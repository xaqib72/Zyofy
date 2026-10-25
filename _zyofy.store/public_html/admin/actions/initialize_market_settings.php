<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
require_once __DIR__ . '/guard.php';
dd_guard_admin_json('settings_csrf_token');
require_once __DIR__ . '/../../db.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_settings (
        marketplace VARCHAR(64) NOT NULL,
        country_label VARCHAR(64) NOT NULL,
        currency CHAR(3) NOT NULL,
        share_percent DECIMAL(5,2) NOT NULL DEFAULT 70.00,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        updated_by VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (marketplace),
        KEY idx_currency (currency)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $markets = [
        ['www.amazon.com', 'United States', 'USD', 90, 1],
        ['www.amazon.co.uk', 'United Kingdom', 'GBP', 70, 1],
        ['www.amazon.de', 'Germany', 'EUR', 70, 1],
        ['www.amazon.ca', 'Canada', 'CAD', 70, 1],
        ['www.amazon.es', 'Spain', 'EUR', 70, 1],
        ['www.amazon.fr', 'France', 'EUR', 70, 1],
        ['www.amazon.it', 'Italy', 'EUR', 70, 0],
        ['www.amazon.nl', 'Netherlands', 'EUR', 70, 0],
        ['www.amazon.se', 'Sweden', 'SEK', 70, 0],
        ['www.amazon.pl', 'Poland', 'PLN', 70, 0],
        ['www.amazon.com.mx', 'Mexico', 'MXN', 70, 0],
        ['www.amazon.co.jp', 'Japan', 'JPY', 70, 0],
        ['www.amazon.in', 'India', 'INR', 70, 0],
        ['www.amazon.com.au', 'Australia', 'AUD', 70, 0],
        ['www.amazon.com.br', 'Brazil', 'BRL', 70, 0],
        ['www.amazon.ae', 'UAE', 'AED', 70, 0],
        ['www.amazon.sa', 'Saudi Arabia', 'SAR', 70, 0],
        ['www.amazon.sg', 'Singapore', 'SGD', 70, 0],
    ];
    $insertMarket = $pdo->prepare('INSERT IGNORE INTO marketplace_settings (marketplace, country_label, currency, share_percent, is_active, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    foreach ($markets as $market) $insertMarket->execute([$market[0], $market[1], $market[2], $market[3], $market[4], 'admin-setup']);

    $pdo->exec("CREATE TABLE IF NOT EXISTS exchange_rates (
        currency CHAR(3) NOT NULL,
        rate_to_usd DECIMAL(18,6) NOT NULL,
        quoted_for DATE DEFAULT NULL,
        is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        updated_by VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (currency)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $rates = [
        ['USD', 1.000000, 1], ['EUR', 1.080000, 0], ['GBP', 1.270000, 0], ['CAD', 0.730000, 0],
        ['SEK', 0.095000, 0], ['PLN', 0.250000, 0], ['MXN', 0.055000, 0], ['JPY', 0.006400, 0],
        ['INR', 0.012000, 0], ['AUD', 0.660000, 0], ['BRL', 0.180000, 0], ['AED', 0.272000, 0],
        ['SAR', 0.266000, 0], ['SGD', 0.740000, 0],
    ];
    $insertRate = $pdo->prepare('INSERT IGNORE INTO exchange_rates (currency, rate_to_usd, quoted_for, is_confirmed, updated_by, updated_at) VALUES (?, ?, NULL, ?, ?, NOW())');
    foreach ($rates as $rate) $insertRate->execute([$rate[0], $rate[1], $rate[2], 'admin-setup']);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => 'Marketplace settings are ready. Existing settings were kept; Amazon USA is active by default when newly seeded.']);
} catch (Throwable $error) {
    error_log('initialize_market_settings.php: ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Could not initialize marketplace settings. Check the database permissions and server error log.']);
}
