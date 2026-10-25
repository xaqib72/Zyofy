<?php
/**
 * Countries, currencies, and working out which one a report file came from.
 *
 * The country list itself lives in the marketplace_settings table — this file only
 * holds the logic that reads it and the language fingerprints used for detection.
 */

/** All countries, keyed by marketplace. */
function dd_all_countries($pdo)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = array();
    foreach ($pdo->query(
        'SELECT marketplace, country_label, currency, share_percent, is_active
         FROM marketplace_settings ORDER BY country_label'
    ) as $row) {
        $cache[$row['marketplace']] = $row;
    }

    return $cache;
}

function dd_active_countries($pdo)
{
    $active = array();
    foreach (dd_all_countries($pdo) as $marketplace => $country) {
        if ((int) $country['is_active'] === 1) {
            $active[$marketplace] = $country;
        }
    }

    return $active;
}

function dd_country($pdo, $marketplace)
{
    $all = dd_all_countries($pdo);
    return isset($all[$marketplace]) ? $all[$marketplace] : null;
}

/** The rate currently offered as a default for a currency. */
function dd_default_rate($pdo, $currency)
{
    $statement = $pdo->prepare('SELECT rate_to_usd, is_confirmed FROM exchange_rates WHERE currency = :c');
    $statement->execute(array(':c' => $currency));
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row ? $row : array('rate_to_usd' => null, 'is_confirmed' => 0);
}

/**
 * Language fingerprints.
 *
 * Each entry is a set of normalised header words that only that language writes.
 * `certain` says whether the language identifies exactly one Amazon store: German
 * and Italian do, but English is shared by .com/.co.uk/.ca/.com.au/.in, Spanish by
 * .es/.com.mx, and French by .fr/.ca — so those can only ever be a suggestion.
 */
function dd_language_fingerprints()
{
    return array(
        'German' => array(
            'words'   => array('klicks', 'bestellte artikel', 'versendete artikel', 'gesamteinnahmen', 'zuruckgegebene artikel'),
            'primary' => 'www.amazon.de',
            'others'  => array(),
            'certain' => true,
        ),
        'Italian' => array(
            'words'   => array('articoli ordinati', 'articoli spediti', 'guadagni totali', 'articoli restituiti'),
            'primary' => 'www.amazon.it',
            'others'  => array(),
            'certain' => true,
        ),
        'Spanish' => array(
            'words'   => array('clics', 'productos pedidos', 'articulos pedidos', 'ganancias totales', 'productos enviados'),
            'primary' => 'www.amazon.es',
            'others'  => array('www.amazon.com.mx'),
            'certain' => false,
        ),
        'French' => array(
            'words'   => array('articles commandes', 'articles expedies', 'revenus totaux', 'articles retournes'),
            'primary' => 'www.amazon.fr',
            'others'  => array('www.amazon.ca'),
            'certain' => false,
        ),
        'English' => array(
            'words'   => array('clicks', 'items ordered', 'items shipped', 'total earnings', 'items returned'),
            'primary' => 'www.amazon.com',
            'others'  => array('www.amazon.co.uk', 'www.amazon.ca', 'www.amazon.com.au', 'www.amazon.in', 'www.amazon.sg', 'www.amazon.ae'),
            'certain' => false,
        ),
    );
}

/**
 * Looks at the header row and says which language — and therefore which country —
 * the file most likely came from.
 *
 * Returns: language, marketplace, certain, matched (how many words hit), and a
 * plain-English reason the screen can show the admin.
 */
function dd_detect_from_header($normalizedHeader)
{
    $best = null;

    foreach (dd_language_fingerprints() as $language => $fingerprint) {
        $matched = 0;
        foreach ($fingerprint['words'] as $word) {
            if (in_array($word, $normalizedHeader, true)) {
                $matched++;
            }
        }

        if ($matched === 0) {
            continue;
        }

        // A language that names exactly one store wins ties: "klicks" is decisive in
        // a way that "clicks" never is.
        $score = $matched * 10 + ($fingerprint['certain'] ? 5 : 0);
        if ($best === null || $score > $best['score']) {
            $best = array(
                'language'    => $language,
                'marketplace' => $fingerprint['primary'],
                'certain'     => $fingerprint['certain'],
                'others'      => $fingerprint['others'],
                'matched'     => $matched,
                'score'       => $score,
            );
        }
    }

    if ($best === null) {
        return array(
            'language'    => null,
            'marketplace' => null,
            'certain'     => false,
            'others'      => array(),
            'matched'     => 0,
            'reason'      => 'The column headings did not match any Amazon report language, so the country cannot be worked out from the file. Choose it yourself below.',
        );
    }

    unset($best['score']);
    return $best;
}

/**
 * Reads the country out of the file's NAME.
 *
 * This matters because Amazon writes the Canadian, British, Australian and Indian
 * reports in English, exactly like the US one — so the column headings alone will
 * always guess "United States" for all of them. People name these files sensibly
 * ("Canada July 1to31.csv", "USA july 1 to31.csv"), and that name is a far better
 * clue than the language.
 *
 * Only whole words count. "de", "fr", "es" and "it" are deliberately absent: a French
 * file called "rapport de juillet.csv" would otherwise be filed under Germany. "us"
 * and "uk" are kept because they are not words in any language Amazon exports in, and
 * "US July.csv" is a name people really use.
 *
 * @return array|null  marketplace + the word that matched
 */
function dd_detect_from_filename($pdo, $filename)
{
    // Punctuation becomes spaces so words can be matched with their boundaries.
    $haystack = ' ' . strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $filename)) . ' ';
    $haystack = preg_replace('/\s+/', ' ', $haystack);

    $aliases = array(
        'www.amazon.com'    => array('usa', 'us', 'united states', 'america', 'american', 'amazon com'),
        'www.amazon.ca'     => array('canada', 'canadian', 'amazon ca'),
        'www.amazon.co.uk'  => array('uk', 'united kingdom', 'britain', 'british', 'england', 'amazon co uk'),
        'www.amazon.de'     => array('germany', 'german', 'deutschland', 'amazon de'),
        'www.amazon.fr'     => array('france', 'french', 'amazon fr'),
        'www.amazon.es'     => array('spain', 'spanish', 'espana', 'amazon es'),
        'www.amazon.it'     => array('italy', 'italian', 'italia', 'amazon it'),
        'www.amazon.nl'     => array('netherlands', 'holland', 'dutch', 'amazon nl'),
        'www.amazon.com.mx' => array('mexico', 'mexican', 'amazon com mx'),
        'www.amazon.com.au' => array('australia', 'australian', 'amazon com au'),
        'www.amazon.in'     => array('india', 'indian', 'amazon in'),
        'www.amazon.co.jp'  => array('japan', 'japanese', 'amazon co jp'),
        'www.amazon.com.br' => array('brazil', 'brasil', 'amazon com br'),
        'www.amazon.se'     => array('sweden', 'swedish', 'amazon se'),
        'www.amazon.pl'     => array('poland', 'polish', 'amazon pl'),
        'www.amazon.ae'     => array('uae', 'emirates', 'amazon ae'),
        'www.amazon.sa'     => array('saudi', 'amazon sa'),
        'www.amazon.sg'     => array('singapore', 'amazon sg'),
    );

    /* Only countries that are switched on can be suggested. Guessing a country that
       is not in the dropdown would leave the screen showing one thing and the form
       holding another, and the import would refuse it anyway. */
    $active = dd_active_countries($pdo);

    // A country's own label counts too, so renaming one in the settings makes its
    // files recognisable without touching the list above.
    foreach ($active as $marketplace => $country) {
        $label = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $country['country_label'])));
        if ($label !== '' && strlen($label) > 2) {
            if (!isset($aliases[$marketplace])) {
                $aliases[$marketplace] = array();
            }
            if (!in_array($label, $aliases[$marketplace], true)) {
                $aliases[$marketplace][] = $label;
            }
        }
    }

    // Longest match wins, so "united states" beats a stray "us".
    $best = null;
    foreach ($aliases as $marketplace => $words) {
        if (!isset($active[$marketplace])) {
            continue;
        }
        foreach ($words as $word) {
            if (strpos($haystack, ' ' . $word . ' ') === false) {
                continue;
            }
            if ($best === null || strlen($word) > strlen($best['matched'])) {
                $best = array('marketplace' => $marketplace, 'matched' => $word);
            }
        }
    }

    return $best;
}

/**
 * The sentence shown under the file chooser. Kept here so the analyse endpoint and
 * any later screen word it identically.
 */
function dd_detection_reason($detection, $countryLabel, $currency)
{
    $fromName = isset($detection['filename_word']) ? $detection['filename_word'] : null;

    if ($detection['language'] === null && $fromName === null) {
        return $detection['reason'];
    }

    // The two signals disagree and the language one is decisive — say so loudly.
    if (!empty($detection['conflict'])) {
        return 'The column headings are ' . $detection['language'] . ', which only ' . $countryLabel
             . ' writes, but the file is named after ' . $detection['conflict']
             . '. The headings win, because a file can be renamed and its contents cannot.'
             . ' Check the country before importing.';
    }

    if ($detection['certain']) {
        $because = 'The column headings in this export are ' . $detection['language']
                 . ', which only ' . $countryLabel . ' writes';
        if ($fromName !== null) {
            $because .= ', and the file name says so too';
        }
        return 'Read from the file: ' . $countryLabel . ', in ' . $currency . '. ' . $because
             . ' — so the country and currency are filled in for you.';
    }

    if ($fromName !== null) {
        return 'Taken from the file name, which contains "' . $fromName . '": ' . $countryLabel . ', in ' . $currency . '. '
             . 'The column headings are ' . ($detection['language'] ?: 'unrecognised')
             . ', which several Amazon stores share, so the name is the better clue here. Change it below if it is wrong.';
    }

    return 'The column headings are ' . $detection['language'] . ', which more than one Amazon store uses, so '
         . $countryLabel . ' is only a suggestion — and the file name does not say which country it is. '
         . 'Check it before importing; the currency follows whatever you pick.';
}

/**
 * Puts the two clues together.
 *
 * German and Italian name exactly one store, so those headings decide. English,
 * Spanish and French are shared, and there the file name is the stronger signal.
 */
function dd_detect_country($pdo, $normalizedHeader, $filename)
{
    $detection = dd_detect_from_header($normalizedHeader);
    $byName = dd_detect_from_filename($pdo, $filename);

    $detection['filename_word'] = null;
    $detection['conflict'] = null;

    if ($byName === null) {
        return $detection;
    }

    $detection['filename_word'] = $byName['matched'];

    if ($detection['certain']) {
        if ($detection['marketplace'] !== $byName['marketplace']) {
            // Headings are decisive, so they stay — but this needs pointing out.
            $named = dd_country($pdo, $byName['marketplace']);
            $detection['conflict'] = $named ? $named['country_label'] : $byName['marketplace'];
        }
        return $detection;
    }

    // Ambiguous language: trust the name.
    $detection['marketplace'] = $byName['marketplace'];
    return $detection;
}
