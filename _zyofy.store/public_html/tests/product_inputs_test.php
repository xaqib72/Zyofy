<?php
require_once __DIR__ . '/../inc/whatsapp.php';
require_once __DIR__ . '/../inc/product-inputs.php';

final class ProductInputTestStatement extends PDOStatement
{
    private array $rows;
    private string $countryLabel;

    public function __construct(array $rows = [], string $countryLabel = '')
    {
        $this->rows = $rows;
        $this->countryLabel = $countryLabel;
    }

    public function execute(?array $params = null): bool
    {
        $marketplace = strtolower((string) ($params[0] ?? ''));
        $this->countryLabel = str_contains($marketplace, 'co.uk') ? 'United Kingdom' : (str_contains($marketplace, 'amazon.com') ? 'United States' : $marketplace);
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) return array_column($this->rows, 'marketplace');
        return $this->rows;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[0] ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->countryLabel ?: false;
    }
}

final class ProductInputTestPDO extends PDO
{
    private array $marketplaces;

    public function __construct()
    {
        $this->marketplaces = [
            ['marketplace' => 'www.amazon.com', 'country_label' => 'United States'],
            ['marketplace' => 'www.amazon.co.uk', 'country_label' => 'United Kingdom'],
        ];
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new ProductInputTestStatement($this->marketplaces);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ProductInputTestStatement();
    }
}

function product_input_test(string $name, bool $passed): void
{
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) $GLOBALS['product_input_test_failures']++;
}

function product_input_summary(PDO $pdo, string $message): array
{
    $items = dd_extract_product_inputs($pdo, $message);
    return array_map(static function ($item) {
        $parsed = $item['parsed'];
        return [strtoupper((string) ($parsed['asin'] ?? '')), (string) ($parsed['marketplace'] ?? ''), !empty($parsed['ambiguous'])];
    }, $items);
}

$GLOBALS['product_input_test_failures'] = 0;
$pdo = new ProductInputTestPDO();
$cases = [
    'single Amazon URL' => ['https://www.amazon.com/dp/B000000001', [['B000000001', 'www.amazon.com', false]]],
    'punctuation and emoji around an Amazon URL' => ["✨ New: (https://www.amazon.com/gp/aw/dp/B000000098?tag=example), check this!", [['B000000098', 'www.amazon.com', false]]],
    'supported link.amazon short URL' => ['https://link.amazon/B000000002 UK', [['B000000002', 'www.amazon.co.uk', false]]],
    'ASIN followed by marketplace' => ['ASIN: B000000003 Marketplace: USA', [['B000000003', 'www.amazon.com', false]]],
    'external product URL containing an ASIN with USA clue' => ["Crunchy Squishy Pack\nhttps://trendingpickstoday.com/product/B0HHKYV9DJ?ref=post\nSold by: LIANGDONGDONG\nUSA market", [['B0HHKYV9DJ', 'www.amazon.com', false]]],
    'Pickwell product share URL containing ASIN with USA clue' => ["https://pickwell.t86.site/product/B0FYNXR41Y/-P1fGhvHxpWEYjVO_IrMc9jdosCjOvFz/\nUSA", [['B0FYNXR41Y', 'www.amazon.com', false]]],
    'marketplace followed by ASIN' => ['Marketplace: UK ASIN: B000000004', [['B000000004', 'www.amazon.co.uk', false]]],
    'three products with separate marketplaces and notes' => ["Keywords: nightlight for kids\nStore: CoolNight\nASIN: B000000005\nUSA\n\nKeywords: desk lamp\nASIN: B000000006\nUK\n\nASIN: B000000007 USA", [['B000000005', 'www.amazon.com', false], ['B000000006', 'www.amazon.co.uk', false], ['B000000007', 'www.amazon.com', false]]],
    'duplicate products removed' => ['B000000008 USA; https://www.amazon.com/dp/B000000008', [['B000000008', 'www.amazon.com', false]]],
    'invalid ASIN shape ignored' => ['ASIN: BADASIN UK', []],
    'ambiguous marketplace preserved for clarification' => ['ASIN: B000000009 USA UK', [['B000000009', '', true]]],
    'ten-letter keyword is not treated as an ASIN' => ["Keywords: nightlight for kids\nASIN: B000000010 USA", [['B000000010', 'www.amazon.com', false]]],
];
foreach ($cases as $name => [$message, $expected]) product_input_test($name, product_input_summary($pdo, $message) === $expected);
$externalInput = dd_extract_product_inputs($pdo, "Crunchy Squishy Pack\nhttps://trendingpickstoday.com/product/B0HHKYV9DJ?ref=post\nSold by: LIANGDONGDONG\nUSA market");
$externalNotes = dd_extract_product_notes((string) ($externalInput[0]['notes_context'] ?? ''));
product_input_test('external source URL is removed from outgoing notes while product details remain', $externalNotes === ['Crunchy Squishy Pack', 'Sold by: LIANGDONGDONG', 'USA market']);

$amazonShortHosts = ['a.co', 'amzn.to', 'amzn.eu', 'amzn.asia', 'amzn.in', 'link.amazon'];
$mockResponses = [];
$resolverLogs = [];
$GLOBALS['dd_amazon_short_link_test_logger'] = static function (array $record) use (&$resolverLogs) { $resolverLogs[] = $record; };
$GLOBALS['dd_amazon_short_link_test_dns_resolver'] = static function ($host) { return ['93.184.216.34']; };
$GLOBALS['dd_amazon_short_link_test_transport'] = static function ($url, $host, $pinnedIp) use (&$mockResponses) {
    product_input_test('resolver pins the validated public address before issuing a request', $pinnedIp === '93.184.216.34');
    $key = strtolower((string) parse_url($url, PHP_URL_HOST));
    $response = isset($mockResponses[$key]) && $mockResponses[$key] ? array_shift($mockResponses[$key]) : null;
    return $response ?? ['status' => 200, 'effective_url' => $url];
};
foreach ($amazonShortHosts as $shortHost) {
    $mockResponses[$shortHost] = [['status' => 302, 'location' => 'https://www.amazon.com/dp/B000000099'], ['status' => 200, 'effective_url' => 'https://www.amazon.com/dp/B000000099']];
    $resolved = dd_resolve_amazon_short_url($pdo, 'https://' . $shortHost . '/test-code');
    product_input_test('reviewed short-link host resolves through an allowed Amazon product redirect: ' . $shortHost, $resolved === 'https://www.amazon.com/dp/B000000099');
}
// link.amazon currently uses an Amazon-mediated bridge before reaching the storefront.
$mockResponses['link.amazon'] = [['status' => 302, 'location' => 'https://amzlinks.in/B0j5h8crF']];
$mockResponses['amzlinks.in'] = [['status' => 302, 'location' => 'https://www.amazon.com/dp/B000000099']];
$mockResponses['www.amazon.com'] = [['status' => 200, 'effective_url' => 'https://www.amazon.com/dp/B000000099']];
$linkAmazonResolved = dd_resolve_amazon_short_url($pdo, 'https://link.amazon/B0j5h8crF');
product_input_test('link.amazon follows its observed bridge only to a verified Amazon product destination', $linkAmazonResolved === 'https://www.amazon.com/dp/B000000099');
$mockResponses['link.amazon'] = [['status' => 302, 'location' => 'https://amzlinks.in/B0j5h8crF']];
$mockResponses['amzlinks.in'] = [['status' => 302, 'location' => 'https://www.amazon.com/dp/B000000099']];
$mockResponses['www.amazon.com'] = [['status' => 200, 'effective_url' => 'https://www.amazon.com/dp/B000000099']];
$linkAmazonInput = dd_extract_product_input($pdo, 'https://link.amazon/B0j5h8crF');
product_input_test('link.amazon bridge resolves through the product-input parser to a real ASIN and marketplace', $linkAmazonInput['asin'] === 'B000000099' && $linkAmazonInput['marketplace'] === 'www.amazon.com');
$directBridge = dd_resolve_amazon_short_url($pdo, 'https://amzlinks.in/B0j5h8crF');
product_input_test('observed link.amazon bridge is not accepted as a standalone short-link input', $directBridge === null);
product_input_test('resolver diagnostics record safe redirect metadata without query strings or secrets', !empty($resolverLogs) && ($resolverLogs[0]['input_host'] ?? '') === 'a.co' && ($resolverLogs[0]['outcome'] ?? '') === 'success' && ($resolverLogs[0]['redirect_count'] ?? 0) === 1 && ($resolverLogs[0]['asin_extracted'] ?? false) === true && !array_key_exists('query', $resolverLogs[0]) && !array_key_exists('token', $resolverLogs[0]));
$mockResponses['a.co'] = [['status' => 302, 'location' => 'https://www.amazon.com/dp/B000000099'], ['status' => 200, 'effective_url' => 'https://www.amazon.com/dp/B000000099']];
$logsBeforeSingleParse = count($resolverLogs);
$singleResolutionInput = dd_extract_product_inputs($pdo, "https://a.co/d/opaque-test\nMarketplace: USA");
$resolutionLogsForInput = array_slice($resolverLogs, $logsBeforeSingleParse);
product_input_test('one short-link input performs only one resolver request while retaining ASIN and marketplace', count($resolutionLogsForInput) === 1 && ($singleResolutionInput[0]['parsed']['asin'] ?? '') === 'B000000099' && ($singleResolutionInput[0]['parsed']['marketplace'] ?? '') === 'www.amazon.com');
$mockResponses['a.co'] = [['status' => 404]];
$http404 = null;
try { dd_resolve_amazon_short_url($pdo, 'https://a.co/not-a-real-code'); } catch (RuntimeException $error) { $http404 = $error->getMessage(); }
product_input_test('HTTP 404 is classified for bounded recovery and is not treated as a guessed product', $http404 === 'Amazon short link could not be resolved: http_404.');
$http404Log = end($resolverLogs);
product_input_test('HTTP failure diagnostics include status and failed stage without URL query data', ($http404Log['http_status'] ?? null) === 404 && ($http404Log['stage'] ?? '') === 'http_request' && !array_key_exists('query', $http404Log));
$mockResponses['a.co'] = [['status' => 302, 'location' => 'https://www.amazon.com/dp/B000000098']];
$mockResponses['www.amazon.com'] = [['status' => 404, 'effective_url' => 'https://www.amazon.com/dp/B000000098']];
$verified404Url = dd_resolve_amazon_short_url($pdo, 'https://a.co/known-product');
$mockResponses['a.co'] = [['status' => 302, 'location' => 'https://www.amazon.com/dp/B000000098']];
$mockResponses['www.amazon.com'] = [['status' => 404, 'effective_url' => 'https://www.amazon.com/dp/B000000098']];
$verified404Product = dd_extract_product_input($pdo, 'https://a.co/known-product USA');
product_input_test('HTTP 404 after redirect to a verified Amazon ASIN preserves that exact product URL', $verified404Url === 'https://www.amazon.com/dp/B000000098');
product_input_test('recovered 404 short link continues through normal ASIN and marketplace parsing', $verified404Product['asin'] === 'B000000098' && $verified404Product['marketplace'] === 'www.amazon.com' && $verified404Product['error'] === '');
$verified404Log = end($resolverLogs);
product_input_test('verified Amazon 404 recovery is distinguishable in safe diagnostics', ($verified404Log['outcome'] ?? '') === 'recovered' && ($verified404Log['category'] ?? '') === 'http_404_asin_from_verified_amazon_destination' && ($verified404Log['asin_extracted'] ?? false) === true);
$mockResponses['a.co'] = [['status' => 302, 'location' => 'https://amzlinks.in/B0j5h8crF']];
$unsafeBridge = null;
try { dd_resolve_amazon_short_url($pdo, 'https://a.co/bridge-spoof'); } catch (RuntimeException $error) { $unsafeBridge = $error->getMessage(); }
product_input_test('Amazon bridge is rejected when it is not the immediate link.amazon redirect target', $unsafeBridge === 'Amazon short link could not be resolved: unsafe_redirect.');
$mockResponses['amzn.to'] = [['timeout' => true]];
$timeoutCategory = null;
try { dd_resolve_amazon_short_url($pdo, 'https://amzn.to/transient'); } catch (RuntimeException $error) { $timeoutCategory = $error->getMessage(); }
product_input_test('short-link timeout has a safe diagnostic category', $timeoutCategory === 'Amazon short link could not be resolved: timeout.');
$timeoutLog = end($resolverLogs);
product_input_test('timeout diagnostics include safe cURL number and sanitized error text', ($timeoutLog['curl_errno'] ?? 0) === 28 && ($timeoutLog['curl_error'] ?? '') === 'Operation timed out' && ($timeoutLog['stage'] ?? '') === 'http_request');
$mockResponses['amzn.eu'] = [['transport_error' => true, 'curl_errno' => 7, 'unsafe_message' => 'token=should-never-be-logged']];
$transportCategory = null;
try { dd_resolve_amazon_short_url($pdo, 'https://amzn.eu/connect-failure'); } catch (RuntimeException $error) { $transportCategory = $error->getMessage(); }
$transportLog = end($resolverLogs);
product_input_test('transport diagnostics use a safe cURL message and never log injected error details', $transportCategory === 'Amazon short link could not be resolved: transport_error.' && ($transportLog['curl_errno'] ?? 0) === 7 && ($transportLog['curl_error'] ?? '') === 'Failed to connect' && !str_contains(json_encode($transportLog), 'should-never-be-logged'));
$mockResponses['amzn.eu'] = [['status' => 302, 'location' => 'https://evil.example/dp/B000000099']];
$unsafeRedirect = null;
try { dd_resolve_amazon_short_url($pdo, 'https://amzn.eu/redirect'); } catch (RuntimeException $error) { $unsafeRedirect = $error->getMessage(); }
product_input_test('redirect to an unrelated host is rejected before transport', $unsafeRedirect === 'Amazon short link could not be resolved: unsafe_redirect.');
$GLOBALS['dd_amazon_short_link_test_dns_resolver'] = static function () { return ['127.0.0.1']; };
$unsafeAddress = null;
try { dd_resolve_amazon_short_url($pdo, 'https://amzn.asia/private'); } catch (RuntimeException $error) { $unsafeAddress = $error->getMessage(); }
product_input_test('private resolver address is rejected before transport', $unsafeAddress === 'Amazon short link could not be resolved: unsafe_address.');
$GLOBALS['dd_amazon_short_link_test_dns_resolver'] = static function () { return ['93.184.216.34']; };
$mockResponses['amzn.in'] = [['status' => 302, 'location' => '/next'], ['status' => 302, 'location' => '/next']];
$redirectLoop = null;
try { dd_resolve_amazon_short_url($pdo, 'https://amzn.in/loop'); } catch (RuntimeException $error) { $redirectLoop = $error->getMessage(); }
product_input_test('redirect loop is detected', $redirectLoop === 'Amazon short link could not be resolved: redirect_loop.');
$mockResponses['a.co'] = [['status' => 302, 'location' => 'https://www.amazon.com/dp/B000000099'], ['status' => 200, 'effective_url' => 'https://www.amazon.com/dp/B000000099']];
$extractShort = dd_extract_product_input($pdo, 'https://a.co/test USA');
product_input_test('resolved short URL extracts the ASIN and explicit marketplace', $extractShort['asin'] === 'B000000099' && $extractShort['marketplace'] === 'www.amazon.com');
$mockResponses['a.co'] = [['status' => 404]];
$opaque = dd_extract_product_input($pdo, 'https://a.co/opaque-only-code USA');
product_input_test('opaque short URL returning 404 does not invent or guess an ASIN', empty($opaque['asin']) && $opaque['marketplace'] === 'www.amazon.com' && str_contains($opaque['error'], 'http_404'));
$mockResponses['a.co'] = [['status' => 404]];
$independentAsin = dd_extract_product_input($pdo, "https://a.co/opaque-only-code\nASIN: B000000094\nMarketplace: USA");
product_input_test('HTTP 404 can recover only when an independent explicit ASIN and marketplace are provided', $independentAsin['asin'] === 'B000000094' && $independentAsin['marketplace'] === 'www.amazon.com' && str_contains($independentAsin['error'], 'http_404'));
$mockResponses['a.co'] = [['status' => 404]];
$recoveredInputs = dd_extract_product_inputs($pdo, "https://a.co/opaque-only-code ASIN: B000000094 USA");
product_input_test('short URL plus independent ASIN in the same paragraph produces one recovered product input', count($recoveredInputs) === 1 && $recoveredInputs[0]['parsed']['asin'] === 'B000000094' && $recoveredInputs[0]['parsed']['marketplace'] === 'www.amazon.com' && str_contains($recoveredInputs[0]['parsed']['error'], 'http_404'));
$mockResponses['amzn.to'] = [];
for ($redirectIndex = 1; $redirectIndex <= 8; $redirectIndex++) $mockResponses['amzn.to'][] = ['status' => 302, 'location' => '/hop' . $redirectIndex];
$excessiveRedirects = null;
try { dd_resolve_amazon_short_url($pdo, 'https://amzn.to/many-hops'); } catch (RuntimeException $error) { $excessiveRedirects = $error->getMessage(); }
product_input_test('redirect hop limit is enforced', $excessiveRedirects === 'Amazon short link could not be resolved: too_many_redirects.');
$notAmazon = dd_resolve_amazon_short_url($pdo, 'https://amazon.com.evil.example/dp/B000000095');
product_input_test('unrelated host masquerading as an Amazon URL is not sent through the short-link resolver', $notAmazon === null);
$spoofedProductInputs = dd_extract_product_inputs($pdo, 'https://amazon.com.evil.example/dp/B000000095 USA');
product_input_test('multi-product extractor does not accept a suffix-spoofed Amazon hostname', $spoofedProductInputs === []);
$unconfiguredMarketplaceInputs = dd_extract_product_inputs($pdo, 'https://www.amazon.de/dp/B000000095 Germany');
product_input_test('direct Amazon product URLs are accepted only for configured active marketplaces', $unconfiguredMarketplaceInputs === []);
$directPathAsin = dd_extract_product_input($pdo, 'https://amzn.to/dp/B000000096 USA');
product_input_test('direct conventional ASIN path in a short-link URL avoids resolution and keeps explicit marketplace', $directPathAsin['asin'] === 'B000000096' && $directPathAsin['marketplace'] === 'www.amazon.com');
$unrelatedQueryAsin = dd_extract_product_input($pdo, 'https://evil.example/?asin=B000000097 USA');
product_input_test('asin query parameter on an unrelated URL is not treated as ASIN evidence', empty($unrelatedQueryAsin['asin']));
unset($GLOBALS['dd_amazon_short_link_test_transport']);
$missingCurlCategory = null;
if (!function_exists('curl_init')) {
    try { dd_resolve_amazon_short_url($pdo, 'https://a.co/missing-curl-check'); } catch (RuntimeException $error) { $missingCurlCategory = $error->getMessage(); }
}
product_input_test('server without cURL fails safely with a clear internal category', !function_exists('curl_init') ? $missingCurlCategory === 'Amazon short link could not be resolved: curl_unavailable.' : true);
unset($GLOBALS['dd_amazon_short_link_test_transport'], $GLOBALS['dd_amazon_short_link_test_dns_resolver'], $GLOBALS['dd_amazon_short_link_test_logger']);

$conflictingUrl = dd_extract_product_input($pdo, 'https://www.amazon.com/dp/B000000090 Marketplace: UK');
product_input_test('explicit marketplace conflict with a verified Amazon URL is surfaced for clarification', !empty($conflictingUrl['ambiguous']) && ($conflictingUrl['error'] ?? '') === 'marketplace_conflict');
$conflictingMulti = dd_extract_product_inputs($pdo, 'https://www.amazon.com/dp/B000000090 Marketplace: UK');
product_input_test('multi-product parser also preserves a verified URL marketplace conflict', count($conflictingMulti) === 1 && !empty($conflictingMulti[0]['parsed']['ambiguous']) && ($conflictingMulti[0]['parsed']['error'] ?? '') === 'marketplace_conflict');
$unrelatedUrl = dd_extract_product_input($pdo, 'https://evil.example/dp/B000000091 USA');
product_input_test('ASIN-shaped path on unrelated host is not accepted as an Amazon product', empty($unrelatedUrl['asin']));
$productCodeAsin = dd_extract_product_inputs($pdo, 'Product Code: B000000092 USA');
product_input_test('Product Code field is not reclassified as an ASIN', $productCodeAsin === []);
$longForward = "https://www.amazon.com/dp/B000000093\n" . str_repeat("Seller details and marketplace context. ", 42) . "\nUSA";
product_input_test('bounded parser accepts forwarded product input above 1,200 bytes', strlen($longForward) > 1200 && strlen($longForward) < 16384 && count(dd_extract_product_inputs($pdo, $longForward)) === 1);

$notes = dd_extract_product_notes("https://amzn.to/example\nKeywords: nightlight for kids\nStore: CoolNight\n1 review\nrefund, less $6\nASIN: B000000011");
product_input_test('notes retain labeled product context and remove ASIN/marketplace source URLs', !str_contains(implode(' ', $notes), 'amzn.to') && in_array('Keywords: nightlight for kids', $notes, true) && in_array('Store: CoolNight', $notes, true) && in_array('1 review', $notes, true) && in_array('refund, less $6', $notes, true));

$captionInput = ['context' => "https://www.amazon.com/dp/B000000012\nKeywords: nightlight for kids\nStore: CoolNight\nUSA", 'type' => 'url'];
$captionPost = ['post_url' => 'https://pickwell.t86.site/product/example/', 'asin' => 'B000000012', 'marketplace' => 'www.amazon.com'];
$caption = dd_whatsapp_product_caption($pdo, $captionPost, $captionInput);
product_input_test('WhatsApp caption starts with Pickwell URL, keeps notes, removes Amazon URL, ends with brand', str_starts_with($caption, $captionPost['post_url'] . "\n") && str_contains($caption, 'Keywords: nightlight for kids') && !str_contains($caption, 'amazon.com') && str_ends_with($caption, '✨ Pickwell'));
$urlOnlyCaption = dd_whatsapp_product_caption($pdo, $captionPost, ['context' => 'https://www.amazon.com/dp/B000000012', 'type' => 'url']);
product_input_test('URL-only WhatsApp caption contains only Pickwell URL and final branding', $urlOnlyCaption === $captionPost['post_url'] . "\n✨ Pickwell");
$exampleMessage = "https://www.amazon.com/dp/B0G64FRQLX?tag=example\n\nSold by: medisana HK\nAvailable USA 🇺🇸\n20S less refund\n1 order review per day\nTotal order 2";
$exampleItems = dd_extract_product_inputs($pdo, $exampleMessage);
$exampleCaption = dd_whatsapp_product_caption($pdo, ['post_url' => 'https://pickwell.t86.site/product/actual-generated-slug/', 'asin' => 'B0G64FRQLX', 'marketplace' => 'www.amazon.com'], $exampleItems[0]);
product_input_test('Amazon URL with tracking parameters and multiple note lines keeps original wording and emoji', str_contains($exampleCaption, 'Sold by: medisana HK') && str_contains($exampleCaption, 'Available USA 🇺🇸') && str_contains($exampleCaption, '20S less refund') && str_contains($exampleCaption, '1 order review per day') && str_contains($exampleCaption, 'Total order 2') && !str_contains($exampleCaption, '?tag=example') && str_starts_with($exampleCaption, 'https://pickwell.t86.site/product/actual-generated-slug/'));
$middleUrlCaption = dd_whatsapp_product_caption($pdo, $captionPost, ['context' => "Keywords: bedside light\nhttps://www.amazon.com/dp/B000000012?tag=tracking\nStore note: keep original wording", 'notes_context' => "Keywords: bedside light\nhttps://www.amazon.com/dp/B000000012?tag=tracking\nStore note: keep original wording"]);
product_input_test('relevant text before and after a middle Amazon URL is preserved', str_contains($middleUrlCaption, 'Keywords: bedside light') && str_contains($middleUrlCaption, 'Store note: keep original wording') && !str_contains($middleUrlCaption, 'amazon.com'));
$forwardedCaption = dd_whatsapp_product_caption($pdo, $captionPost, ['notes_context' => "https://amzn.to/short-code\nSold by: medisana HK\nMarketplace: USA\n20S less refund\n1 order review per day\nTotal order 2"]);
product_input_test('forwarded seller, marketplace, refund, review, and order details are retained', str_contains($forwardedCaption, 'Sold by: medisana HK') && str_contains($forwardedCaption, 'Marketplace: USA') && str_contains($forwardedCaption, '20S less refund') && str_contains($forwardedCaption, '1 order review per day') && str_contains($forwardedCaption, 'Total order 2'));
product_input_test('original long and short Amazon URLs never appear in generated captions', !preg_match('~(?:amazon\.com/dp|amzn\.to/)~i', $exampleCaption . $middleUrlCaption . $forwardedCaption));
$multiInputs = dd_extract_product_inputs($pdo, "Keywords: nightlight\nStore: CoolNight\nASIN: B000000013\nUSA\n\nKeywords: desk lamp\nStore: BrightHome\nASIN: B000000014\nUK");
$firstCaption = dd_whatsapp_product_caption($pdo, ['post_url' => 'https://pickwell.t86.site/product/first/', 'asin' => 'B000000013', 'marketplace' => 'www.amazon.com'], $multiInputs[0]);
$secondCaption = dd_whatsapp_product_caption($pdo, ['post_url' => 'https://pickwell.t86.site/product/second/', 'asin' => 'B000000014', 'marketplace' => 'www.amazon.co.uk'], $multiInputs[1]);
product_input_test('multiple WhatsApp captions keep each product notes isolated', str_contains($firstCaption, 'CoolNight') && !str_contains($firstCaption, 'BrightHome') && str_contains($secondCaption, 'BrightHome') && !str_contains($secondCaption, 'CoolNight'));

$webEndpoint = file_get_contents(__DIR__ . '/../generate.php');
$webForm = file_get_contents(__DIR__ . '/../link-generator.php');
product_input_test('website success response contains generated URLs only', str_contains($webEndpoint, "['success' => true, 'post_urls' => \$postUrls, 'errors' => \$errors]") && !str_contains($webForm, 'Your product page is ready') && !str_contains($webForm, 'data.asin') && !str_contains($webForm, 'data.marketplace'));
$webhookSource = file_get_contents(__DIR__ . '/../whatsapp/webhook.php');
product_input_test('WhatsApp accepts image captions without adding OCR or an image-upload service', str_contains($webhookSource, "\$message['image']['caption']") && !str_contains($webhookSource, 'OCR'));
product_input_test('image success and text fallback use the same preserved caption', str_contains($webhookSource, 'dd_whatsapp_send_image($message[\'sender\'], $imageUrl, $caption)') && str_contains($webhookSource, 'dd_whatsapp_send_text($message[\'sender\'], $caption)'));
product_input_test('Website clipboard action copies only each generated Pickwell URL', str_contains($webForm, 'navigator.clipboard.writeText(url)'));

exit($GLOBALS['product_input_test_failures'] === 0 ? 0 : 1);
