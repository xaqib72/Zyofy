<?php
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/../autoload.php';

use Amazon\CreatorsAPI\v1\Configuration;
use Amazon\CreatorsAPI\v1\com\amazon\creators\api\DefaultApi;
use Amazon\CreatorsAPI\v1\com\amazon\creators\model\GetItemsRequestContent;
use Amazon\CreatorsAPI\v1\com\amazon\creators\model\SearchItemsRequestContent;
use GuzzleHttp\Client;

function dd_creator_api_credentials(PDO $pdo, $marketplace)
{
    $normalize = static function ($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (strpos($value, '://') === false) $value = 'https://' . $value;
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        return preg_replace('/^www\./', '', rtrim($host, '.'));
    };
    $expectedMarketplace = $normalize($marketplace);
    $statement = $pdo->query(
        'SELECT credential_id, credential_secret, partner_tag, marketplace, version
         FROM amazon_api_settings ORDER BY id DESC'
    );
    $credentials = null;
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if ($normalize($candidate['marketplace'] ?? '') === $expectedMarketplace) {
            $credentials = $candidate;
            break;
        }
    }
    if (!$credentials) {
        throw new RuntimeException('No Amazon API credentials are saved for ' . (string) $marketplace . '. Add a connection for this marketplace in Admin → API connections.');
    }
    if (empty($credentials['credential_id']) || empty($credentials['credential_secret'])) {
        throw new RuntimeException('The Amazon API credential ID or secret is empty for ' . (string) $marketplace . '. Update that connection in Admin → API connections.');
    }
    if (empty($credentials['partner_tag'])) {
        throw new RuntimeException('The Amazon Partner Tag is missing for ' . (string) $marketplace . '. Update that connection in Admin → API connections.');
    }
    return $credentials;
}

function dd_creator_api_client(array $credentials)
{
    $config = Configuration::getDefaultConfiguration();
    $config->setCredentialId((string) $credentials['credential_id']);
    $config->setCredentialSecret((string) $credentials['credential_secret']);
    $config->setVersion(trim((string) ($credentials['version'] ?? '')) ?: '3.1');
    return new DefaultApi(new Client(['connect_timeout' => 5, 'timeout' => 15]), $config);
}

function dd_creator_payload($model)
{
    $json = json_encode($model, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $data = $json === false ? null : json_decode($json, true);
    return is_array($data) ? $data : [];
}

function dd_creator_map_item(array $item, $marketplace, array $credentials)
{
    $asin = strtoupper(trim((string) ($item['asin'] ?? '')));
    if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) {
        return null;
    }
    $title = (string) ($item['itemInfo']['title']['displayValue'] ?? '');
    $byLine = $item['itemInfo']['byLineInfo'] ?? [];
    $brand = trim((string) ($byLine['brand']['displayValue'] ?? ''));
    $manufacturer = trim((string) ($byLine['manufacturer']['displayValue'] ?? ''));
    $manufactureInfo = $item['itemInfo']['manufactureInfo'] ?? [];
    $productCode = trim((string) ($manufactureInfo['itemPartNumber']['displayValue'] ?? ''));
    $model = trim((string) ($manufactureInfo['model']['displayValue'] ?? ''));
    $image = (string) (
        $item['images']['primary']['large']['url']
        ?? $item['images']['primary']['medium']['url']
        ?? $item['images']['primary']['small']['url']
        ?? ''
    );
    $listings = is_array($item['offersV2']['listings'] ?? null) ? $item['offersV2']['listings'] : [];
    $listing = [];
    $seller = '';
    foreach ($listings as $offer) {
        if (!is_array($offer)) continue;
        $merchantName = trim((string) ($offer['merchantInfo']['name'] ?? ''));
        if ($merchantName === '') continue;
        if (!$listing || !empty($offer['isBuyBoxWinner'])) $listing = $offer;
        if ($seller === '' || !empty($offer['isBuyBoxWinner'])) $seller = $merchantName;
        if (!empty($offer['isBuyBoxWinner'])) break;
    }
    if (!$listing && isset($listings[0]) && is_array($listings[0])) $listing = $listings[0];
    $priceNode = $listing['price'] ?? [];
    $moneyNode = $priceNode['money'] ?? [];
    $displayAmount = trim((string) ($moneyNode['displayAmount'] ?? $priceNode['displayAmount'] ?? ''));
    $amount = $moneyNode['amount'] ?? $priceNode['amount'] ?? null;
    $currency = (string) ($moneyNode['currency'] ?? $moneyNode['currencyCode'] ?? $priceNode['currency'] ?? '');
    $price = $displayAmount !== '' ? $displayAmount : (($amount !== null && $currency !== '')
        ? $currency . ' ' . number_format((float) $amount, 2, '.', '')
        : '');
    $description = '';
    $features = $item['itemInfo']['features']['displayValues'] ?? [];
    if (is_array($features)) {
        $description = implode("\n", array_slice(array_map('strval', $features), 0, 5));
    }

    return [
        'asin' => $asin,
        'title' => $title !== '' ? $title : 'Amazon product ' . $asin,
        'brand' => $brand,
        'manufacturer' => $manufacturer,
        'product_code' => $productCode,
        'model' => $model,
        'seller' => $seller,
        'seller_verified' => $seller !== '',
        'store' => '',
        'store_verified' => false,
        'image' => $image,
        'price' => $price,
        'description' => $description,
        'marketplace' => (string) $marketplace,
        'price_refreshed_at' => $price !== '' ? gmdate('Y-m-d H:i:s') : null,
        'affiliate_link' => dd_amazon_link($marketplace, $asin, $credentials['partner_tag']),
    ];
}

function dd_creator_cache_products(PDO $pdo, array $products)
{
    if (!$products) return;
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('content_refreshed_at', $columns, true) || !in_array('price_refreshed_at', $columns, true)) return;
    $find = $pdo->prepare('SELECT id, affiliate_link FROM products WHERE asin = ? AND marketplace = ? ORDER BY id DESC LIMIT 1');
    foreach ($products as $product) {
        if (!is_array($product) || empty($product['asin']) || empty($product['marketplace'])) continue;
        $find->execute([$product['asin'], $product['marketplace']]);
        $existing = $find->fetch(PDO::FETCH_ASSOC) ?: null;
        $id = $existing ? (int) $existing['id'] : 0;
        $affiliateLink = (string) ($product['affiliate_link'] ?? '');
        if ($existing && trim((string) ($existing['affiliate_link'] ?? '')) !== '') {
            $existingQuery = [];
            parse_str((string) parse_url((string) $existing['affiliate_link'], PHP_URL_QUERY), $existingQuery);
            $existingTag = trim((string) ($existingQuery['tag'] ?? ''));
            if ($existingTag !== '') {
                try {
                    $assigned = $pdo->prepare('SELECT COUNT(*) FROM user_tags WHERE tracking_id = ? AND LOWER(TRIM(marketplace)) = LOWER(TRIM(?))');
                    $assigned->execute([$existingTag, $product['marketplace']]);
                    if ((int) $assigned->fetchColumn() > 0) $affiliateLink = (string) $existing['affiliate_link'];
                } catch (Throwable $error) {
                    error_log('Amazon product cache assigned-tag lookup: ' . $error->getMessage());
                }
            }
        }
        $refreshedAt = gmdate('Y-m-d H:i:s');
        $hasPrice = trim((string) ($product['price'] ?? '')) !== '';
        $values = [
            (string) ($product['title'] ?? ''),
            (string) ($product['image'] ?? ''),
            (string) ($product['price'] ?? ''),
            (string) ($product['description'] ?? ''),
            $affiliateLink,
            $hasPrice ? $refreshedAt : null,
            $refreshedAt,
        ];
        if ($id > 0) {
            if ($hasPrice) {
                $update = $pdo->prepare('UPDATE products SET title = ?, image = ?, price = ?, description = ?, affiliate_link = ?, price_refreshed_at = ?, content_refreshed_at = ? WHERE id = ?');
                $update->execute([...$values, $id]);
            } else {
                $update = $pdo->prepare('UPDATE products SET title = ?, image = ?, description = ?, affiliate_link = ?, content_refreshed_at = ? WHERE id = ?');
                $update->execute([$values[0], $values[1], $values[3], $values[4], $values[6], $id]);
            }
        } else {
            try {
                $insert = $pdo->prepare('INSERT INTO products (title, asin, image, price, marketplace, description, affiliate_link, price_refreshed_at, content_refreshed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([$values[0], $product['asin'], $values[1], $hasPrice ? $values[2] : null, $product['marketplace'], $values[3], $values[4], $values[5], $values[6]]);
            } catch (PDOException $exception) {
                // A concurrent search may have inserted this ASIN; refresh that row.
                $find->execute([$product['asin'], $product['marketplace']]);
                $existing = $find->fetch(PDO::FETCH_ASSOC) ?: null;
                $id = $existing ? (int) $existing['id'] : 0;
                if ($id > 0) {
                    $retryAffiliateLink = $affiliateLink;
                    if ($existing && trim((string) ($existing['affiliate_link'] ?? '')) !== '') {
                        $existingQuery = [];
                        parse_str((string) parse_url((string) $existing['affiliate_link'], PHP_URL_QUERY), $existingQuery);
                        $existingTag = trim((string) ($existingQuery['tag'] ?? ''));
                        if ($existingTag !== '') {
                            try {
                                $assigned = $pdo->prepare('SELECT COUNT(*) FROM user_tags WHERE tracking_id = ? AND LOWER(TRIM(marketplace)) = LOWER(TRIM(?))');
                                $assigned->execute([$existingTag, $product['marketplace']]);
                                if ((int) $assigned->fetchColumn() > 0) $retryAffiliateLink = (string) $existing['affiliate_link'];
                            } catch (Throwable $error) {
                                error_log('Amazon product cache retry assigned-tag lookup: ' . $error->getMessage());
                            }
                        }
                    }
                    if ($hasPrice) {
                        $retryValues = $values;
                        $retryValues[4] = $retryAffiliateLink;
                        $update = $pdo->prepare('UPDATE products SET title = ?, image = ?, price = ?, description = ?, affiliate_link = ?, price_refreshed_at = ?, content_refreshed_at = ? WHERE id = ?');
                        $update->execute([...$retryValues, $id]);
                    } else {
                        $update = $pdo->prepare('UPDATE products SET title = ?, image = ?, description = ?, affiliate_link = ?, content_refreshed_at = ? WHERE id = ?');
                        $update->execute([$values[0], $values[1], $values[3], $retryAffiliateLink, $values[6], $id]);
                    }
                } else {
                    throw $exception;
                }
            }
        }
    }
}

function dd_creator_search_products(PDO $pdo, $keywords, $marketplace, $page = 1, $brand = '', $cacheResults = true)
{
    $keywords = trim((string) $keywords);
    // SearchItemsRequestContent's keywords field supports up to 1000 characters.
    $keywordLength = function_exists('mb_strlen') ? mb_strlen($keywords, 'UTF-8') : strlen($keywords);
    if ($keywords === '' || $keywordLength > 1000) {
        throw new InvalidArgumentException('Enter a product or brand to search.');
    }
    $brand = trim((string) $brand);
    $brandLength = function_exists('mb_strlen') ? mb_strlen($brand, 'UTF-8') : strlen($brand);
    if ($brandLength > 1000) throw new InvalidArgumentException('The brand search clue is too long for Amazon product search.');
    $marketplace = trim((string) $marketplace);
    $active = $pdo->prepare('SELECT marketplace FROM marketplace_settings WHERE marketplace = ? AND is_active = 1 LIMIT 1');
    $active->execute([$marketplace]);
    if (!$active->fetchColumn()) {
        throw new InvalidArgumentException('Choose an active Amazon marketplace.');
    }
    $credentials = dd_creator_api_credentials($pdo, $marketplace);
    $request = new SearchItemsRequestContent([
        'partnerTag' => (string) $credentials['partner_tag'],
        'keywords' => $keywords,
        'brand' => $brand !== '' ? $brand : null,
        'itemCount' => 10,
        'itemPage' => max(1, min(10, (int) $page)),
        'resources' => [
            'images.primary.medium',
            'images.primary.large',
            'itemInfo.title',
            'itemInfo.byLineInfo',
            'itemInfo.manufactureInfo',
            'itemInfo.features',
            'offersV2.listings.merchantInfo',
            'offersV2.listings.price',
        ],
    ]);
    $payload = dd_creator_payload(dd_creator_api_client($credentials)->searchItems($marketplace, $request));
    $items = $payload['searchResult']['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }
    $mapped = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $product = dd_creator_map_item($item, $marketplace, $credentials);
            if ($product !== null) {
                $mapped[] = $product;
            }
        }
    }
    // General catalog refreshes may cache SearchItems results. Product discovery
    // passes false so unselected candidates never enter the permanent catalog.
    if ($cacheResults) {
        dd_creator_cache_products($pdo, $mapped);
    }
    return $mapped;
}

function dd_creator_get_product(PDO $pdo, $asin, $marketplace)
{
    $asin = strtoupper(trim((string) $asin));
    if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) {
        return null;
    }
    $active = $pdo->prepare('SELECT marketplace FROM marketplace_settings WHERE marketplace = ? AND is_active = 1 LIMIT 1');
    $active->execute([(string) $marketplace]);
    if (!$active->fetchColumn()) {
        return null;
    }
    $credentials = dd_creator_api_credentials($pdo, $marketplace);
    $request = new GetItemsRequestContent([
        'partnerTag' => (string) $credentials['partner_tag'],
        'itemIds' => [$asin],
        'resources' => [
            'images.primary.medium',
            'images.primary.large',
            'itemInfo.title',
            'itemInfo.byLineInfo',
            'itemInfo.manufactureInfo',
            'itemInfo.features',
            'offersV2.listings.merchantInfo',
            'offersV2.listings.price',
        ],
    ]);
    $payload = dd_creator_payload(dd_creator_api_client($credentials)->getItems($marketplace, $request));
    $item = $payload['itemsResult']['items'][0] ?? null;
    if (is_array($item)) {
        return dd_creator_map_item($item, $marketplace, $credentials);
    }

    // Some valid catalog items are omitted from GetItems responses. Search by the
    // exact ASIN in the same marketplace and accept only an exact ASIN match.
    $searchRequest = new SearchItemsRequestContent([
        'partnerTag' => (string) $credentials['partner_tag'],
        'keywords' => $asin,
        'itemCount' => 10,
        'itemPage' => 1,
        'resources' => [
            'images.primary.medium',
            'images.primary.large',
            'itemInfo.title',
            'itemInfo.byLineInfo',
            'itemInfo.manufactureInfo',
            'itemInfo.features',
            'offersV2.listings.merchantInfo',
            'offersV2.listings.price',
        ],
    ]);
    $searchPayload = dd_creator_payload(dd_creator_api_client($credentials)->searchItems($marketplace, $searchRequest));
    $items = $searchPayload['searchResult']['items'] ?? [];
    if (!is_array($items)) {
        return null;
    }
    foreach ($items as $searchItem) {
        if (!is_array($searchItem) || strtoupper((string) ($searchItem['asin'] ?? '')) !== $asin) {
            continue;
        }
        return dd_creator_map_item($searchItem, $marketplace, $credentials);
    }
    return null;
}
