<?php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../inc/site.php';
require_once __DIR__ . '/../../inc/manual-products.php';

header('Content-Type: application/json; charset=utf-8');
$title = trim((string) ($data['title'] ?? ''));
$imageUrl = trim((string) ($data['image_url'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));
$affiliateUrl = trim((string) ($data['affiliate_url'] ?? ''));
$marketplace = dd_manual_amazon_marketplace($affiliateUrl);
$imageUpload = $_FILES['image_file'] ?? null;
$createdImagePath = '';
$titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
$descriptionLength = function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description);

if ($title === '' || $titleLength > 500) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a product title up to 500 characters.']);
    exit;
}
if ($descriptionLength > 20000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Description must be 20,000 characters or fewer.']);
    exit;
}
$hasImageUpload = is_array($imageUpload) && (int) ($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($hasImageUpload) {
    if ((int) ($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($imageUpload['size'] ?? 0) < 1 || (int) $imageUpload['size'] > 5 * 1024 * 1024 || !is_uploaded_file((string) ($imageUpload['tmp_name'] ?? ''))) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Image upload failed or exceeds the 5 MB limit.']);
        exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $imageUpload['tmp_name']);
    $extensions = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
    if (!isset($extensions[$mime])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Upload a JPEG, PNG, or WebP image.']);
        exit;
    }
    $uploadDirectory = __DIR__ . '/../../assets/uploads/manual-products';
    if (!is_dir($uploadDirectory) && !@mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The server could not prepare image storage.']);
        exit;
    }
    $fileName = bin2hex(random_bytes(16)) . $extensions[$mime];
    $createdImagePath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file((string) $imageUpload['tmp_name'], $createdImagePath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The server could not save the uploaded image.']);
        exit;
    }
    @chmod($createdImagePath, 0644);
    $imageUrl = dd_url('/assets/uploads/manual-products/' . $fileName);
}
$image = parse_url($imageUrl);
if ((!$hasImageUpload && $imageUrl !== '' && (!is_array($image) || strtolower((string) ($image['scheme'] ?? '')) !== 'https' || empty($image['host']) || isset($image['user']) || isset($image['pass']) || strlen($imageUrl) > 2048))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Use a public HTTPS image URL, or leave the image blank.']);
    exit;
}
$affiliate = parse_url($affiliateUrl);
if ($marketplace === '' || !$affiliate || isset($affiliate['user']) || isset($affiliate['pass']) || strlen($affiliateUrl) > 2048) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid HTTPS Amazon product or supported Amazon short link.']);
    exit;
}

try {
    $slug = bin2hex(random_bytes(12));
    $insert = $pdo->prepare('INSERT INTO manual_product_posts (slug, title, image_url, description, affiliate_url, marketplace) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([$slug, $title, $imageUrl, $description, $affiliateUrl, $marketplace]);
    echo json_encode([
        'success' => true,
        'message' => 'Manual product added.',
        'product_url' => dd_manual_product_url($slug),
    ]);
} catch (Throwable $error) {
    if ($createdImagePath !== '' && is_file($createdImagePath)) @unlink($createdImagePath);
    error_log('admin save manual product failed: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save this product. Confirm migration 012 is installed and try again.']);
}
