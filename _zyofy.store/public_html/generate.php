<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/amazon-products.php';
require_once __DIR__ . '/inc/product-share.php';
require_once __DIR__ . '/inc/product-inputs.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') dd_json(['success' => false, 'message' => 'Method not allowed.'], 405);
if (!dd_user_is_logged_in()) dd_json(['success' => false, 'message' => 'Sign in to generate your tracked link.'], 401);
if (!dd_csrf_validate($_POST['csrf_token'] ?? '')) dd_json(['success' => false, 'message' => 'Your session expired. Reload the page and try again.'], 403);

$message = trim((string) ($_POST['product_message'] ?? ''));
$legacyUrl = trim((string) ($_POST['product_url'] ?? ''));
$legacyMarketplace = trim((string) ($_POST['marketplace'] ?? ''));
$tagId = filter_var($_POST['tag_id'] ?? null, FILTER_VALIDATE_INT);
$email = (string) $_SESSION['user_email'];
$userId = (int) ($_SESSION['user_id'] ?? 0);
$discoveryAction = trim((string) ($_POST['discovery_action'] ?? ''));
if ($discoveryAction !== '') {
    require_once __DIR__ . '/inc/product-discovery.php';
    $scopeHash = dd_product_discovery_scope_hash('website', $userId, session_id());
    if ($discoveryAction === 'search') {
        $criteria = [
            'keyword' => dd_product_discovery_normalize($_POST['discovery_keyword'] ?? ''),
            'seller' => dd_product_discovery_normalize($_POST['discovery_seller'] ?? ''),
            'store' => dd_product_discovery_normalize($_POST['discovery_store'] ?? ''),
            'brand' => dd_product_discovery_normalize($_POST['discovery_brand'] ?? ''),
            'product_code' => dd_product_discovery_normalize($_POST['discovery_product_code'] ?? ''),
        ];
        if ($criteria['keyword'] === '' && $criteria['seller'] === '' && $criteria['store'] === '' && $criteria['brand'] === '' && $criteria['product_code'] === '') dd_json(['success' => false, 'message' => 'Enter a keyword, Sold by, Store, Brand, or Product Code clue.'], 422);
        try {
            $discoveryMarketplace = trim((string) ($_POST['discovery_marketplace'] ?? ''));
            if (!dd_product_discovery_has_active_tag($pdo, $email, $discoveryMarketplace, $tagId ?: null)) dd_json(['success' => false, 'message' => 'Choose a marketplace with an active tracking ID assigned to your account.'], 403);
            $result = dd_product_discovery_search($pdo, $criteria, $discoveryMarketplace);
            if (!$result['candidates']) {
                $sellerNote = $criteria['seller'] !== '' ? ' No verified seller match was found for the Sold by clue.' : '';
                dd_json(['success' => false, 'message' => 'No reliable matches were found.' . $sellerNote . ' Refine your clues or use the product URL or ASIN.'], 404);
            }
            $token = dd_product_discovery_store_selection($pdo, 'website', $userId, $scopeHash, $result['candidates']);
            dd_json(['success' => true, 'selection_token' => $token, 'candidates' => $result['candidates'], 'source' => $result['source'], 'seller_match_found' => !empty($result['seller_match_found']), 'seller_clue_present' => $criteria['seller'] !== '']);
        } catch (InvalidArgumentException $error) {
            dd_json(['success' => false, 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('generate.php product discovery failed; ' . get_class($error));
            dd_json(['success' => false, 'message' => 'Product discovery is unavailable. Use an Amazon URL or ASIN with the existing generator.'], 503);
        }
    }
    if ($discoveryAction === 'reject') {
        $token = trim((string) ($_POST['selection_token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) dd_json(['success' => false, 'message' => 'That product selection is invalid. Search again.'], 422);
        $pending = dd_product_discovery_pending($pdo, 'website', $userId, $scopeHash, $token);
        if (!$pending) dd_json(['success' => false, 'message' => 'Those product choices expired. Search again.'], 410);
        dd_product_discovery_delete_selection($pdo, $token);
        dd_json(['success' => true, 'message' => 'Choices cleared. Refine your search and try again.']);
    }
    if ($discoveryAction === 'select') {
        $token = trim((string) ($_POST['selection_token'] ?? ''));
        $choice = filter_var($_POST['selection_number'] ?? null, FILTER_VALIDATE_INT);
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || $choice === false || $choice < 1 || $choice > 8) dd_json(['success' => false, 'message' => 'That product selection is invalid. Search again.'], 422);
        $pending = dd_product_discovery_pending($pdo, 'website', $userId, $scopeHash, $token);
        $candidate = dd_product_discovery_selected_candidate($pending, $choice);
        if (!$candidate) dd_json(['success' => false, 'message' => 'That product choice expired or is no longer available. Search again.'], 410);
        $marketplace = trim((string) ($candidate['marketplace'] ?? ''));
        if ($marketplace === '') dd_json(['success' => false, 'message' => 'The selected product has no verified marketplace. Search again.'], 422);
        if ($tagId) {
            $tagStatement = $pdo->prepare('SELECT ut.tracking_id, ut.marketplace FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE ut.id = ? AND LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\' LIMIT 1');
            $tagStatement->execute([(int) $tagId, $email]);
        } else {
            $tagStatement = $pdo->prepare('SELECT ut.tracking_id, ut.marketplace FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND LOWER(TRIM(ut.marketplace)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\' ORDER BY ut.id ASC LIMIT 1');
            $tagStatement->execute([$email, $marketplace]);
        }
        $tag = $tagStatement->fetch(PDO::FETCH_ASSOC);
        if (!$tag) dd_json(['success' => false, 'message' => 'No active tracking ID is assigned for this marketplace.'], 403);
        if (strcasecmp(trim((string) $tag['marketplace']), $marketplace) !== 0) dd_json(['success' => false, 'message' => 'The selected tracking ID belongs to a different marketplace. Choose a matching ID and try again.'], 422);
        try {
            $post = dd_create_product_share($pdo, $userId, $email, $candidate['asin'], $marketplace, $tag, true);
            dd_product_discovery_delete_selection($pdo, $token);
            dd_json(['success' => true, 'post_urls' => [(string) $post['post_url']], 'errors' => []]);
        } catch (Throwable $error) {
            error_log('generate.php selected discovery product failed; ' . get_class($error));
            dd_json(['success' => false, 'message' => 'Could not create this product post. Check the Amazon API connection and assigned tracking ID, then search again.'], 422);
        }
    }
    dd_json(['success' => false, 'message' => 'Unknown product discovery action.'], 422);
}
if ($message === '' && $legacyUrl !== '') {
    if (strlen($legacyUrl) > 500 || $legacyMarketplace === '') dd_json(['success' => false, 'message' => 'Paste product details with an ASIN and marketplace.'], 422);
    $message = preg_match('/^[A-Z0-9]{10}$/i', $legacyUrl) ? strtoupper($legacyUrl) . ' ' . $legacyMarketplace : $legacyUrl;
}

try {
    $inputs = dd_extract_product_inputs($pdo, $message);
} catch (Throwable $error) {
    dd_json(['success' => false, 'message' => 'Enter one or more Amazon product URLs or ASINs with their marketplaces.'], 422);
}
if (!$inputs) dd_json(['success' => false, 'message' => 'Could not identify a valid Amazon product. Paste a product URL or a 10-character ASIN with its marketplace.'], 422);

$postUrls = [];
$errors = [];
$tagMarketplace = '';
if ($tagId) {
    $tagMarketQuery = $pdo->prepare('SELECT ut.marketplace FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE ut.id = ? AND LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\' LIMIT 1');
    $tagMarketQuery->execute([(int) $tagId, $email]);
    $tagMarketplace = (string) ($tagMarketQuery->fetchColumn() ?: '');
    if ($tagMarketplace === '') dd_json(['success' => false, 'message' => 'Select an active tracking ID assigned to your account.'], 403);
}

$availableMarketplaces = null;
$availableQuery = null;
foreach ($inputs as $input) {
    $parsed = $input['parsed'];
    if (!empty($parsed['ambiguous'])) {
        $errors[] = 'A product matches more than one marketplace. Add its country next to that product.';
        continue;
    }
    $asin = strtoupper((string) ($parsed['asin'] ?? ''));
    $marketplace = trim((string) ($parsed['marketplace'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) {
        $errors[] = 'Could not identify a valid ASIN for one of the product inputs.';
        continue;
    }
    if ($marketplace === '' && $tagMarketplace !== '') $marketplace = $tagMarketplace;
    if ($marketplace === '' && $availableMarketplaces === null) {
        $availableQuery = $pdo->prepare('SELECT DISTINCT ut.marketplace FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\'');
        $availableQuery->execute([$email]);
        $availableMarketplaces = $availableQuery->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($marketplace === '' && count((array) $availableMarketplaces) === 1) $marketplace = (string) $availableMarketplaces[0];
    if ($marketplace === '') {
        $errors[] = 'Add a marketplace such as UK or USA for this product.';
        continue;
    }
    if (!empty($parsed['error']) && !$asin) {
        $errors[] = 'Amazon could not resolve one of the product links. Try its full country-specific product URL or ASIN.';
        continue;
    }

    if ($tagId) {
        $tagStatement = $pdo->prepare('SELECT ut.tracking_id, ut.marketplace FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE ut.id = ? AND LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\' LIMIT 1');
        $tagStatement->execute([(int) $tagId, $email]);
    } else {
        $tagStatement = $pdo->prepare('SELECT ut.tracking_id, ut.marketplace FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND LOWER(TRIM(ut.marketplace)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\' ORDER BY ut.id ASC LIMIT 1');
        $tagStatement->execute([$email, $marketplace]);
    }
    $tag = $tagStatement->fetch(PDO::FETCH_ASSOC);
    if (!$tag) {
        $errors[] = 'No active tracking ID is assigned for one product marketplace. Ask the Zyofy administrator to assign one.';
        continue;
    }
    if (strcasecmp(trim((string) $tag['marketplace']), $marketplace) !== 0) {
        $errors[] = 'The selected tracking ID belongs to a different marketplace than one of the products.';
        continue;
    }
    try {
        $post = dd_create_product_share($pdo, $userId, $email, $asin, $marketplace, $tag, true);
        $postUrls[] = (string) $post['post_url'];
    } catch (Throwable $error) {
        error_log('generate.php product/share generation failed; ' . get_class($error));
        if ($error instanceof InvalidArgumentException) $errors[] = $error->getMessage();
        elseif ($error instanceof RuntimeException && stripos($error->getMessage(), 'not configured') !== false) $errors[] = 'The Amazon API is not configured for one product marketplace. Contact the Zyofy administrator.';
        elseif ($error instanceof RuntimeException && stripos($error->getMessage(), 'Amazon') !== false) $errors[] = 'Amazon could not verify one product for the selected marketplace. Check its country and try again.';
        else $errors[] = 'Could not create one product post right now. Check the product details and try again.';
    }
}

if (!$postUrls) dd_json(['success' => false, 'message' => $errors[0] ?? 'No valid product posts could be generated.'], 422);
dd_json(['success' => true, 'post_urls' => $postUrls, 'errors' => $errors]);
