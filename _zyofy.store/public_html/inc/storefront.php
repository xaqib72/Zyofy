<?php

function dd_store_categories()
{
    return [
        'home-kitchen' => [
            'label' => 'Home & Kitchen',
            'short' => 'Home & Kitchen',
            'image' => 'https://images.unsplash.com/photo-1772475385491-f3cf64d4131a?auto=format&fit=crop&w=1000&q=82',
            'position' => 'center 54%',
            'keywords' => ['kitchen','cookware','pan','pot','kettle','vacuum','lamp','light','pillow','storage','organizer','shelf','curtain','towel','home','bath','bedroom','cleaner','mop','broom','faucet','chandelier'],
        ],
        'tech-audio' => [
            'label' => 'Tech & Audio',
            'short' => 'Tech & Audio',
            'image' => 'https://images.unsplash.com/photo-1659186198156-f0372a4fdc5e?auto=format&fit=crop&w=1000&q=82',
            'position' => 'center 50%',
            'keywords' => ['headphone','earbud','speaker','bluetooth','phone','iphone','android','tablet','laptop','computer','mini pc','camera','microphone','charger','cable','smartwatch','watch','radio','tv','monitor','keyboard','mouse','gaming'],
        ],
        'fitness' => [
            'label' => 'Health & Fitness',
            'short' => 'Fitness',
            'image' => 'https://images.unsplash.com/photo-1775993699105-4d18bcac7e54?auto=format&fit=crop&w=1000&q=82',
            'position' => 'center 52%',
            'keywords' => ['fitness','yoga','treadmill','walking pad','dumbbell','gym','exercise','workout','resistance','massage','sports','training','mat'],
        ],
        'outdoors' => [
            'label' => 'Outdoors & Travel',
            'short' => 'Outdoors',
            'image' => 'https://images.unsplash.com/photo-1771623605804-1794b6ae0b4e?auto=format&fit=crop&w=1000&q=82',
            'position' => 'center 50%',
            'keywords' => ['outdoor','camping','hiking','backpack','travel','garden','hose','saw','chainsaw','tent','lantern','patio','wind chime','broom','tool','portable'],
        ],
        'everyday-essentials' => [
            'label' => 'Everyday Essentials',
            'short' => 'Essentials',
            'image' => 'https://images.unsplash.com/photo-1778373620793-a369943bf9b3?auto=format&fit=crop&w=1000&q=82',
            'position' => 'center 54%',
            'keywords' => ['beauty','skincare','cream','lotion','shampoo','hair','toothbrush','soap','makeup','cleanser','bottle','food storage','vacuum sealer','bag','pet','daily','organizer','strap'],
        ],
    ];
}

function dd_store_category($slug)
{
    $all = dd_store_categories();
    return $all[$slug] ?? null;
}

function dd_store_category_clause($slug, array &$params, $prefix = 'cat')
{
    $category = dd_store_category($slug);
    if (!$category) {
        return '';
    }
    $parts = [];
    foreach ($category['keywords'] as $i => $keyword) {
        $key = ':' . $prefix . $i;
        $parts[] = "p.title LIKE $key";
        $params[$key] = '%' . $keyword . '%';
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '';
}

function dd_store_category_count(PDO $pdo, $slug)
{
    if (!dd_store_has_content_freshness($pdo)) return 0;
    $params = [];
    $clause = dd_store_category_clause($slug, $params, 'c');
    if ($clause === '') {
        return 0;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN marketplace_settings m ON m.marketplace=p.marketplace WHERE m.is_active=1 AND p.content_refreshed_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY AND $clause");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function dd_store_has_content_freshness(PDO $pdo)
{
    static $hasColumn = null;
    if ($hasColumn === null) {
        $statement = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'content_refreshed_at'");
        $hasColumn = (int) $statement->fetchColumn() > 0;
    }
    return $hasColumn;
}

function dd_store_has_created_at(PDO $pdo)
{
    static $hasColumn = null;
    if ($hasColumn === null) {
        $statement = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'created_at'");
        $hasColumn = (int) $statement->fetchColumn() > 0;
    }
    return $hasColumn;
}

function dd_storefront_product_query_args($q, $category, $page, $marketplace = '')
{
    $args = [];
    if ($q !== '') $args['q'] = $q;
    if ($category !== '') $args['category'] = $category;
    if ($marketplace !== '') $args['marketplace'] = $marketplace;
    if ($page > 1) $args['page'] = $page;
    return $args;
}

function dd_storefront_category_url($slug)
{
    return dd_url('/products/?' . http_build_query(['category' => (string) $slug]));
}
