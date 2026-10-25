<?php
require_once __DIR__ . '/site.php';

/** Parse flexible discovery clues without confusing sellers/stores with product brands. */
function dd_product_discovery_parse(PDO $pdo, $message)
{
    $text = trim((string) $message);
    if ($text === '' || strlen($text) > 16384) return ['valid' => false, 'error' => 'This product search message is too large. Please shorten it and try again.'];

    // The shared product parser recognizes country names; map the language adjective
    // commonly used in chat input to its country name for marketplace detection.
    $marketText = preg_replace('/(?<!\pL)German(?!\pL)/iu', 'Germany', $text);
    $marketResult = dd_extract_product_input($pdo, 'B000000000 ' . $marketText);
    if (!empty($marketResult['ambiguous'])) return ['valid' => false, 'error' => 'Add one marketplace, such as USA or UK, to this product search.'];
    $detectedMarketplace = trim((string) ($marketResult['marketplace'] ?? ''));
    $marketAliases = array_map('strtolower', [$detectedMarketplace, preg_replace('/^www\./i', '', $detectedMarketplace)]);
    $domainAliases = [
        'amazon.com' => ['USA', 'US', 'United States', 'United States of America'], 'amazon.co.uk' => ['UK', 'United Kingdom', 'Great Britain', 'Britain'],
        'amazon.ca' => ['Canada', 'CA'], 'amazon.de' => ['Germany', 'German', 'DE', 'Deutschland'], 'amazon.fr' => ['France', 'FR'], 'amazon.it' => ['Italy', 'IT'],
        'amazon.es' => ['Spain', 'ES'], 'amazon.co.jp' => ['Japan', 'JP'], 'amazon.in' => ['India', 'IN'], 'amazon.com.au' => ['Australia', 'AU'],
        'amazon.com.mx' => ['Mexico', 'MX'], 'amazon.com.br' => ['Brazil', 'BR'], 'amazon.nl' => ['Netherlands', 'NL'], 'amazon.se' => ['Sweden', 'SE'],
        'amazon.pl' => ['Poland', 'PL'], 'amazon.com.be' => ['Belgium', 'BE'], 'amazon.com.tr' => ['Turkey', 'Türkiye', 'TR'],
        'amazon.ae' => ['United Arab Emirates', 'UAE', 'AE'], 'amazon.sa' => ['Saudi Arabia', 'SA'], 'amazon.eg' => ['Egypt', 'EG'], 'amazon.sg' => ['Singapore', 'SG'],
    ];
    foreach ($domainAliases[preg_replace('/^www\./i', '', $detectedMarketplace)] ?? [] as $alias) $marketAliases[] = strtolower($alias);
    if ($detectedMarketplace !== '') {
        $marketLabel = $pdo->prepare('SELECT country_label FROM marketplace_settings WHERE marketplace = ? LIMIT 1');
        $marketLabel->execute([$detectedMarketplace]);
        $label = trim((string) ($marketLabel->fetchColumn() ?: ''));
        if ($label !== '') $marketAliases[] = strtolower($label);
    }
    $fields = ['keyword' => '', 'seller' => '', 'store' => '', 'brand' => '', 'product_code' => '', 'asin' => '', 'notes' => ''];
    $free = [];
    $hasStructuredClue = false;
    $hasExplicitKeyword = false;
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim((string) $line);
        if ($line === '' || preg_match('/^(?:(?:marketplace|country)(?:\s+name)?|available)(?:\s*[:=\-]\s*|\s+).+$/iu', $line) || in_array(strtolower(dd_product_discovery_normalize($line)), $marketAliases, true)) continue;
        $labelPattern = '/(?<!\pL)(keyword|keywords|product\s+(?:code|id)|product\s+name|product|looking\s+for|sold\s+by|seller(?:\s+name)?|store(?:\s+name)?|brand(?:\s+name)?|asin|amazon\s+(?:url|link)|link|url|marketplace|country|available)(?=\s*[:=\-]|\s+)/iu';
        if (!preg_match_all($labelPattern, $line, $labels, PREG_OFFSET_CAPTURE)) { $free[] = $line; continue; }
        $hasStructuredClue = true;
        $firstOffset = (int) $labels[0][0][1];
        if ($firstOffset > 0) $fields['keyword'] .= ' ' . trim(substr($line, 0, $firstOffset), " \t,;|:-");
        foreach ($labels[0] as $index => $labelMatch) {
            $start = (int) $labelMatch[1] + strlen((string) $labelMatch[0]);
            $end = isset($labels[0][$index + 1]) ? (int) $labels[0][$index + 1][1] : strlen($line);
            $value = trim(substr($line, $start, max(0, $end - $start)), " \t,;|:-");
            $label = strtolower(preg_replace('/\s+/', ' ', trim((string) $labelMatch[0])));
            if ($value === '') continue;
            if (in_array($label, ['keyword', 'keywords', 'product name', 'product', 'looking for'], true)) { $fields['keyword'] .= ' ' . $value; $hasExplicitKeyword = true; }
            elseif (in_array($label, ['sold by', 'seller', 'seller name'], true)) $fields['seller'] .= ' ' . $value;
            elseif (in_array($label, ['store', 'store name'], true)) $fields['store'] .= ' ' . $value;
            elseif (in_array($label, ['brand', 'brand name'], true)) $fields['brand'] .= ' ' . $value;
            elseif (in_array($label, ['product code', 'product id'], true)) $fields['product_code'] .= ' ' . $value;
            elseif ($label === 'asin' && preg_match('/^[A-Z0-9]{10}$/i', $value)) $fields['asin'] = strtoupper($value);
            elseif (in_array($label, ['amazon url', 'amazon link', 'link', 'url'], true)) {
                // URL fields are consumed by the shared product URL path, not search keywords.
            }
        }
    }

    $freeText = implode("\n", $free);
    if (preg_match('/\b(?:sold\s+by|seller)\s*[:=]?\s*(.+?)(?=\s+\b(?:store(?:\s+name)?|keyword|marketplace|country)\s*[:=]|$)/iu', $freeText, $m, PREG_OFFSET_CAPTURE)) {
        $fields['seller'] .= ' ' . $m[1][0];
        $freeText = substr_replace($freeText, ' ', $m[0][1], strlen($m[0][0]));
    }
    if (preg_match('/\bstore(?:\s+name)?\s*[:=]?\s*(.+?)(?=\s+\b(?:keyword|marketplace|country)\s*[:=]|$)/iu', $freeText, $m, PREG_OFFSET_CAPTURE)) {
        $fields['store'] .= ' ' . $m[1][0];
        $freeText = substr_replace($freeText, ' ', $m[0][1], strlen($m[0][0]));
    }
    $freeLines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $freeText)), static function ($line) { return $line !== ''; }));
    if ($freeLines) {
        if (trim($fields['keyword']) === '' && !$hasStructuredClue) {
            $fields['keyword'] = preg_replace('/\b(?:find|search\s+for|looking\s+for)\b/i', ' ', implode(' ', $freeLines));
        } elseif (trim($fields['keyword']) === '' && !$hasExplicitKeyword) {
            $fields['keyword'] = array_shift($freeLines);
        }
        $fields['notes'] = implode("\n", $freeLines);
    }
    foreach ($fields as $key => $value) $fields[$key] = $key === 'notes' ? trim((string) $value) : dd_product_discovery_normalize($value);
    if ($fields['keyword'] === '' && $fields['seller'] === '' && $fields['store'] === '' && $fields['brand'] === '' && $fields['product_code'] === '' && $fields['asin'] === '') return ['valid' => false, 'error' => 'Please send at least one useful product clue.'];

    $fields['marketplace'] = $detectedMarketplace;
    $fields['valid'] = true;
    return $fields;
}

function dd_product_discovery_normalize($value)
{
    $value = trim((string) $value);
    $value = preg_replace('/[\t ]+/u', ' ', $value);
    return trim((string) $value, " \t\n\r\0\x0B,;|.");
}

function dd_product_discovery_has_product_identifier($message)
{
    $text = (string) $message;
    $shortHosts = '(?:a\.co|amzn\.(?:to|eu|asia|in)|link\.amazon)';
    $marketHosts = '(?:amazon\.(?:com|co\.uk|ca|de|fr|it|es|co\.jp|in|com\.au|com\.mx|com\.br|nl|se|pl|com\.be|com\.tr|ae|sa|eg|sg))';
    if (preg_match('~(?:https?://)?(?:www\.)?(?:' . $shortHosts . '|' . $marketHosts . ')(?:[/:?#\s]|$)~i', $text)) return true;
    // A product URL from any site can carry a real ASIN in its path (including
    // Zyofy/Viral Nest share links). Route it to ASIN lookup only when the message
    // also supplies a recognizable marketplace clue; Amazon API lookup still
    // verifies the item in that marketplace before a tracked link is created.
    if (preg_match_all('~https?://[^\s<>"\']+~iu', $text, $externalUrls)) {
        $hasMarketplaceClue = (bool) preg_match('/\b(?:USA|US|United States(?: of America)?|UK|United Kingdom|Great Britain|Canada|Germany|German|France|Italy|Spain|Japan|India|Australia|Mexico|Brazil|Netherlands|Sweden|Poland|Belgium|Turkey|Türkiye|UAE|United Arab Emirates|Saudi Arabia|Singapore|Egypt|DE|CA|IN|AU)\b/iu', $text);
        if ($hasMarketplaceClue) {
            foreach ($externalUrls[0] as $externalUrl) {
                $host = strtolower((string) parse_url($externalUrl, PHP_URL_HOST));
                $path = (string) parse_url($externalUrl, PHP_URL_PATH);
                if ($host !== '' && !preg_match('/(^|\.)amazon\./i', $host)
                    && preg_match('~(?:^|/)([A-Z0-9]{10})(?:/|$)~i', $path, $pathAsin)
                    && preg_match('/\d/', $pathAsin[1])) return true;
            }
        }
    }
    if (preg_match('/(?<![A-Z0-9])ASIN\s*[:=\-]?\s*[A-Z0-9]{10}(?![A-Z0-9])/i', $text)) return true;
    if (preg_match('/\bproduct\s+(?:code|id)\s*[:=]/i', $text)) return false;
    foreach (preg_split('/\R/u', $text) as $line) {
        if (preg_match('/^\s*[A-Z0-9]{10}(?:\s+(?:(?:marketplace|country)\s*[:=]?\s*)?(?:USA|US|United States(?: of America)?|UK|United Kingdom|Canada|Germany|German|France|India|Australia|DE|CA|IN|AU))?\s*$/iu', (string) $line)
            && preg_match('/\d/', (string) $line)) return true;
    }
    return false;
}

function dd_product_discovery_match_level($clue, $actual)
{
    $fold = static function ($value) {
        $value = function_exists('mb_strtolower') ? mb_strtolower((string) $value, 'UTF-8') : strtolower((string) $value);
        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $value));
    };
    $clue = $fold(dd_product_discovery_normalize($clue));
    $actual = $fold(dd_product_discovery_normalize($actual));
    if ($clue === '' || $actual === '') return 'unavailable';
    if ($clue === $actual) return 'exact';
    if (strpos($actual, $clue) !== false || strpos($clue, $actual) !== false) return 'strong';
    $clueTokens = array_values(array_unique(array_filter(explode(' ', $clue))));
    $actualTokens = array_fill_keys(array_filter(explode(' ', $actual)), true);
    if ($clueTokens) {
        $matched = count(array_filter($clueTokens, static function ($token) use ($actualTokens) { return isset($actualTokens[$token]); }));
        if ($matched / count($clueTokens) >= 0.75) return 'strong';
    }
    return 'none';
}

/** Rank candidates using title relevance and only metadata returned by a trusted source. */
function dd_product_discovery_score(array $candidate, array $criteria)
{
    $keyword = dd_product_discovery_normalize($criteria['keyword'] ?? '');
    $fold = static function ($value) { return function_exists('mb_strtolower') ? mb_strtolower((string) $value, 'UTF-8') : strtolower((string) $value); };
    $keywordFolded = $fold($keyword);
    $title = $fold($candidate['title'] ?? '');
    $description = $fold($candidate['description'] ?? '');
    $score = 0;
    $matchedTokens = 0;
    $tokens = array_values(array_unique(array_filter(preg_split('/[^\pL\pN]+/u', $keyword), static function ($token) { return function_exists('mb_strlen') ? mb_strlen((string) $token, 'UTF-8') >= 2 : strlen((string) $token) >= 2; })));
    if ($keywordFolded === '') $score = 1;
    else {
        if (strpos($title, $keywordFolded) !== false) $score += 12;
        elseif (strpos($description, $keywordFolded) !== false) $score += 7;
        foreach ($tokens as $token) {
            $token = $fold($token);
            if (strpos($title, $token) !== false) { $score += 3; $matchedTokens++; }
            elseif (strpos($description, $token) !== false) { $score += 1; $matchedTokens++; }
        }
    }
    $brandClue = trim((string) ($criteria['brand'] ?? ''));
    $brandMatch = dd_product_discovery_match_level($brandClue, $candidate['brand'] ?? '');
    if ($brandClue !== '') $score += $brandMatch === 'exact' ? 35 : ($brandMatch === 'strong' ? 24 : ($brandMatch === 'none' ? -10 : 0));
    $sellerClue = trim((string) ($criteria['seller'] ?? ''));
    $sellerMatch = dd_product_discovery_match_level($sellerClue, !empty($candidate['seller_verified']) ? ($candidate['seller'] ?? '') : '');
    if ($sellerClue !== '') $score += $sellerMatch === 'exact' ? 45 : ($sellerMatch === 'strong' ? 30 : ($sellerMatch === 'none' ? -20 : -4));
    $storeClue = trim((string) ($criteria['store'] ?? ''));
    $storeMatch = dd_product_discovery_match_level($storeClue, !empty($candidate['store_verified']) ? ($candidate['store'] ?? '') : '');
    if ($storeClue !== '') $score += $storeMatch === 'exact' ? 30 : ($storeMatch === 'strong' ? 20 : ($storeMatch === 'none' ? -10 : -2));
    if ($keywordFolded === '') return max(1, $score);
    $minimumMatches = count($tokens) < 2 ? 1 : (int) ceil(count($tokens) * 0.6);
    $strongMetadataMatch = $brandMatch === 'exact' || $brandMatch === 'strong' || $sellerMatch === 'exact' || $sellerMatch === 'strong';
    if ($matchedTokens < $minimumMatches && !$strongMetadataMatch) return 0;
    // Keep a title-relevant candidate visible even when verified seller evidence
    // contradicts the requested clue; ranking and the card explain the mismatch.
    return max(1, $score);
}

function dd_product_discovery_candidate_explanation(array $candidate, array $criteria)
{
    $parts = [];
    $titleScore = dd_product_discovery_score($candidate, array_merge($criteria, ['brand' => '', 'seller' => '', 'store' => '']));
    if (trim((string) ($criteria['keyword'] ?? '')) !== '') $parts[] = $titleScore > 0 ? 'Title matched' : 'Title weak';
    if (trim((string) ($criteria['brand'] ?? '')) !== '') {
        $match = dd_product_discovery_match_level($criteria['brand'], $candidate['brand'] ?? '');
        $parts[] = $match === 'unavailable' ? 'Brand not available' : ($match === 'none' ? 'Brand differs' : 'Brand matched');
    }
    if (trim((string) ($criteria['seller'] ?? '')) !== '') {
        $match = !empty($candidate['seller_verified']) ? dd_product_discovery_match_level($criteria['seller'], $candidate['seller'] ?? '') : 'unavailable';
        $parts[] = $match === 'unavailable' ? 'Seller not verified' : ($match === 'none' ? 'Seller clue not matched' : 'Seller matched');
    }
    if (trim((string) ($criteria['store'] ?? '')) !== '') {
        $match = !empty($candidate['store_verified']) ? dd_product_discovery_match_level($criteria['store'], $candidate['store'] ?? '') : 'unavailable';
        $parts[] = $match === 'unavailable' ? 'Store not verified' : ($match === 'none' ? 'Store clue not matched' : 'Store matched');
    }
    $parts[] = 'Marketplace matched';
    return implode('; ', $parts);
}

function dd_product_discovery_candidate(array $row, $source, array $criteria)
{
    $asin = strtoupper(trim((string) ($row['asin'] ?? '')));
    if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) return null;
    $image = trim((string) ($row['image'] ?? ''));
    $parts = parse_url($image);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $extension = strtolower(pathinfo((string) ($parts['path'] ?? ''), PATHINFO_EXTENSION));
    $validImage = is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
        && empty($parts['user']) && empty($parts['pass']) && (!isset($parts['port']) || (int) $parts['port'] === 443)
        && in_array($extension, ['jpg', 'jpeg', 'png'], true)
        && ($host === 'm.media-amazon.com' || $host === 'images-amazon.com' || $host === 'images.amazon.com' || (bool) preg_match('/(^|\.)ssl-images-amazon\.com$/', $host));
    $title = trim((string) ($row['title'] ?? ''));
    if ($title === '' || strcasecmp($title, 'Amazon product ' . $asin) === 0) $title = 'Title unavailable';
    $seller = trim((string) ($row['seller'] ?? ''));
    $sellerVerified = $seller !== '' && !empty($row['seller_verified']);
    $store = trim((string) ($row['store'] ?? ''));
    $storeVerified = $store !== '' && !empty($row['store_verified']);
    $candidate = [
        'asin' => $asin,
        'title' => $title,
        'image' => $validImage ? $image : '',
        'brand' => trim((string) ($row['brand'] ?? '')),
        'manufacturer' => trim((string) ($row['manufacturer'] ?? '')),
        'product_code' => trim((string) ($row['product_code'] ?? '')),
        'model' => trim((string) ($row['model'] ?? '')),
        'seller' => $sellerVerified ? $seller : '',
        'seller_verified' => $sellerVerified,
        'store' => $storeVerified ? $store : '',
        'store_verified' => $storeVerified,
        'marketplace' => trim((string) ($row['marketplace'] ?? '')),
        'source' => $source,
        'seller_store_verified' => $sellerVerified || $storeVerified,
    ];
    $candidate['brand_match'] = trim((string) ($criteria['brand'] ?? '')) !== '' ? dd_product_discovery_match_level($criteria['brand'], $candidate['brand']) : null;
    $candidate['seller_match'] = trim((string) ($criteria['seller'] ?? '')) !== '' ? ($sellerVerified ? dd_product_discovery_match_level($criteria['seller'], $seller) : 'unavailable') : null;
    $candidate['store_match'] = trim((string) ($criteria['store'] ?? '')) !== '' ? ($storeVerified ? dd_product_discovery_match_level($criteria['store'], $store) : 'unavailable') : null;
    $candidate['marketplace_match'] = true;
    $candidate['match_explanation'] = dd_product_discovery_candidate_explanation($candidate, $criteria);
    $candidate['seller_store_status'] = ($sellerVerified ? 'Sold by: ' . $seller : 'Seller: Not verified') . ' · ' . ($storeVerified ? 'Store: ' . $store : 'Store: Not verified');
    return $candidate;
}

function dd_product_discovery_candidate_caption(array $candidate, $number)
{
    $lines = [
        (int) $number . '. ' . (string) ($candidate['title'] ?? 'Title unavailable'),
        'Brand: ' . (trim((string) ($candidate['brand'] ?? '')) ?: 'Not available'),
    ];
    if (trim((string) ($candidate['manufacturer'] ?? '')) !== '') $lines[] = 'Manufacturer: ' . $candidate['manufacturer'];
    $lines[] = 'Sold by: ' . (!empty($candidate['seller_verified']) && trim((string) ($candidate['seller'] ?? '')) !== '' ? $candidate['seller'] : 'Not verified');
    $lines[] = 'Store: ' . (!empty($candidate['store_verified']) && trim((string) ($candidate['store'] ?? '')) !== '' ? $candidate['store'] : 'Not verified');
    $lines[] = 'ASIN: ' . (string) ($candidate['asin'] ?? '');
    $lines[] = 'Marketplace: ' . (string) ($candidate['marketplace'] ?? '');
    $lines[] = 'Match: ' . (string) ($candidate['match_explanation'] ?? 'Marketplace matched');
    return implode("\n", $lines);
}

/** Fill missing requested brand/seller evidence with a bounded GetItems lookup. */
function dd_product_discovery_enrich_candidates(PDO $pdo, array $candidates, array $criteria, $lookup)
{
    if (!trim((string) ($criteria['brand'] ?? '')) && !trim((string) ($criteria['seller'] ?? ''))) return $candidates;
    $lookups = 0;
    foreach ($candidates as $index => $candidate) {
        if ($lookups >= 3) break;
        $needsBrand = trim((string) ($criteria['brand'] ?? '')) !== '' && trim((string) ($candidate['brand'] ?? '')) === '';
        $needsSeller = trim((string) ($criteria['seller'] ?? '')) !== '' && empty($candidate['seller_verified']);
        if (!$needsBrand && !$needsSeller) continue;
        $lookups++;
        try {
            $details = $lookup($pdo, (string) $candidate['asin'], (string) $candidate['marketplace']);
            if (!is_array($details) || strtoupper((string) ($details['asin'] ?? '')) !== strtoupper((string) $candidate['asin'])) continue;
            $merged = array_merge($candidate, $details);
            $candidates[$index] = dd_product_discovery_candidate($merged, (string) $candidate['source'], $criteria);
            $candidates[$index]['_score'] = dd_product_discovery_score($candidates[$index], $criteria);
        } catch (Throwable $error) {
            // Missing optional metadata must not make product discovery fail.
        }
    }
    return $candidates;
}

/** Database first, using only columns that exist in the inspected products schema. */
function dd_product_discovery_search(PDO $pdo, array $criteria, $marketplace, $apiSearch = null, $apiItemLookup = null)
{
    require_once __DIR__ . '/amazon-products.php';
    $marketplace = trim((string) $marketplace);
    if ($marketplace === '') throw new InvalidArgumentException('Choose an Amazon marketplace for this search.');
    $active = $pdo->prepare('SELECT marketplace FROM marketplace_settings WHERE marketplace = ? AND is_active = 1 LIMIT 1');
    $active->execute([$marketplace]);
    if (!$active->fetchColumn()) throw new InvalidArgumentException('Choose an active Amazon marketplace.');

    $criteria['keyword'] = dd_product_discovery_normalize($criteria['keyword'] ?? '');
    $criteria['seller'] = dd_product_discovery_normalize($criteria['seller'] ?? '');
    $criteria['store'] = dd_product_discovery_normalize($criteria['store'] ?? '');
    $criteria['brand'] = dd_product_discovery_normalize($criteria['brand'] ?? '');
    $criteria['product_code'] = dd_product_discovery_normalize($criteria['product_code'] ?? '');
    $itemLookup = is_callable($apiItemLookup) ? $apiItemLookup : (is_callable($apiSearch)
        ? static function () { return null; }
        : static function ($database, $asin, $market) { return dd_creator_get_product($database, $asin, $market); });
    $dbCandidates = [];
    if ($criteria['keyword'] !== '') {
        $tokens = array_slice(array_values(array_unique(array_filter(preg_split('/[^\pL\pN]+/u', $criteria['keyword']), static function ($token) { return strlen((string) $token) >= 2; }))), 0, 5);
        $where = ['title LIKE ?', 'description LIKE ?'];
        $literal = static function ($value) { return addcslashes((string) $value, '\\%_'); };
        $params = [$marketplace, '%' . $literal($criteria['keyword']) . '%', '%' . $literal($criteria['keyword']) . '%'];
        foreach ($tokens as $token) { $where[] = 'title LIKE ?'; $params[] = '%' . $literal($token) . '%'; }
        $statement = $pdo->prepare('SELECT asin, title, image, description, marketplace FROM products WHERE marketplace = ? AND (' . implode(' OR ', $where) . ') ORDER BY id DESC LIMIT 60');
        $statement->execute($params);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $candidate = dd_product_discovery_candidate($row, 'zyofy_catalog', $criteria);
            if ($candidate === null) continue;
            $candidate['_score'] = dd_product_discovery_score($row, $criteria);
            if ($candidate['_score'] > 0) $dbCandidates[] = $candidate;
        }
        usort($dbCandidates, static function ($a, $b) { return ($b['_score'] <=> $a['_score']) ?: strcmp($a['asin'], $b['asin']); });
        $dbCandidates = array_slice($dbCandidates, 0, 8);
    }
    if ($dbCandidates) {
        $dbCandidates = dd_product_discovery_enrich_candidates($pdo, $dbCandidates, $criteria, $itemLookup);
        $dbCandidates = array_values(array_filter($dbCandidates, static function ($candidate) { return !empty($candidate) && ($candidate['_score'] ?? 0) > 0; }));
        usort($dbCandidates, static function ($a, $b) { return ($b['_score'] <=> $a['_score']) ?: strcmp($a['asin'], $b['asin']); });
        $dbCandidates = array_slice($dbCandidates, 0, 8);
    }
    if ($dbCandidates) {
        foreach ($dbCandidates as &$candidate) unset($candidate['_score']);
        unset($candidate);
        return ['source' => 'zyofy_catalog', 'candidates' => $dbCandidates, 'seller_match_found' => count(array_filter($dbCandidates, static function ($candidate) { return ($candidate['seller_match'] ?? '') === 'exact' || ($candidate['seller_match'] ?? '') === 'strong'; })) > 0];
    }

    $query = implode(' ', array_filter([$criteria['keyword'], $criteria['seller'], $criteria['store'], $criteria['product_code'] ?? '']));
    if ($query === '' && $criteria['brand'] !== '') $query = $criteria['brand'];
    if ($query === '') throw new InvalidArgumentException('Add a keyword, seller, or store name to search.');
    $search = is_callable($apiSearch) ? $apiSearch : static function ($database, $keywords, $market, $brand) { return dd_creator_search_products($database, $keywords, $market, 1, $brand, false); };
    // SearchItemsRequestContent accepts up to 1000 characters for keywords.
    $query = function_exists('mb_substr') ? mb_substr($query, 0, 1000, 'UTF-8') : substr($query, 0, 1000);
    $products = is_callable($apiSearch) ? $search($pdo, $query, $marketplace) : $search($pdo, $query, $marketplace, $criteria['brand']);
    $apiCandidates = [];
    foreach ($products as $row) {
        $candidate = dd_product_discovery_candidate($row, 'amazon_creators_api', $criteria);
        if ($candidate === null) continue;
        $candidate['_score'] = dd_product_discovery_score($row, $criteria);
        if ($candidate['_score'] > 0) $apiCandidates[] = $candidate;
    }
    $apiCandidates = dd_product_discovery_enrich_candidates($pdo, $apiCandidates, $criteria, $itemLookup);
    $apiCandidates = array_values(array_filter($apiCandidates, static function ($candidate) { return !empty($candidate) && ($candidate['_score'] ?? 0) > 0; }));
    usort($apiCandidates, static function ($a, $b) { return ($b['_score'] <=> $a['_score']) ?: strcmp($a['asin'], $b['asin']); });
    $apiCandidates = array_slice($apiCandidates, 0, 8);
    foreach ($apiCandidates as &$candidate) unset($candidate['_score']);
    unset($candidate);
    return ['source' => 'amazon_creators_api', 'candidates' => $apiCandidates, 'seller_match_found' => count(array_filter($apiCandidates, static function ($candidate) { return ($candidate['seller_match'] ?? '') === 'exact' || ($candidate['seller_match'] ?? '') === 'strong'; })) > 0];
}

function dd_product_discovery_scope_hash($channel, $userId, $scope)
{
    return hash('sha256', strtolower((string) $channel) . "\0" . (int) $userId . "\0" . (string) $scope);
}

function dd_product_discovery_has_active_tag(PDO $pdo, $userEmail, $marketplace, $tagId = null)
{
    $sql = 'SELECT ut.id FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND LOWER(TRIM(ut.marketplace)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\'';
    $params = [trim((string) $userEmail), trim((string) $marketplace)];
    if ($tagId) { $sql .= ' AND ut.id = ?'; $params[] = (int) $tagId; }
    $sql .= ' LIMIT 1';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (bool) $statement->fetchColumn();
}

function dd_product_discovery_store_selection(PDO $pdo, $channel, $userId, $scopeHash, array $candidates)
{
    if (!$candidates) throw new InvalidArgumentException('No matching products were found.');
    $selectionCandidates = [];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) continue;
        $asin = strtoupper(trim((string) ($candidate['asin'] ?? '')));
        $marketplace = trim((string) ($candidate['marketplace'] ?? ''));
        if (!preg_match('/^[A-Z0-9]{10}$/', $asin) || $marketplace === '') continue;
        // The pending record only needs enough data to resolve a later choice;
        // display metadata stays in the immediate search response.
        $selectionCandidates[] = ['asin' => $asin, 'marketplace' => $marketplace];
        if (count($selectionCandidates) >= 8) break;
    }
    if (!$selectionCandidates) throw new InvalidArgumentException('No valid products are available to select.');
    $pdo->exec('DELETE FROM product_discovery_selections WHERE expires_at < UTC_TIMESTAMP() LIMIT 100');
    $token = bin2hex(random_bytes(32));
    $json = json_encode($selectionCandidates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) throw new RuntimeException('Could not save the product choices.');
    $statement = $pdo->prepare('INSERT INTO product_discovery_selections (selection_token, channel, user_id, scope_hash, candidates_json, expires_at, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL 15 MINUTE, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE selection_token = VALUES(selection_token), candidates_json = VALUES(candidates_json), expires_at = VALUES(expires_at), created_at = VALUES(created_at)');
    $statement->execute([$token, (string) $channel, (int) $userId, (string) $scopeHash, $json]);
    return $token;
}

function dd_product_discovery_pending(PDO $pdo, $channel, $userId, $scopeHash, $token = '')
{
    $sql = 'SELECT selection_token, candidates_json, expires_at FROM product_discovery_selections WHERE channel = ? AND user_id = ? AND scope_hash = ? AND expires_at > UTC_TIMESTAMP()';
    $params = [(string) $channel, (int) $userId, (string) $scopeHash];
    if ((string) $token !== '') { $sql .= ' AND selection_token = ?'; $params[] = (string) $token; }
    $sql .= ' ORDER BY created_at DESC LIMIT 1';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $candidates = json_decode((string) $row['candidates_json'], true);
    if (!is_array($candidates)) return null;
    return ['token' => (string) $row['selection_token'], 'candidates' => $candidates];
}

function dd_product_discovery_selected_candidate($pending, $number)
{
    $number = filter_var($number, FILTER_VALIDATE_INT);
    if (!is_array($pending) || !$number || $number < 1 || $number > 8) return null;
    $candidate = $pending['candidates'][$number - 1] ?? null;
    if (!is_array($candidate) || !preg_match('/^[A-Z0-9]{10}$/', strtoupper((string) ($candidate['asin'] ?? ''))) || trim((string) ($candidate['marketplace'] ?? '')) === '') return null;
    $candidate['asin'] = strtoupper((string) $candidate['asin']);
    return $candidate;
}

function dd_product_discovery_delete_selection(PDO $pdo, $token)
{
    $statement = $pdo->prepare('DELETE FROM product_discovery_selections WHERE selection_token = ?');
    $statement->execute([(string) $token]);
}
