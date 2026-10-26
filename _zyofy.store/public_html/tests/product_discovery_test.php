<?php
require_once __DIR__ . '/../inc/product-discovery.php';
require_once __DIR__ . '/../inc/amazon-products.php';
require_once __DIR__ . '/../inc/whatsapp.php';

final class DiscoveryTestStatement extends PDOStatement
{
    private DiscoveryTestPDO $pdo;
    private string $sql;
    private array $rows = [];
    private $column = false;

    public function __construct(DiscoveryTestPDO $pdo, string $sql) { $this->pdo = $pdo; $this->sql = $sql; if (str_contains($sql, 'FROM marketplace_settings') && !str_contains($sql, 'WHERE marketplace = ?')) $this->rows = $pdo->markets; }
    public function execute(?array $params = null): bool
    {
        $params = $params ?? [];
        $this->rows = [];
        $this->column = false;
        if (preg_match('/^(INSERT INTO|UPDATE) products\\b/i', trim($this->sql))) $this->pdo->permanentProductWrites++;
        if (str_contains($this->sql, 'FROM marketplace_settings')) {
            $market = (string) ($params[0] ?? '');
            if (str_contains($this->sql, 'WHERE marketplace = ?')) {
                $this->column = $market === '' ? false : $market;
                $country = stripos($market, 'co.uk') !== false ? 'United Kingdom' : (stripos($market, 'amazon.de') !== false ? 'Germany' : 'United States');
                $this->rows = $this->column ? [['marketplace' => $market, 'country_label' => $country]] : [];
            } else {
                $this->rows = $this->pdo->markets;
            }
        } elseif (str_contains($this->sql, 'FROM user_tags')) {
            $this->column = ((string) ($params[0] ?? '') === 'contributor@example.com' && (string) ($params[1] ?? '') === 'www.amazon.com' && (!isset($params[2]) || (int) $params[2] === 9)) ? 9 : false;
        } elseif (str_contains($this->sql, 'FROM products')) {
            $this->rows = $this->pdo->productRows;
        } elseif (str_contains($this->sql, 'INSERT INTO product_discovery_selections')) {
            [$token, $channel, $userId, $scope, $json] = $params;
            $this->pdo->selections[$channel . '|' . $userId . '|' . $scope] = ['selection_token' => $token, 'channel' => $channel, 'user_id' => $userId, 'scope_hash' => $scope, 'candidates_json' => $json, 'expired' => false];
        } elseif (str_contains($this->sql, 'SELECT selection_token, candidates_json')) {
            [$channel, $userId, $scope] = $params;
            $row = $this->pdo->selections[$channel . '|' . $userId . '|' . $scope] ?? null;
            if ($row && !$row['expired'] && (!isset($params[3]) || hash_equals($row['selection_token'], (string) $params[3]))) $this->rows[] = $row;
        } elseif (str_contains($this->sql, 'DELETE FROM product_discovery_selections WHERE selection_token')) {
            foreach ($this->pdo->selections as $key => $row) if ($row['selection_token'] === $params[0]) unset($this->pdo->selections[$key]);
        }
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) return array_map(static function ($row) { return array_values($row)[0] ?? false; }, $this->rows);
        return $this->rows;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->rows[0] ?? false; }
    public function fetchColumn(int $column = 0): mixed { return $this->column; }
}

final class DiscoveryTestPDO extends PDO
{
    public array $markets = [
        ['marketplace' => 'www.amazon.com', 'country_label' => 'United States'],
        ['marketplace' => 'www.amazon.co.uk', 'country_label' => 'United Kingdom'],
        ['marketplace' => 'www.amazon.de', 'country_label' => 'Germany'],
    ];
    public array $productRows = [];
    public int $permanentProductWrites = 0;
    public array $selections = [];
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new DiscoveryTestStatement($this, $query); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new DiscoveryTestStatement($this, $query); }
    public function exec(string $statement): int|false { return 0; }
}

function discovery_test(string $name, bool $passed): void
{
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) $GLOBALS['discovery_test_failures']++;
}

$GLOBALS['discovery_test_failures'] = 0;
$pdo = new DiscoveryTestPDO();
$parsed = dd_product_discovery_parse($pdo, "Keyword: massage gun\nSold by: medisana HK\nStore: Medisana\nMarketplace: USA");
discovery_test('labeled keyword, seller, store, and marketplace parse independently', $parsed['valid'] && $parsed['keyword'] === 'massage gun' && $parsed['seller'] === 'medisana HK' && $parsed['store'] === 'Medisana' && $parsed['marketplace'] === 'www.amazon.com');
$parsedPair = dd_product_discovery_parse($pdo, "wireless earbuds sold by Example Seller\nStore: AnkerDirect");
discovery_test('keyword plus inline seller and labeled store are parsed without ordering requirements', $parsedPair['keyword'] === 'wireless earbuds' && $parsedPair['seller'] === 'Example Seller' && $parsedPair['store'] === 'AnkerDirect');
$parsedKeywordSeller = dd_product_discovery_parse($pdo, "Keyword: massage gun\nSold by: medisana HK");
$parsedKeywordStore = dd_product_discovery_parse($pdo, "Keyword: wireless earbuds\nStore: AnkerDirect");
discovery_test('keyword plus seller and keyword plus store combinations parse independently', $parsedKeywordSeller['keyword'] === 'massage gun' && $parsedKeywordSeller['seller'] === 'medisana HK' && $parsedKeywordSeller['store'] === '' && $parsedKeywordStore['keyword'] === 'wireless earbuds' && $parsedKeywordStore['store'] === 'AnkerDirect' && $parsedKeywordStore['seller'] === '');
$parsedTwo = dd_product_discovery_parse($pdo, "Sold by: ABC Store\nStore name: ABC Official\nUK");
discovery_test('seller plus store parses independently with marketplace alias', $parsedTwo['seller'] === 'ABC Store' && $parsedTwo['store'] === 'ABC Official' && $parsedTwo['marketplace'] === 'www.amazon.co.uk');
$parsedNatural = dd_product_discovery_parse($pdo, 'Find massage gun');
discovery_test('single keyword is a valid search clue', $parsedNatural['valid'] && $parsedNatural['keyword'] === 'massage gun');
$parsedInline = dd_product_discovery_parse($pdo, 'Keyword: massage gun, Sold By: medisana HK, Store: Medisana, Marketplace: USA');
discovery_test('multiple labeled clues on one line are extracted in place', $parsedInline['keyword'] === 'massage gun' && $parsedInline['seller'] === 'medisana HK' && $parsedInline['store'] === 'Medisana' && $parsedInline['marketplace'] === 'www.amazon.com');
$parsedLongFields = dd_product_discovery_parse($pdo, "Keyword: 5-Blade Electric Head Shaver - Cordless Rechargeable Razor for Men\nSold By: GEYILO LLC-43\nBrand: TEPOTZ\nProduct Code: 3054904");
discovery_test('sample keyword, seller, brand, and product code remain separate and pass individual clue limits', $parsedLongFields['valid'] && $parsedLongFields['keyword'] === '5-Blade Electric Head Shaver - Cordless Rechargeable Razor for Men' && $parsedLongFields['seller'] === 'GEYILO LLC-43' && $parsedLongFields['store'] === '' && $parsedLongFields['brand'] === 'TEPOTZ' && $parsedLongFields['product_code'] === '3054904');
$parsedLongClues = dd_product_discovery_parse($pdo, "Keyword: " . str_repeat('k', 170) . "\nSold By: " . str_repeat('s', 140) . "\nStore: " . str_repeat('t', 130));
discovery_test('keyword, seller, and store clues above 100 characters pass validation independently', $parsedLongClues['valid'] && strlen($parsedLongClues['keyword']) === 170 && strlen($parsedLongClues['seller']) === 140 && strlen($parsedLongClues['store']) === 130);
discovery_test('seller/store clues stay distinct from brand and seller_name fields', $parsed['brand'] === '' && !array_key_exists('seller_name', $parsed));
$parsedNoColon = dd_product_discovery_parse($pdo, "Keyword wireless earbuds\nSold by Example Seller\nStore AnkerDirect\nMarketplace USA");
discovery_test('natural labels without colons and marketplace text are handled', $parsedNoColon['keyword'] === 'wireless earbuds' && $parsedNoColon['seller'] === 'Example Seller' && $parsedNoColon['store'] === 'AnkerDirect' && $parsedNoColon['marketplace'] === 'www.amazon.com');
$namedProduct = dd_product_discovery_parse($pdo, "Product Name: German coffee grinder\nProduct Code: 3054904\nBrand Name: Example\nMarketplace: Germany");
discovery_test('Product Name and Product Code labels remain distinct from ASIN and notes', $namedProduct['valid'] && $namedProduct['keyword'] === 'German coffee grinder' && $namedProduct['product_code'] === '3054904' && $namedProduct['brand'] === 'Example' && $namedProduct['asin'] === '');
$labelledAsin = dd_product_discovery_parse($pdo, "ASIN: B000000050\nMarketplace: USA");
discovery_test('explicit ASIN label is retained as ASIN evidence, separately from search clues', $labelledAsin['valid'] && $labelledAsin['asin'] === 'B000000050' && $labelledAsin['keyword'] === '');
discovery_test('product-code-only 10 character values do not trigger ASIN routing', !dd_product_discovery_has_product_identifier('Product Code: B000000051') && dd_product_discovery_has_product_identifier('ASIN: B000000051'));
discovery_test('Pickwell product share URL with ASIN and USA routes to direct product generation', dd_product_discovery_has_product_identifier('https://pickwell.t86.site/product/B0FYNXR41Y/-P1fGhvHxpWEYjVO_IrMc9jdosCjOvFz/ USA'));
discovery_test('other site product URL with ASIN and USA routes to direct product generation', dd_product_discovery_has_product_identifier('https://viralnesthome.shop/product/B09QYTLR3K/zyHcrXM1yutEg6UG5-IQt4C_nihZIvFf/ USA'));
discovery_test('Amazon-shaped links on unrelated or suffix-spoofed hosts without marketplace remain search input', !dd_product_discovery_has_product_identifier('https://amazon.com.evil.example/dp/B000000052') && !dd_product_discovery_has_product_identifier('https://evil.example/dp/B000000052'));

$germanTitle = 'SEHMUA 4G LTE Mobiltelefon mit Außenüberwachungskamera und Solarmodul, 2K-HD-Kamera mit 360°-Sicht und PTZ, Akku, farbige Nachtsicht und Mensch-PIR-Erkennung, wasserdicht nach IP66';
$germanMessage = $germanTitle . "\n\nSold by: SEHMUA\n\nAvailable German\n\nTikTok product\n\nBuyers can only purchase the specified color, otherwise no refund will be paid.";
$parsedGerman = dd_product_discovery_parse($pdo, $germanMessage);
discovery_test('long German title, seller, marketplace, and additional notes parse into separate fields', $parsedGerman['valid'] && $parsedGerman['keyword'] === $germanTitle && $parsedGerman['seller'] === 'SEHMUA' && $parsedGerman['marketplace'] === 'www.amazon.de' && $parsedGerman['notes'] === "TikTok product\nBuyers can only purchase the specified color, otherwise no refund will be paid.");

$pdo->productRows = [];
$discoveryQueries = [];
$previousApiResult = dd_product_discovery_search($pdo, $parsedLongFields, 'www.amazon.com', static function ($db, $query, $market) use (&$discoveryQueries) {
    $discoveryQueries[] = [$query, $market];
    return [['asin' => 'B000000011', 'title' => '5-Blade Electric Head Shaver - Cordless Rechargeable Razor for Men', 'description' => '', 'image' => '', 'marketplace' => $market]];
});
discovery_test('previous example passes validation and reaches Amazon fallback discovery', $previousApiResult['source'] === 'amazon_creators_api' && count($previousApiResult['candidates']) === 1 && $discoveryQueries[0][1] === 'www.amazon.com' && str_contains($discoveryQueries[0][0], '5-Blade Electric Head Shaver') && str_contains($discoveryQueries[0][0], 'GEYILO LLC-43'));
$germanQueries = [];
$germanDiscovery = dd_product_discovery_search($pdo, $parsedGerman, $parsedGerman['marketplace'], static function ($db, $query, $market) use (&$germanQueries, $germanTitle) {
    $germanQueries[] = [$query, $market];
    return [['asin' => 'B000000012', 'title' => $germanTitle, 'description' => '', 'image' => '', 'marketplace' => $market]];
});
discovery_test('German example passes validation and reaches Amazon fallback without mixing notes into query', $germanDiscovery['source'] === 'amazon_creators_api' && count($germanDiscovery['candidates']) === 1 && $germanQueries[0][1] === 'www.amazon.de' && str_contains($germanQueries[0][0], $germanTitle) && str_contains($germanQueries[0][0], 'SEHMUA') && !str_contains($germanQueries[0][0], 'TikTok product') && !str_contains($germanQueries[0][0], 'no refund'));
$longContext = dd_product_discovery_parse($pdo, $germanTitle . "\nSold by: SEHMUA\nAvailable German\n" . str_repeat('Additional buyer note. ', 70));
discovery_test('combined discovery context above the former 1200-byte cap is accepted within the request safety cap', $longContext['valid'] && strlen($longContext['notes']) > 1200 && $longContext['keyword'] === $germanTitle);

$pdo->productRows = [
    ['asin' => 'B000000001', 'title' => 'Medisana Deep Tissue Massage Gun', 'description' => 'Portable hand massager', 'image' => 'https://m.media-amazon.com/images/I/sample.jpg', 'marketplace' => 'www.amazon.com'],
    ['asin' => 'B000000002', 'title' => 'Wireless Earbuds with Charging Case', 'description' => 'Bluetooth audio', 'image' => '', 'marketplace' => 'www.amazon.com'],
];
$apiCalls = 0;
$dbHit = dd_product_discovery_search($pdo, ['keyword' => 'massage gun', 'seller' => 'medisana HK', 'store' => 'Medisana'], 'www.amazon.com', static function () use (&$apiCalls) { $apiCalls++; return []; });
discovery_test('database-first matching uses title/description and avoids API when it has a candidate', $dbHit['source'] === 'zyofy_catalog' && count($dbHit['candidates']) === 1 && $dbHit['candidates'][0]['asin'] === 'B000000001' && $apiCalls === 0);
discovery_test('seller/store evidence remains explicitly unverified', $dbHit['candidates'][0]['seller_store_verified'] === false && !$dbHit['candidates'][0]['seller_verified'] && !$dbHit['candidates'][0]['store_verified'] && str_contains($dbHit['candidates'][0]['seller_store_status'], 'Not verified'));
discovery_test('discovery requires an assigned active marketplace tracking ID', dd_product_discovery_has_active_tag($pdo, 'contributor@example.com', 'www.amazon.com', 9) && !dd_product_discovery_has_active_tag($pdo, 'other@example.com', 'www.amazon.com'));
discovery_test('exact keyword/title evidence ranks above a partial title match', dd_product_discovery_score(['title' => 'Massage Gun'], ['keyword' => 'massage gun']) > dd_product_discovery_score(['title' => 'Gun storage rack'], ['keyword' => 'massage gun']));
$pdo->productRows[] = ['asin' => 'B000000004', 'title' => 'Massage Gun Travel Case', 'description' => '', 'image' => '', 'marketplace' => 'www.amazon.com'];
$ranked = dd_product_discovery_search($pdo, ['keyword' => 'massage gun'], 'www.amazon.com', static function () { throw new RuntimeException('API should not be called when catalog candidates exist'); });
discovery_test('multiple plausible saved products are returned for explicit selection', count($ranked['candidates']) === 2 && $ranked['candidates'][0]['asin'] === 'B000000001' && $ranked['candidates'][1]['asin'] === 'B000000004');

$pdo->productRows = [];
$fallback = dd_product_discovery_search($pdo, ['keyword' => 'wireless earbuds', 'seller' => 'Example Seller', 'store' => 'Store Name'], 'www.amazon.com', static function ($db, $query, $market) { return [['asin' => 'B000000003', 'title' => 'Wireless Earbuds with Charging Case', 'description' => '', 'image' => '', 'marketplace' => $market]]; });
discovery_test('database miss invokes the injected Amazon API fallback with marketplace-scoped candidate', $fallback['source'] === 'amazon_creators_api' && count($fallback['candidates']) === 1 && $fallback['candidates'][0]['asin'] === 'B000000003' && $fallback['candidates'][0]['marketplace'] === 'www.amazon.com');
discovery_test('catalog remains untouched while fallback candidates are searched and presented', $pdo->permanentProductWrites === 0 && $pdo->productRows === []);
$eightSearch = dd_product_discovery_search($pdo, ['keyword' => 'wireless earbuds'], 'www.amazon.com', static function ($db, $query, $market) {
    $rows = [];
    for ($i = 1; $i <= 8; $i++) $rows[] = ['asin' => sprintf('B0000000%02d', 40 + $i), 'title' => 'Wireless earbuds candidate ' . $i, 'marketplace' => $market];
    return $rows;
});
discovery_test('searching and returning eight candidates creates zero permanent product records', count($eightSearch['candidates']) === 8 && $pdo->permanentProductWrites === 0 && $pdo->productRows === []);
$noMatch = dd_product_discovery_search($pdo, ['keyword' => '', 'seller' => 'Unknown Seller', 'store' => 'Unknown Store'], 'www.amazon.com', static function () { return []; });
discovery_test('API fallback returns no synthetic candidate when there is no usable match', $noMatch['candidates'] === []);
$sellerStoreOnly = dd_product_discovery_search($pdo, ['keyword' => '', 'seller' => 'ABC Seller', 'store' => 'ABC Official'], 'www.amazon.com', static function ($db, $query, $market) { return [['asin' => 'B000000005', 'title' => 'ABC compatible accessories', 'description' => '', 'image' => '', 'marketplace' => $market]]; });
discovery_test('seller plus store can use API search while leaving the returned seller unverified', count($sellerStoreOnly['candidates']) === 1 && $sellerStoreOnly['candidates'][0]['seller_store_verified'] === false && str_contains($sellerStoreOnly['candidates'][0]['seller_store_status'], 'Not verified'));

$mockApiItem = dd_creator_map_item([
    'asin' => 'B000000021',
    'itemInfo' => [
        'title' => ['displayValue' => 'Example Cordless Shaver'],
        'byLineInfo' => ['brand' => ['displayValue' => 'TEPOTZ'], 'manufacturer' => ['displayValue' => 'TEPOTZ Manufacturing']],
        'manufactureInfo' => ['itemPartNumber' => ['displayValue' => '3054904'], 'model' => ['displayValue' => 'M-4']],
    ],
    'images' => ['primary' => ['medium' => ['url' => 'https://m.media-amazon.com/images/I/sample.jpg']]],
    'offersV2' => ['listings' => [
        ['merchantInfo' => ['name' => 'Other seller']],
        ['merchantInfo' => ['name' => 'GEYILO LLC-43'], 'isBuyBoxWinner' => true],
    ]],
], 'www.amazon.com', ['partner_tag' => 'pickwell-test-20']);
discovery_test('mock Creators API response maps brand, manufacturer, part number, and buy box seller fields', $mockApiItem['brand'] === 'TEPOTZ' && $mockApiItem['manufacturer'] === 'TEPOTZ Manufacturing' && $mockApiItem['product_code'] === '3054904' && $mockApiItem['seller'] === 'GEYILO LLC-43' && $mockApiItem['seller_verified'] === true);
$missingSellerCandidate = dd_product_discovery_candidate(['asin' => 'B000000022', 'title' => 'Sample product', 'marketplace' => 'www.amazon.com'], 'amazon_creators_api', ['keyword' => 'Sample', 'seller' => 'Wanted seller']);
discovery_test('missing seller data is explicitly shown as unverified', str_contains(dd_product_discovery_candidate_caption($missingSellerCandidate, 1), 'Sold by: Not verified'));
$sellerMismatch = dd_product_discovery_candidate($mockApiItem, 'amazon_creators_api', ['keyword' => 'Example Cordless Shaver', 'seller' => 'Unrelated Seller']);
discovery_test('requested seller clue is never displayed as the actual seller and mismatch is explained', $sellerMismatch['seller'] === 'GEYILO LLC-43' && $sellerMismatch['seller_match'] === 'none' && str_contains($sellerMismatch['match_explanation'], 'Seller clue not matched') && !str_contains(dd_product_discovery_candidate_caption($sellerMismatch, 1), 'Unrelated Seller'));
$rankedVerified = dd_product_discovery_search($pdo, ['keyword' => 'Cordless Shaver', 'brand' => 'TEPOTZ', 'seller' => 'GEYILO LLC-43'], 'www.amazon.com', static function ($db, $query, $market) {
    return [
        ['asin' => 'B000000023', 'title' => 'Cordless Shaver', 'brand' => 'Other Brand', 'seller' => 'Different seller', 'seller_verified' => true, 'marketplace' => $market],
        ['asin' => 'B000000024', 'title' => 'Cordless Shaver', 'brand' => 'TEPOTZ', 'seller' => 'GEYILO LLC-43', 'seller_verified' => true, 'marketplace' => $market],
    ];
});
discovery_test('verified brand and seller clues rank the strongest candidate first', $rankedVerified['candidates'][0]['asin'] === 'B000000024' && $rankedVerified['candidates'][0]['brand_match'] === 'exact' && $rankedVerified['candidates'][0]['seller_match'] === 'exact' && $rankedVerified['seller_match_found']);
$itemLookupCalls = 0;
$enrichedSearch = dd_product_discovery_search($pdo, ['keyword' => 'Cordless Shaver', 'seller' => 'GEYILO LLC-43'], 'www.amazon.com', static function ($db, $query, $market) { return [['asin' => 'B000000025', 'title' => 'Cordless Shaver', 'marketplace' => $market]]; }, static function ($db, $asin, $market) use (&$itemLookupCalls) {
    $itemLookupCalls++;
    return ['asin' => $asin, 'title' => 'Cordless Shaver', 'seller' => 'GEYILO LLC-43', 'seller_verified' => true, 'marketplace' => $market];
});
discovery_test('GetItems enrichment is used only when requested seller data is missing and can verify the match', $itemLookupCalls === 1 && $enrichedSearch['candidates'][0]['seller'] === 'GEYILO LLC-43' && $enrichedSearch['candidates'][0]['seller_match'] === 'exact');
discovery_test('SearchItems and GetItems request the documented brand, manufacturer, and merchant resources', substr_count(file_get_contents(__DIR__ . '/../inc/amazon-products.php'), "'itemInfo.byLineInfo'") >= 3 && substr_count(file_get_contents(__DIR__ . '/../inc/amazon-products.php'), "'itemInfo.manufactureInfo'") >= 3 && substr_count(file_get_contents(__DIR__ . '/../inc/amazon-products.php'), "'offersV2.listings.merchantInfo'") >= 3);

$scope = dd_product_discovery_scope_hash('whatsapp', 7, '+15550000001');
$token = dd_product_discovery_store_selection($pdo, 'whatsapp', 7, $scope, $fallback['candidates']);
$pending = dd_product_discovery_pending($pdo, 'whatsapp', 7, $scope);
discovery_test('candidate selection is stored and retrieved only in its owner and channel scope', is_string($token) && $pending !== null && $pending['token'] === $token && dd_product_discovery_pending($pdo, 'whatsapp', 8, $scope) === null && dd_product_discovery_pending($pdo, 'website', 7, $scope) === null);
discovery_test('temporary candidate state stores only the ASIN and marketplace needed for later selection', array_keys($pending['candidates'][0]) === ['asin', 'marketplace']);
discovery_test('selection token lookup rejects another token', dd_product_discovery_pending($pdo, 'whatsapp', 7, $scope, str_repeat('0', 64)) === null);
$selected = dd_product_discovery_selected_candidate($pending, 1);
discovery_test('selection number resolves only an ASIN and marketplace in the saved candidate list', $selected !== null && $selected['asin'] === 'B000000003' && $selected['marketplace'] === 'www.amazon.com' && dd_product_discovery_selected_candidate($pending, 0) === null && dd_product_discovery_selected_candidate($pending, 9) === null);
$selectedThird = dd_product_discovery_selected_candidate(['candidates' => $eightSearch['candidates']], 3);
discovery_test('selecting candidate 3 resolves only candidate 3 and leaves the other candidates unselected', $selectedThird !== null && $selectedThird['asin'] === $eightSearch['candidates'][2]['asin'] && $pdo->permanentProductWrites === 0);
$eightCandidateRows = [];
for ($i = 1; $i <= 8; $i++) $eightCandidateRows[] = ['asin' => sprintf('B0000000%02d', 30 + $i), 'title' => 'Candidate ' . $i, 'marketplace' => 'www.amazon.com'];
$eightPending = ['candidates' => $eightCandidateRows];
discovery_test('reply 1 selects the first candidate and reply 8 selects the eighth when present', dd_product_discovery_selected_candidate($eightPending, 1)['asin'] === 'B000000031' && dd_product_discovery_selected_candidate($eightPending, 8)['asin'] === 'B000000038');
$rejectScope = dd_product_discovery_scope_hash('whatsapp', 44, '+15550000044');
$rejectToken = dd_product_discovery_store_selection($pdo, 'whatsapp', 44, $rejectScope, $eightCandidateRows);
$rejectReply = dd_whatsapp_product_discovery($pdo, ['id' => 44, 'email' => 'contributor@example.com'], '+15550000044', '9');
discovery_test('reply 9 clears the pending list, asks for refined clues, and does not generate a post', is_string($rejectReply) && str_contains($rejectReply, 'did not create') && dd_product_discovery_pending($pdo, 'whatsapp', 44, $rejectScope, $rejectToken) === null && $pdo->permanentProductWrites === 0);
$waPresented = dd_whatsapp_product_discovery($pdo, ['id' => 46, 'email' => 'contributor@example.com'], '+15550000046', "Keyword: massage gun\nSold by: medisana HK\nStore: Medisana\nMarketplace: USA", static function ($db, $query, $market) {
    $rows = [];
    for ($i = 1; $i <= 8; $i++) $rows[] = ['asin' => sprintf('B0000000%02d', 40 + $i), 'title' => 'Massage gun candidate ' . $i, 'marketplace' => $market];
    return $rows;
});
discovery_test('displaying WhatsApp discovery candidates stores pending choices only, with no permanent product writes', isset($waPresented['wa_discovery']['candidates']) && count($waPresented['wa_discovery']['candidates']) === 8 && $pdo->permanentProductWrites === 0 && $pdo->productRows === []);
$nextSearch = dd_product_discovery_parse($pdo, 'Cordless shaver Sold by: New Seller');
discovery_test('a refined search can start after rejecting the previous candidates', $nextSearch['valid'] && $nextSearch['keyword'] === 'Cordless shaver' && $nextSearch['seller'] === 'New Seller' && $pdo->permanentProductWrites === 0);
$expiredScope = dd_product_discovery_scope_hash('whatsapp', 45, '+15550000045');
$expiredToken = dd_product_discovery_store_selection($pdo, 'whatsapp', 45, $expiredScope, $eightCandidateRows);
$pdo->selections['whatsapp|45|' . $expiredScope]['expired'] = true;
$expiredRejectReply = dd_whatsapp_product_discovery($pdo, ['id' => 45, 'email' => 'contributor@example.com'], '+15550000045', '9');
discovery_test('reply 9 on an expired result cannot create a product post', is_string($expiredRejectReply) && str_contains($expiredRejectReply, 'expired') && dd_product_discovery_pending($pdo, 'whatsapp', 45, $expiredScope, $expiredToken) === null);
$pdo->selections['whatsapp|7|' . $scope]['expired'] = true;
discovery_test('expired pending choices are not reused', dd_product_discovery_pending($pdo, 'whatsapp', 7, $scope) === null);
discovery_test('website and WhatsApp session scopes are distinct', dd_product_discovery_scope_hash('website', 7, 'session-1') !== $scope);
$webSource = file_get_contents(__DIR__ . '/../generate.php');
$formSource = file_get_contents(__DIR__ . '/../link-generator.php');
$waSource = file_get_contents(__DIR__ . '/../inc/whatsapp.php');
$webhookSource = file_get_contents(__DIR__ . '/../whatsapp/webhook.php');
$amazonSource = file_get_contents(__DIR__ . '/../inc/amazon-products.php');
$discoverySource = file_get_contents(__DIR__ . '/../inc/product-discovery.php');
$shareSource = file_get_contents(__DIR__ . '/../inc/product-share.php');
discovery_test('website exposes keyword, seller, store and candidate selection actions', str_contains($formSource, 'name="discovery_keyword"') && str_contains($formSource, 'name="discovery_seller"') && str_contains($formSource, 'name="discovery_store"') && str_contains($webSource, "'discovery_action'"));
discovery_test('website and WhatsApp selection use the existing assigned-tag product-post generator', str_contains($webSource, 'dd_create_product_share($pdo, $userId, $email, $candidate[\'asin\']') && str_contains($waSource, 'dd_whatsapp_generate_for_user($pdo, $user, $parsed[\'asin\']'));
discovery_test('Amazon search caching is opt-in for discovery while remaining enabled for existing callers', str_contains($amazonSource, '$cacheResults = true') && str_contains($amazonSource, 'if ($cacheResults)') && str_contains($discoverySource, 'dd_creator_search_products($database, $keywords, $market, 1, $brand, false)'));
discovery_test('the final post flow receives only the selected candidate ASIN', str_contains($webSource, 'dd_create_product_share($pdo, $userId, $email, $candidate[\'asin\']') && str_contains($waSource, 'dd_whatsapp_generate_for_user($pdo, $user, $parsed[\'asin\']'));
discovery_test('selected product refresh and required share writes roll back together on post-creation failure', str_contains($shareSource, '$pdo->beginTransaction()') && str_contains($shareSource, 'SAVEPOINT ' . "' . $savepoint") && str_contains($shareSource, 'ROLLBACK TO SAVEPOINT ' . "' . $savepoint") && str_contains($shareSource, '$pdo->rollBack()'));
discovery_test('valid WhatsApp selection keeps the user account marketplace-tag lookup before generating the product post', str_contains($waSource, 'WHERE LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND LOWER(TRIM(ut.marketplace)) = LOWER(TRIM(?))') && str_contains($waSource, 'dd_whatsapp_generate_for_user($pdo, $user, $parsed[\'asin\']'));
discovery_test('WhatsApp candidate image failure uses the existing text-caption fallback', str_contains($webhookSource, 'discovery_candidate_image_failed') && str_contains($webhookSource, 'dd_whatsapp_send_text($message[\'sender\'], $caption)'));
discovery_test('website results include metadata and support rejecting candidates without changing URL-only success output', str_contains($formSource, 'candidate.brand') && str_contains($formSource, 'candidate.seller_verified') && str_contains($formSource, 'None of these — search again') && str_contains($webSource, "discoveryAction === 'reject'") && str_contains($webSource, '\'post_urls\' => [(string) $post[\'post_url\']]'));

exit($GLOBALS['discovery_test_failures'] === 0 ? 0 : 1);
