<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/../session.php';

if (!function_exists('dd_csrf_token')) {
    function dd_csrf_token()
    {
        if (empty($_SESSION['dd_csrf_token'])) {
            $_SESSION['dd_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['dd_csrf_token'];
    }
}

if (!function_exists('dd_csrf_validate')) {
    function dd_csrf_validate($token)
    {
        $held = isset($_SESSION['dd_csrf_token']) ? (string) $_SESSION['dd_csrf_token'] : '';
        return $held !== '' && is_string($token) && hash_equals($held, $token);
    }
}

if (!function_exists('dd_json')) {
    function dd_json(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('dd_base64url')) {
    function dd_base64url($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

if (!function_exists('dd_share_token')) {
    function dd_share_token()
    {
        return dd_base64url(random_bytes(24));
    }
}

if (!function_exists('dd_product_share_url')) {
    function dd_product_share_url($asin, $token)
    {
        return dd_url('/product/' . rawurlencode((string) $asin) . '/' . rawurlencode((string) $token) . '/');
    }
}

if (!function_exists('dd_product_public_url')) {
    function dd_product_public_url($asin, $marketplace = null)
    {
        $url = dd_url('/product/' . rawurlencode((string) $asin) . '/');
        if ($marketplace) {
            $url .= '?marketplace=' . rawurlencode((string) $marketplace);
        }
        return $url;
    }
}

if (!function_exists('dd_marketplace_domain')) {
    function dd_marketplace_domain($marketplace)
    {
        return preg_replace('/^www\./i', '', strtolower((string) $marketplace));
    }
}

if (!function_exists('dd_detect_marketplace')) {
    function dd_detect_marketplace(PDO $pdo, $url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        if ($host === '') {
            return null;
        }

        $rows = $pdo->query('SELECT marketplace FROM marketplace_settings')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $marketplace) {
            $domain = dd_marketplace_domain($marketplace);
            if ($host === $domain || $host === 'www.' . $domain) {
                return (string) $marketplace;
            }
        }
        return null;
    }
}

if (!function_exists('dd_resolve_amazon_short_url')) {
    /** Resolve reviewed Amazon short-link hosts to exact configured Amazon storefront hosts. */
    function dd_resolve_amazon_short_url(PDO $pdo, $url)
    {
        $shortHosts = ['a.co', 'amzn.to', 'amzn.eu', 'amzn.asia', 'amzn.in', 'link.amazon'];
        $url = trim((string) $url);
        if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
        $parts = parse_url($url);
        $inputHost = strtolower(rtrim((string) (is_array($parts) ? ($parts['host'] ?? '') : ''), '.'));
        $state = ['stage' => 'input_validation', 'status' => null, 'curl_errno' => 0, 'curl_error' => '', 'redirect_count' => 0, 'final_url' => $url];
        $logAttempt = static function ($outcome, $category) use (&$state, $inputHost) {
            $finalParts = parse_url((string) ($state['final_url'] ?? ''));
            $path = (string) ($finalParts['path'] ?? '');
            $path = substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', $path), 0, 240);
            $record = [
                'event' => 'amazon_short_link_resolution',
                'outcome' => (string) $outcome,
                'category' => (string) $category,
                'stage' => (string) ($state['stage'] ?? 'unknown'),
                'input_host' => $inputHost,
                'http_status' => $state['status'],
                'curl_errno' => (int) ($state['curl_errno'] ?? 0),
                'curl_error' => (string) ($state['curl_error'] ?? ''),
                'redirect_count' => (int) ($state['redirect_count'] ?? 0),
                'final_host' => strtolower(rtrim((string) ($finalParts['host'] ?? ''), '.')),
                'final_path' => $path,
                'asin_extracted' => dd_extract_asin((string) ($state['final_url'] ?? '')) !== null,
            ];
            $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $testLogger = PHP_SAPI === 'cli' ? ($GLOBALS['dd_amazon_short_link_test_logger'] ?? null) : null;
            if (is_callable($testLogger)) $testLogger($record);
            elseif (is_string($json)) error_log('[Zyofy Amazon Link] ' . $json);
        };
        if (!is_array($parts)) {
            $logAttempt('failure', 'invalid_url');
            throw new RuntimeException('Amazon short link could not be resolved: invalid_url.');
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (str_starts_with($host, 'www.')) $host = substr($host, 4);
        if (!in_array($host, $shortHosts, true)) {
            $logAttempt('ignored', 'unsupported_host');
            return null;
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443) || isset($parts['fragment'])) {
            $logAttempt('failure', 'invalid_url');
            throw new RuntimeException('Amazon short link could not be resolved: invalid_url.');
        }

        $marketplaces = $pdo->query('SELECT marketplace FROM marketplace_settings')->fetchAll(PDO::FETCH_COLUMN);
        $allowedHosts = $shortHosts;
        foreach ($marketplaces as $marketplace) {
            $domain = strtolower(preg_replace('/^www\./i', '', (string) $marketplace));
            if ($domain !== '' && preg_match('/^amazon\.[a-z0-9.-]+$/', $domain)) {
                $allowedHosts[] = $domain;
                $allowedHosts[] = 'www.' . $domain;
            }
        }
        $allowedHosts = array_values(array_unique($allowedHosts));
        $isAllowedHost = static function ($candidate) use ($allowedHosts) {
            $candidate = strtolower(rtrim((string) $candidate, '.'));
            $canonical = str_starts_with($candidate, 'www.') ? substr($candidate, 4) : $candidate;
            return $candidate !== '' && (bool) preg_match('/^[a-z0-9.-]+$/', $candidate) && (in_array($candidate, $allowedHosts, true) || in_array($canonical, $allowedHosts, true));
        };
        $marketplaceHosts = array_values(array_filter($allowedHosts, static function ($candidate) use ($shortHosts) { return !in_array($candidate, $shortHosts, true); }));
        $resolveLocation = static function ($base, $location) {
            $location = trim((string) $location);
            if ($location === '') return '';
            if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) return $location;
            $baseParts = parse_url($base);
            if (strpos($location, '//') === 0) return 'https:' . $location;
            $origin = 'https://' . ($baseParts['host'] ?? '');
            if (strpos($location, '/') === 0) return $origin . $location;
            if (strpos($location, '?') === 0 || strpos($location, '#') === 0) return $origin . ($baseParts['path'] ?? '/') . $location;
            $path = (string) ($baseParts['path'] ?? '/');
            $joined = substr($path, 0, strrpos($path, '/') + 1) . $location;
            $segments = [];
            foreach (explode('/', $joined) as $segment) {
                if ($segment === '' || $segment === '.') continue;
                if ($segment === '..') { array_pop($segments); continue; }
                $segments[] = $segment;
            }
            return $origin . '/' . implode('/', $segments);
        };

        // These seams permit deterministic resolver tests; production always uses DNS and cURL.
        $testTransport = $GLOBALS['dd_amazon_short_link_test_transport'] ?? null;
        $testDnsResolver = $GLOBALS['dd_amazon_short_link_test_dns_resolver'] ?? null;
        if (!is_callable($testTransport) && !function_exists('curl_init')) {
            throw new RuntimeException('Amazon short link could not be resolved: curl_unavailable.');
        }
        $curl = is_callable($testTransport) ? null : curl_init();
        if (!is_callable($testTransport) && $curl === false) {
            $state['stage'] = 'curl_initialization';
            $logAttempt('failure', 'curl_initialization_failed');
            throw new RuntimeException('Amazon short link could not be resolved: transport_error.');
        }
        if ($curl !== null) curl_setopt($curl, CURLOPT_COOKIEFILE, '');
        $visited = [];
        $previousHost = '';
        $deadline = microtime(true) + 12.0;
        try {
            for ($hop = 0; $hop < 8; $hop++) {
                $state['stage'] = 'redirect_validation';
                $url = trim($url);
                $state['final_url'] = $url;
                if ($url === '' || isset($visited[$url])) throw new RuntimeException('Amazon short link could not be resolved: redirect_loop.');
                $visited[$url] = true;
                $parts = parse_url($url);
                if (!is_array($parts)) throw new RuntimeException('Amazon short link could not be resolved: unsafe_redirect.');
                $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
                // Production diagnostics show link.amazon redirects through this exact
                // intermediary. Permit it only as the immediate HTTPS hop from link.amazon;
                // it is neither an accepted input nor an allowed final destination.
                $isLinkAmazonBridge = $host === 'amzlinks.in' && $previousHost === 'link.amazon';
                if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || (!$isAllowedHost($host) && !$isLinkAmazonBridge) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443) || isset($parts['fragment'])) {
                    throw new RuntimeException('Amazon short link could not be resolved: unsafe_redirect.');
                }
                // Pin cURL to the checked public IPv4 so DNS cannot change between validation and connect.
                $state['stage'] = 'dns_validation';
                $addresses = is_callable($testDnsResolver) ? $testDnsResolver($host) : @gethostbynamel($host);
                if (!is_array($addresses) || !$addresses) throw new RuntimeException('Amazon short link could not be resolved: dns_failure.');
                $publicAddresses = [];
                foreach ($addresses as $address) {
                    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
                    if (!filter_var($address, FILTER_VALIDATE_IP, $flags)) throw new RuntimeException('Amazon short link could not be resolved: unsafe_address.');
                    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | $flags)) $publicAddresses[] = $address;
                }
                if (!$publicAddresses) throw new RuntimeException('Amazon short link could not be resolved: unsupported_address_family.');

                $state['stage'] = 'http_request';
                $state['curl_errno'] = 0;
                $state['curl_error'] = '';
                if (is_callable($testTransport)) {
                    $response = $testTransport($url, $host, $publicAddresses[0]);
                    $status = (int) ($response['status'] ?? 0);
                    $state['status'] = $status;
                    $location = (string) ($response['location'] ?? '');
                    $finalUrl = (string) ($response['effective_url'] ?? $url);
                    if (!empty($response['transport_error'])) {
                        $state['curl_errno'] = (int) ($response['curl_errno'] ?? 0);
                        $safeCurlErrors = [6 => 'Could not resolve host', 7 => 'Failed to connect', 28 => 'Operation timed out', 35 => 'TLS connection failed'];
                        $state['curl_error'] = $safeCurlErrors[$state['curl_errno']] ?? 'cURL request failed';
                        throw new RuntimeException($state['curl_errno'] === 28 ? 'Amazon short link could not be resolved: timeout.' : 'Amazon short link could not be resolved: transport_error.');
                    }
                    if (!empty($response['timeout'])) {
                        $state['curl_errno'] = (int) ($response['curl_errno'] ?? 28);
                        $state['curl_error'] = 'Operation timed out';
                        throw new RuntimeException('Amazon short link could not be resolved: timeout.');
                    }
                } else {
                    $location = '';
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) throw new RuntimeException('Amazon short link could not be resolved: timeout.');
                    curl_setopt_array($curl, [
                        CURLOPT_URL => $url,
                        CURLOPT_RESOLVE => [$host . ':443:' . $publicAddresses[0]],
                        CURLOPT_PROXY => '',
                        CURLOPT_HTTPGET => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT => max(1, min(4, (int) ceil($remaining))),
                        CURLOPT_TIMEOUT => max(1, min(7, (int) ceil($remaining))),
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                        CURLOPT_USERAGENT => 'ZyofyProductLinkResolver/1.0',
                        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
                        CURLOPT_WRITEFUNCTION => static function ($handle, $data) { return strlen($data); },
                        CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$location) {
                            if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                            return strlen($line);
                        },
                    ]);
                    $ok = curl_exec($curl);
                    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                    $state['status'] = $status;
                    $finalUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
                    if ($ok === false) {
                        $state['curl_errno'] = (int) curl_errno($curl);
                        $state['curl_error'] = function_exists('curl_strerror') ? (string) curl_strerror($state['curl_errno']) : 'cURL request failed';
                        if (preg_match('/[\x00-\x1F\x7F]/', $state['curl_error'])) $state['curl_error'] = 'cURL request failed';
                        $state['curl_error'] = substr($state['curl_error'], 0, 120);
                        throw new RuntimeException($state['curl_errno'] === 28 ? 'Amazon short link could not be resolved: timeout.' : 'Amazon short link could not be resolved: transport_error.');
                    }
                }
                $state['final_url'] = $finalUrl;
                if ($status >= 300 && $status < 400 && $location !== '') {
                    if ($hop === 7) throw new RuntimeException('Amazon short link could not be resolved: too_many_redirects.');
                    $state['redirect_count']++;
                    $state['stage'] = 'redirect_follow';
                    $previousHost = $host;
                    $url = $resolveLocation($url, $location);
                    continue;
                }
                if ($status === 404) {
                    // Some Amazon short URLs redirect to a valid country-specific /dp/ASIN
                    // page that returns 404 to server-side requests. Preserve only the ASIN
                    // from that verified Amazon destination; the normal Creators API lookup
                    // still decides whether a product can be created/refreshed.
                    $notFoundParts = parse_url($finalUrl);
                    $notFoundHost = strtolower(rtrim((string) ($notFoundParts['host'] ?? ''), '.'));
                    $isVerifiedAmazonProduct = is_array($notFoundParts)
                        && strtolower((string) ($notFoundParts['scheme'] ?? '')) === 'https'
                        && in_array($notFoundHost, $marketplaceHosts, true)
                        && dd_extract_asin($finalUrl) !== null;
                    if ($isVerifiedAmazonProduct) {
                        $state['stage'] = 'asin_extraction';
                        $logAttempt('recovered', 'http_404_asin_from_verified_amazon_destination');
                        return $finalUrl;
                    }
                    throw new RuntimeException('Amazon short link could not be resolved: http_404.');
                }
                if ($status < 200 || $status >= 300) throw new RuntimeException('Amazon short link could not be resolved: http_error.');
                $state['stage'] = 'destination_validation';
                $finalParts = parse_url($finalUrl);
                $finalHost = strtolower(rtrim((string) ($finalParts['host'] ?? ''), '.'));
                if (!in_array($finalHost, $marketplaceHosts, true) || strtolower((string) ($finalParts['scheme'] ?? '')) !== 'https') throw new RuntimeException('Amazon short link could not be resolved: unsafe_final_url.');
                $state['stage'] = 'asin_extraction';
                $logAttempt('success', 'resolved');
                return $finalUrl;
            }
            throw new RuntimeException('Amazon short link could not be resolved: too_many_redirects.');
        } catch (Throwable $error) {
            $category = dd_amazon_short_link_error_category($error->getMessage());
            if ($category === 'resolution_failed') $category = 'resolution_failed';
            $logAttempt('failure', $category);
            throw $error;
        } finally {
            if ($curl !== null) curl_close($curl);
        }
    }
}
if (!function_exists('dd_amazon_short_link_error_category')) {
    function dd_amazon_short_link_error_category($error)
    {
        $error = strtolower((string) $error);
        foreach (['invalid_url', 'unsupported_host', 'unsafe_redirect', 'unsafe_address', 'unsupported_address_family', 'unsafe_final_url', 'redirect_loop', 'timeout', 'http_404', 'http_error', 'dns_failure', 'curl_initialization_failed', 'transport_error', 'curl_unavailable', 'too_many_redirects'] as $category) {
            if (strpos($error, $category) !== false) return $category;
        }
        return 'resolution_failed';
    }
}

if (!function_exists('dd_extract_product_input')) {
    function dd_extract_product_input(PDO $pdo, $text)
    {
        $text = trim((string) $text);
        $shortHosts = ['a.co', 'amzn.to', 'amzn.eu', 'amzn.asia', 'amzn.in', 'link.amazon'];
        $configuredMarkets = $pdo->query('SELECT marketplace, country_label FROM marketplace_settings WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
        $marketHosts = [];
        foreach ($configuredMarkets as $row) {
            $domain = strtolower(preg_replace('/^www\./i', '', (string) ($row['marketplace'] ?? '')));
            if (preg_match('/^amazon\.[a-z0-9.-]+$/', $domain)) {
                $marketHosts[] = $domain;
                $marketHosts[] = 'www.' . $domain;
            }
        }
        $isConfiguredAmazonHost = static function ($host) use ($marketHosts) {
            $host = strtolower(rtrim((string) $host, '.'));
            return $host !== '' && in_array($host, $marketHosts, true);
        };
        $sourceUrl = null;
        $resolvedUrl = null;
        $resolveError = '';
        $directShortAsin = null;
        $urlPattern = '~https?://[^\s<>"\']+|(?<![A-Z0-9@])(?:www\.)?(?:a\.co|amzn\.(?:to|eu|asia|in)|link\.amazon|amazon\.[a-z0-9.-]+)(?:/[^\s<>"\']*)?~iu';
        if (preg_match_all($urlPattern, $text, $urlMatches)) {
            foreach ($urlMatches[0] as $raw) {
                $candidateUrl = rtrim((string) $raw, '.,);]}>');
                if (!preg_match('~^https?://~i', $candidateUrl)) $candidateUrl = 'https://' . $candidateUrl;
                $parts = parse_url($candidateUrl);
                $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
                $shortHost = preg_replace('/^www\./i', '', $host);
                if (!in_array($shortHost, $shortHosts, true) && !$isConfiguredAmazonHost($host)) continue;
                $sourceUrl = $candidateUrl;
                if (in_array($shortHost, $shortHosts, true)) {
                    if ($shortHost === 'link.amazon' && preg_match('~^/([A-Z0-9]{10})(?:/|$)~i', (string) ($parts['path'] ?? ''), $directMatch)) {
                        $directShortAsin = strtoupper($directMatch[1]);
                    } else {
                        // A conventional /dp/ASIN path is independent product evidence even if resolution fails.
                        $directShortAsin = dd_extract_asin($sourceUrl);
                        if ($directShortAsin === null) {
                            try { $resolvedUrl = dd_resolve_amazon_short_url($pdo, $sourceUrl); }
                            catch (Throwable $error) { $resolveError = dd_amazon_short_link_error_category($error->getMessage()); }
                        }
                    }
                }
                break;
            }
        }

        $asin = $directShortAsin;
        if ($asin === null && $resolvedUrl !== null) $asin = dd_extract_asin($resolvedUrl);
        if ($asin === null && $sourceUrl !== null) {
            $sourceHost = strtolower(rtrim((string) parse_url($sourceUrl, PHP_URL_HOST), '.'));
            if ($isConfiguredAmazonHost($sourceHost)) $asin = dd_extract_asin($sourceUrl);
        }
        $labelText = preg_replace('~https?://[^\s<>"\']+|(?<![A-Z0-9@])(?:www\.)?(?:a\.co|amzn\.(?:to|eu|asia|in)|link\.amazon|amazon\.[a-z0-9.-]+)(?:/[^\s<>"\']*)?~iu', ' ', $text);
        if ($asin === null && preg_match('/(?<![A-Z0-9])ASIN\s*[:=\-]?\s*([A-Z0-9]{10})(?![A-Z0-9])/i', $labelText, $match)) $asin = strtoupper($match[1]);
        // A bare ASIN is supported only as a standalone identifier, never from a Product Code/ID or arbitrary URL.
        if ($asin === null && !preg_match('/\bproduct\s+(?:code|id)\s*[:=]/i', $text)) {
            $plainText = preg_replace('~https?://[^\s<>"\']+|(?<![A-Z0-9@])(?:www\.)?(?:a\.co|amzn\.(?:to|eu|asia|in)|link\.amazon|amazon\.[a-z0-9.-]+)(?:/[^\s<>"\']*)?~iu', ' ', $text);
            if (preg_match('/(?<![A-Z0-9])([A-Z0-9]{10})(?![A-Z0-9])/i', $plainText, $match)) {
                $tokenOffset = (int) strpos($plainText, $match[1]);
                $linePrefix = substr($plainText, (strrpos(substr($plainText, 0, $tokenOffset), "\n") ?: -1) + 1, $tokenOffset - ((strrpos(substr($plainText, 0, $tokenOffset), "\n") ?: -1) + 1));
                $lineEnd = strpos($plainText, "\n", $tokenOffset);
                if ($lineEnd === false) $lineEnd = strlen($plainText);
                $lineSuffix = trim(substr($plainText, $tokenOffset + 10, $lineEnd - ($tokenOffset + 10)), " \t,;|:-");
                $suffixIsMarketplace = $lineSuffix === '' || (bool) preg_match('/^(?:(?:marketplace|country)\s*[:=]?\s*)?(?:USA|US|United States(?: of America)?|UK|United Kingdom|Great Britain|Canada|Germany|German|France|India|Australia|DE|CA|IN|AU)?$/iu', $lineSuffix);
                if (trim($linePrefix, " \t,;|:-") === '' && $suffixIsMarketplace) $asin = strtoupper($match[1]);
            }
        }

        $marketplace = $resolvedUrl ? dd_detect_marketplace($pdo, $resolvedUrl) : null;
        if (!$marketplace && $sourceUrl && $isConfiguredAmazonHost(parse_url($sourceUrl, PHP_URL_HOST))) $marketplace = dd_detect_marketplace($pdo, $sourceUrl);
        $marketText = preg_replace($urlPattern, ' ', $text);
        $aliases = [
            'amazon.com' => ['USA', 'US', 'United States', 'United States of America', 'Amazon US', 'Amazon USA'],
            'amazon.co.uk' => ['UK', 'United Kingdom', 'Great Britain', 'Britain', 'Amazon UK'],
            'amazon.ca' => ['Canada', 'CA', 'Amazon Canada'], 'amazon.de' => ['Germany', 'German', 'DE', 'Deutschland', 'Amazon Germany'],
            'amazon.fr' => ['France', 'FR', 'Amazon France'], 'amazon.it' => ['Italy', 'IT'], 'amazon.es' => ['Spain', 'ES'],
            'amazon.co.jp' => ['Japan', 'JP'], 'amazon.in' => ['India', 'IN'], 'amazon.com.au' => ['Australia', 'AU'],
            'amazon.com.mx' => ['Mexico', 'MX'], 'amazon.com.br' => ['Brazil', 'BR'], 'amazon.nl' => ['Netherlands', 'NL'],
            'amazon.se' => ['Sweden', 'SE'], 'amazon.pl' => ['Poland', 'PL'], 'amazon.com.be' => ['Belgium', 'BE'],
            'amazon.com.tr' => ['Turkey', 'Türkiye', 'TR'], 'amazon.ae' => ['United Arab Emirates', 'UAE', 'AE'],
            'amazon.sa' => ['Saudi Arabia', 'SA'], 'amazon.eg' => ['Egypt', 'EG'], 'amazon.sg' => ['Singapore', 'SG'],
        ];
        $explicitMarkets = [];
        foreach ($configuredMarkets as $row) {
            $market = (string) ($row['marketplace'] ?? '');
            $domain = strtolower(preg_replace('/^www\./i', '', $market));
            $terms = array_merge([(string) ($row['country_label'] ?? ''), $domain, 'www.' . $domain], $aliases[$domain] ?? []);
            foreach (array_unique(array_filter($terms)) as $term) {
                $quoted = preg_quote((string) $term, '/');
                if (preg_match('/(?<![\pL\pN])' . $quoted . '(?![\pL\pN])/iu', $marketText)) {
                    $explicitMarkets[$market] = true;
                    break;
                }
            }
        }
        if (count($explicitMarkets) > 1) return ['asin' => $asin, 'marketplace' => null, 'ambiguous' => true, 'error' => 'marketplace_ambiguity'];
        $explicitMarketplace = $explicitMarkets ? (string) array_key_first($explicitMarkets) : null;
        if ($marketplace && $explicitMarketplace && strcasecmp($marketplace, $explicitMarketplace) !== 0) {
            return ['asin' => $asin, 'marketplace' => null, 'ambiguous' => true, 'error' => 'marketplace_conflict'];
        }
        if (!$marketplace) $marketplace = $explicitMarketplace;
        return ['asin' => $asin, 'marketplace' => $marketplace, 'ambiguous' => false, 'error' => $resolveError];
    }
}
if (!function_exists('dd_extract_asin')) {
    function dd_extract_asin($url)
    {
        $url = trim((string) $url);
        $patterns = [
            '~/(?:dp|gp/product|gp/aw/dp|gp/aw/d|product)/([A-Z0-9]{10})(?:[/?#]|$)~i',
            '~[?&](?:asin|ASIN)=([A-Z0-9]{10})(?:&|$)~i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return strtoupper($m[1]);
            }
        }
        return null;
    }
}

if (!function_exists('dd_amazon_link')) {
    function dd_amazon_link($marketplace, $asin, $trackingId)
    {
        return 'https://' . (string) $marketplace . '/dp/' . rawurlencode((string) $asin)
            . '?tag=' . rawurlencode((string) $trackingId) . '&linkCode=ogi&th=1&psc=1';
    }
}

if (!function_exists('dd_price_text')) {
    function dd_price_text($price)
    {
        $price = trim((string) $price);
        if ($price === '' || strcasecmp($price, 'N/A') === 0) {
            return 'See price on Amazon';
        }
        return $price . ' on Amazon';
    }
}

if (!function_exists('dd_product_content_ttl_seconds')) {
    function dd_product_content_ttl_seconds()
    {
        // Amazon offer data is volatile and must be refreshed at least hourly.
        return 60 * 60;
    }
}

if (!function_exists('dd_product_price_is_fresh')) {
    function dd_product_price_is_fresh($refreshedAt, $price = null)
    {
        $refreshedAt = trim((string) $refreshedAt);
        $priceText = trim((string) $price);
        if ($refreshedAt === '' || $priceText === '' || strcasecmp($priceText, 'N/A') === 0) {
            return false;
        }
        try {
            // Database timestamps are written in UTC; do not interpret them in the
            // web server's local timezone when deciding whether a price is fresh.
            $timestamp = (new DateTimeImmutable($refreshedAt, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable $exception) {
            return false;
        }
        return $timestamp >= (time() - dd_product_content_ttl_seconds());
    }
}

if (!function_exists('dd_amazon_image')) {
    function dd_amazon_image($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        // Older records were saved as Amazon's 160px thumbnail. Ask the same CDN for
        // a larger rendition when that standard size marker is present.
        return preg_replace('/\._SL\d+_\./i', '._SL700_.', $url);
    }
}

if (!function_exists('dd_marketplace_label')) {
    function dd_marketplace_label(PDO $pdo, $marketplace)
    {
        static $labels = [];
        $key = (string) $marketplace;
        if (array_key_exists($key, $labels)) {
            return $labels[$key];
        }
        $stmt = $pdo->prepare('SELECT country_label FROM marketplace_settings WHERE marketplace = ? LIMIT 1');
        $stmt->execute([$key]);
        $labels[$key] = (string) ($stmt->fetchColumn() ?: $key);
        return $labels[$key];
    }
}

if (!function_exists('dd_logged_in_user')) {
    function dd_logged_in_user(PDO $pdo)
    {
        if (!dd_user_is_logged_in()) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id, name, email, is_admin FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            dd_logout_user();
            return null;
        }
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        return $user;
    }
}
