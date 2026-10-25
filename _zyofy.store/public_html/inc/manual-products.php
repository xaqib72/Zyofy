<?php

function dd_manual_product_url($slug)
{
    return dd_url('/product.php?manual=' . rawurlencode((string) $slug));
}

function dd_manual_product_from_slug(PDO $pdo, $slug)
{
    if (!preg_match('/^[a-f0-9]{24}$/', (string) $slug)) return null;
    $statement = $pdo->prepare('SELECT slug, title, image_url, description, affiliate_url, marketplace, created_at FROM manual_product_posts WHERE slug = ? LIMIT 1');
    $statement->execute([(string) $slug]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return [
        'manual_slug' => $row['slug'],
        'title' => $row['title'],
        'image' => $row['image_url'],
        'description' => $row['description'],
        'affiliate_link' => $row['affiliate_url'],
        'marketplace' => $row['marketplace'],
        'created_at' => $row['created_at'],
        'price' => null,
        'price_refreshed_at' => null,
    ];
}

function dd_manual_amazon_marketplace($url)
{
    $parts = parse_url((string) $url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') return '';
    $host = strtolower((string) ($parts['host'] ?? ''));
    $supported = [
        'amazon.com', 'amazon.ca', 'amazon.com.mx', 'amazon.com.br', 'amazon.co.uk',
        'amazon.de', 'amazon.fr', 'amazon.it', 'amazon.es', 'amazon.nl', 'amazon.se',
        'amazon.pl', 'amazon.com.au', 'amazon.co.jp', 'amazon.in', 'amazon.sg',
        'amazon.ae', 'amazon.sa', 'amazon.com.tr',
    ];
    foreach ($supported as $domain) {
        if ($host === $domain || $host === 'www.' . $domain) return $host;
    }
    // Amazon's official short-link domains do not reveal a marketplace until
    // they redirect, so keep the generic Amazon label and preserve the URL.
    if (in_array($host, ['a.co', 'amzn.to', 'amzn.eu', 'amzn.asia', 'amzn.in', 'link.amazon'], true)) return 'Amazon';
    return '';
}
