<?php
require_once __DIR__ . '/site.php';

/** Parse distinct Amazon product inputs from pasted text, keeping each product's nearby context. */
function dd_extract_product_inputs(PDO $pdo, $message)
{
    $message = trim((string) $message);
    if ($message === '' || strlen($message) > 16384) {
        throw new InvalidArgumentException('Please send one or more Amazon product URLs or ASINs with their marketplaces.');
    }

    $candidates = [];
    $urlRanges = [];
    $marketRows = $pdo->query('SELECT marketplace FROM marketplace_settings WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
    $allowedHosts = ['a.co', 'amzn.to', 'amzn.eu', 'amzn.asia', 'amzn.in', 'link.amazon'];
    foreach ($marketRows as $marketRow) {
        $marketHost = strtolower(rtrim(preg_replace('/^www\./i', '', (string) ($marketRow['marketplace'] ?? '')), '.'));
        if (preg_match('/^amazon\.[a-z0-9.-]+$/', $marketHost)) $allowedHosts[] = $marketHost;
    }
    $allowedHosts = array_values(array_unique($allowedHosts));
    $urlPattern = '~https?://[^\s<>"\']+|(?<![A-Z0-9@])(?:www\.)?(?:a\.co|amzn\.(?:to|eu|asia|in)|link\.amazon|amazon\.[a-z0-9.-]+)(?:/[^\s<>"\']*)?~i';
    if (preg_match_all($urlPattern, $message, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $rawUrl = rtrim((string) $match[0], '.,);]');
            $offset = (int) $match[1];
            $candidateUrl = preg_match('~^https?://~i', $rawUrl) ? $rawUrl : 'https://' . $rawUrl;
            $end = $offset + strlen($rawUrl);
            $urlRanges[] = [$offset, $end];
            $host = strtolower((string) parse_url($candidateUrl, PHP_URL_HOST));
            $canonicalHost = preg_replace('/^www\./i', '', $host);
            if (!in_array($canonicalHost, $allowedHosts, true)) {
                // A forwarded product post may link to a non-Amazon retailer/site
                // while embedding the Amazon ASIN in its URL. Treat that URL only
                // as a source for the identifier; the normal Amazon API lookup
                // below must still verify the ASIN for the selected marketplace.
                // Do not treat malformed Amazon-lookalike domains as arbitrary
                // third-party clues, and only accept identifiers from the path
                // (not arbitrary query parameters such as ?asin=...).
                $externalPath = (string) parse_url($candidateUrl, PHP_URL_PATH);
                if (strpos($canonicalHost, 'amazon.') === false
                    && preg_match('/(?<![A-Z0-9])([A-Z0-9]{10})(?![A-Z0-9])/i', $externalPath, $externalAsin, PREG_OFFSET_CAPTURE)
                    && preg_match('/\d/', $externalAsin[1][0])) {
                    $pathOffset = strpos($candidateUrl, $externalPath);
                    $schemePrefixLength = preg_match('~^https?://~i', $rawUrl) ? 0 : 8;
                    $asinOffset = $offset + max(0, ($pathOffset === false ? 0 : $pathOffset) - $schemePrefixLength) + (int) $externalAsin[1][1];
                    $candidates[] = ['type' => 'asin', 'raw' => strtoupper((string) $externalAsin[1][0]), 'offset' => $asinOffset, 'end' => $asinOffset + 10];
                }
                continue;
            }
            $candidates[] = ['type' => 'url', 'raw' => $candidateUrl, 'offset' => $offset, 'end' => $end];
        }
    }

    if (preg_match_all('/(?<![A-Z0-9])[A-Z0-9]{10}(?![A-Z0-9])/i', $message, $asins, PREG_OFFSET_CAPTURE)) {
        foreach ($asins[0] as $match) {
            $offset = (int) $match[1];
            $insideUrl = false;
            foreach ($urlRanges as $range) {
                if ($offset >= $range[0] && $offset < $range[1]) { $insideUrl = true; break; }
            }
            // Long ordinary words can also be ten letters (for example "nightlight").
            // Amazon ASINs include at least one digit, so ignore all-letter prose.
            $lineStart = strrpos(substr($message, 0, $offset), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd = strpos($message, "\n", $offset);
            if ($lineEnd === false) $lineEnd = strlen($message);
            $linePrefix = substr($message, $lineStart, $offset - $lineStart);
            $lineSuffix = substr($message, $offset + 10, $lineEnd - ($offset + 10));
            $explicitAsin = (bool) preg_match('/\bASIN\s*[:=\-]?\s*$/i', $linePrefix);
            $bareAsinLine = trim($linePrefix, " \t,;|:-") === ''
                && (trim($lineSuffix, " \t,;|:-") === '' || (bool) preg_match('/^(?:(?:marketplace|country)\s*[:=]?\s*)?(?:USA|US|United States(?: of America)?|UK|United Kingdom|Great Britain|Canada|Germany|German|France|India|Australia|DE|CA|IN|AU)?$/iu', trim($lineSuffix, " \t,;|:-")));
            $productCodeLine = (bool) preg_match('/\bproduct\s+(?:code|id)\s*[:=]/i', substr($message, $lineStart, $lineEnd - $lineStart));
            if (!$insideUrl && !$productCodeLine && preg_match('/\d/', (string) $match[0]) && ($explicitAsin || $bareAsinLine)) {
                $candidates[] = ['type' => 'asin', 'raw' => strtoupper((string) $match[0]), 'offset' => $offset, 'end' => $offset + 10];
            }
        }
    }
    usort($candidates, static function ($left, $right) { return $left['offset'] <=> $right['offset']; });
    // A clearly-labelled ASIN in the same paragraph is independent evidence for
    // a neighboring URL; do not create two posts from one forwarded product block.
    $groupedCandidates = [];
    foreach ($candidates as $candidate) {
        if ($candidate['type'] === 'asin') {
            $lineStart = strrpos(substr($message, 0, (int) $candidate['offset']), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd = strpos($message, "\n", (int) $candidate['end']);
            if ($lineEnd === false) $lineEnd = strlen($message);
            $nearbyAsinLabel = preg_match('/\bASIN\s*[:=\-]?\s*$/i', substr($message, $lineStart, (int) $candidate['offset'] - $lineStart));
            if ($nearbyAsinLabel) {
                foreach ($candidates as $urlCandidate) {
                    if ($urlCandidate['type'] !== 'url') continue;
                    $between = substr($message, (int) $urlCandidate['end'], max(0, (int) $candidate['offset'] - (int) $urlCandidate['end']));
                    if ($urlCandidate['offset'] <= $candidate['offset'] && !preg_match('/\R\s*\R/u', $between)) {
                        $candidate['associated_url'] = (int) $urlCandidate['offset'];
                        break;
                    }
                }
            }
        }
        $groupedCandidates[] = $candidate;
    }
    $candidates = $groupedCandidates;
    $urlOffsets = [];
    foreach ($candidates as $candidate) if ($candidate['type'] === 'url') $urlOffsets[(int) $candidate['offset']] = true;
    $candidates = array_values(array_filter($candidates, static function ($candidate) use ($urlOffsets) { return !isset($candidate['associated_url']) || !isset($urlOffsets[$candidate['associated_url']]); }));
    if (!$candidates) return [];
    if (count($candidates) > 8) {
        throw new InvalidArgumentException('Please send no more than 8 product links or ASINs in one message.');
    }

    $marketText = $message;
    for ($index = count($urlRanges) - 1; $index >= 0; $index--) {
        $length = $urlRanges[$index][1] - $urlRanges[$index][0];
        $marketText = substr_replace($marketText, str_repeat(' ', $length), $urlRanges[$index][0], $length);
    }
    $globalMarket = dd_extract_product_input($pdo, 'B000000000 ' . $marketText);
    $clauses = [];
    if (preg_match_all('/[^\r\n;|]+/', $message, $clauseMatches, PREG_OFFSET_CAPTURE)) {
        foreach ($clauseMatches[0] as $clause) $clauses[] = ['text' => trim((string) $clause[0]), 'start' => (int) $clause[1], 'end' => (int) $clause[1] + strlen((string) $clause[0])];
    }
    $paragraphs = [];
    if (preg_match_all('/[^\r\n]+(?:\r?\n(?!\r?\n)[^\r\n]+)*/', $message, $paragraphMatches, PREG_OFFSET_CAPTURE)) {
        foreach ($paragraphMatches[0] as $paragraph) $paragraphs[] = ['text' => trim((string) $paragraph[0]), 'start' => (int) $paragraph[1], 'end' => (int) $paragraph[1] + strlen((string) $paragraph[0])];
    }

    $parsedInputs = [];
    $seen = [];
    foreach ($candidates as $index => $candidate) {
        // Keep the preamble with the first product, then associate following text
        // with the nearest preceding identifier until the next product begins.
        $contextStart = $index === 0 ? 0 : (int) $candidate['offset'];
        $contextEnd = isset($candidates[$index + 1]) ? (int) $candidates[$index + 1]['offset'] : strlen($message);
        $context = trim(substr($message, $contextStart, max(0, $contextEnd - $contextStart)));
        if ($candidate['type'] === 'asin' && !empty($candidate['associated_url'])) {
            foreach ($urlRanges as [$rangeStart, $rangeEnd]) {
                if ((int) $rangeStart === (int) $candidate['associated_url']) {
                    $relativeStart = max(0, (int) $rangeStart - $contextStart);
                    $relativeLength = max(0, (int) $rangeEnd - (int) $rangeStart);
                    $context = substr_replace($context, ' ', $relativeStart, $relativeLength);
                    break;
                }
            }
        }
        $parseContext = $candidate['type'] === 'asin'
            ? preg_replace('/(?<![A-Z0-9])[A-Z0-9]{10}(?![A-Z0-9])/i', ' ', $context)
            : $context;
        $parseText = $candidate['type'] === 'url' ? $candidate['raw'] . ' ' . $context : 'ASIN: ' . $candidate['raw'] . ' ' . $parseContext;
        $parsed = dd_extract_product_input($pdo, $parseText);
        foreach ($clauses as $clause) {
            if ((int) $candidate['offset'] < $clause['start'] || (int) $candidate['offset'] >= $clause['end']) continue;
            // URL candidates were already resolved by the full-context parse above.
            // Re-parsing them here caused a second outbound request for the same short link.
            if ($candidate['type'] === 'asin' && empty($candidate['associated_url'])) {
                $clauseContext = preg_replace('/(?<![A-Z0-9])[A-Z0-9]{10}(?![A-Z0-9])/i', ' ', $clause['text']);
                $clauseParse = dd_extract_product_input($pdo, 'ASIN: ' . $candidate['raw'] . ' ' . $clauseContext);
                if (!empty($clauseParse['asin']) && empty($clauseParse['ambiguous'])) {
                    if (!empty($clauseParse['marketplace'])) $parsed['marketplace'] = $clauseParse['marketplace'];
                    if (empty($parsed['asin'])) $parsed['asin'] = $clauseParse['asin'];
                    if (empty($clauseParse['marketplace']) && !empty($parsed['ambiguous'])) $parsed['ambiguous'] = true;
                }
            }
            break;
        }
        $notesParts = [];
        if (count($candidates) === 1) {
            $notesParts[] = $message;
        } else {
            foreach ($paragraphs as $paragraph) {
                $paragraphIndexes = [];
                foreach ($candidates as $candidateIndex => $other) {
                    if ($other['offset'] >= $paragraph['start'] && $other['offset'] < $paragraph['end']) $paragraphIndexes[] = $candidateIndex;
                }
                if (count($paragraphIndexes) === 1) {
                    $ownerIndex = $paragraphIndexes[0];
                    if ($ownerIndex === $index) $notesParts[] = $paragraph['text'];
                    continue;
                }
                if (count($paragraphIndexes) > 1) {
                    foreach ($paragraphIndexes as $partIndex => $ownerIndex) {
                        $partStart = $partIndex === 0 ? $paragraph['start'] : (int) $candidates[$ownerIndex]['offset'];
                        $nextCandidateIndex = $paragraphIndexes[$partIndex + 1] ?? null;
                        $partEnd = $nextCandidateIndex === null ? $paragraph['end'] : (int) $candidates[$nextCandidateIndex]['offset'];
                        if ($ownerIndex === $index) $notesParts[] = trim(substr($message, $partStart, max(0, $partEnd - $partStart)));
                    }
                    continue;
                }
                $nearestIndex = 0;
                $nearestDistance = PHP_INT_MAX;
                foreach ($candidates as $candidateIndex => $other) {
                    $distance = $other['offset'] < $paragraph['start'] ? $paragraph['start'] - $other['offset'] : ($other['offset'] > $paragraph['end'] ? $other['offset'] - $paragraph['end'] : 0);
                    if ($distance < $nearestDistance) { $nearestDistance = $distance; $nearestIndex = $candidateIndex; }
                }
                if ($nearestIndex === $index) $notesParts[] = $paragraph['text'];
            }
        }
        $notesContext = trim(implode("\n", $notesParts));
        if (empty($parsed['marketplace']) && !empty($globalMarket['marketplace']) && empty($globalMarket['ambiguous'])) $parsed['marketplace'] = $globalMarket['marketplace'];
        elseif (!empty($parsed['marketplace']) && !empty($globalMarket['marketplace']) && strcasecmp((string) $parsed['marketplace'], (string) $globalMarket['marketplace']) !== 0) {
            $parsed['marketplace'] = null;
            $parsed['ambiguous'] = true;
            $parsed['error'] = 'marketplace_conflict';
        } elseif (empty($parsed['marketplace']) && !empty($globalMarket['ambiguous'])) $parsed['ambiguous'] = true;
        $asin = strtoupper((string) ($parsed['asin'] ?? ''));
        if (preg_match('/^[A-Z0-9]{10}$/', $asin)) {
            $key = $asin . '|' . strtolower(trim((string) ($parsed['marketplace'] ?? '')));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
        }
        $parsedInputs[] = [
            'raw' => $context,
            'context' => $context,
            'notes_context' => $notesContext,
            'parsed' => $parsed,
            'type' => $candidate['type'],
            'offset' => (int) $candidate['offset'],
        ];
    }
    return $parsedInputs;
}

/** Preserve original message text while removing source URLs and empty whitespace. */
function dd_extract_product_notes($context)
{
    $text = (string) $context;
    $text = preg_replace('~https?://[^\s<>"\']+|(?<![A-Z0-9@])www\.[^\s<>"\']+~iu', ' ', $text);
    $notes = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim(preg_replace('/[\t ]+/', ' ', (string) $line));
        if ($line !== '') $notes[] = $line;
    }
    return $notes;
}
