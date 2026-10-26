<?php
require_once __DIR__ . '/site.php';

if (!function_exists('dd_whatsapp_diag_start')) {
    function dd_whatsapp_diag_start()
    {
        static $requestId = null;
        if ($requestId === null) {
            try {
                $requestId = bin2hex(random_bytes(12));
            } catch (Throwable $error) {
                $requestId = str_replace('.', '', uniqid('', true));
            }
        }
        return $requestId;
    }
}

if (!function_exists('dd_whatsapp_diag')) {
    function dd_whatsapp_diag($stage, $outcome, $category, array $context = [])
    {
        try {
            if (empty($GLOBALS['dd_whatsapp_diag_enabled'])) return false;
            $requestId = dd_whatsapp_diag_start();
            $record = [
                'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'stage' => preg_replace('/[^a-z0-9_.-]/i', '_', (string) $stage),
                'outcome' => preg_replace('/[^a-z0-9_.-]/i', '_', (string) $outcome),
                'request_id' => $requestId,
                'category' => preg_replace('/[^a-z0-9_.-]/i', '_', (string) $category),
            ];
            foreach (['method', 'http_status', 'header_present', 'valid', 'message_index', 'message_count', 'message_type', 'message_id_present', 'meta_error_code', 'error_class'] as $key) {
                if (!array_key_exists($key, $context)) continue;
                $value = $context[$key];
                if ($key === 'http_status' || $key === 'message_index' || $key === 'message_count') {
                    $record[$key] = (int) $value;
                } elseif ($key === 'header_present' || $key === 'valid' || $key === 'message_id_present') {
                    $record[$key] = (bool) $value;
                } elseif ($key === 'message_type') {
                    $record[$key] = in_array($value, ['text', 'image', 'audio', 'video', 'document', 'location', 'contacts', 'sticker', 'interactive', 'button', 'unknown'], true) ? $value : 'unknown';
                } elseif ($key === 'meta_error_code') {
                    $record[$key] = preg_match('/^[0-9]{1,12}$/', (string) $value) ? (string) $value : 'unavailable';
                } elseif ($key === 'error_class') {
                    $record[$key] = preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,119}$/', (string) $value) ? (string) $value : 'Throwable';
                } else {
                    $record[$key] = preg_replace('/[^A-Z]/', '', strtoupper((string) $value));
                }
            }
            $json = json_encode($record, JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) return false;

            // Sibling to public_html; Hostinger PHP must be allowed to append here.
            $path = dirname(__DIR__, 2) . '/zyofy-whatsapp-diagnostics.log';
            if (is_link($path)) return false;
            $previousUmask = umask(0077);
            if (is_file($path)) @chmod($path, 0600);
            $written = @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
            if ($written !== false) @chmod($path, 0600);
            umask($previousUmask);
            return $written !== false;
        } catch (Throwable $error) {
            if (isset($previousUmask)) @umask($previousUmask);
            return false;
        }
    }
}

if (!function_exists('dd_whatsapp_config')) {
    function dd_whatsapp_config()
    {
        static $config = null;
        if ($config !== null) return $config;

        $file = getenv('ZYOFY_WHATSAPP_CONFIG');
        if (!is_string($file) || trim($file) === '') $file = dirname(__DIR__, 2) . '/zyofy-whatsapp-secrets.php';
        $fromFile = [];
        $fileLoaded = false;
        if (is_file($file) && is_readable($file)) {
            try {
                $loaded = require $file;
                if (is_array($loaded)) {
                    $fromFile = $loaded;
                    $fileLoaded = true;
                }
            } catch (Throwable $error) {
                error_log('[Pickwell WhatsApp] Secret config could not be loaded.');
                dd_whatsapp_diag('config_load', 'failure', 'secret_file_require_error', ['error_class' => get_class($error)]);
            }
        } else {
            dd_whatsapp_diag('config_load', 'failure', is_file($file) ? 'secret_file_unreadable' : 'secret_file_missing');
        }
        if ($fileLoaded) dd_whatsapp_diag('config_load', 'success', 'secret_file_array_loaded');
        elseif (is_file($file) && is_readable($file)) dd_whatsapp_diag('config_load', 'failure', 'secret_file_invalid_return');
        $config = [
            'phone_number_id' => (string) (getenv('ZYOFY_WA_PHONE_NUMBER_ID') ?: ($fromFile['phone_number_id'] ?? '')),
            'waba_id' => (string) (getenv('ZYOFY_WA_WABA_ID') ?: ($fromFile['waba_id'] ?? '')),
            'graph_api_version' => (string) (getenv('PICKWELL_WA_GRAPH_API_VERSION') ?: ($fromFile['graph_api_version'] ?? '')),
            'access_token' => (string) (getenv('PICKWELL_WA_ACCESS_TOKEN') ?: ($fromFile['access_token'] ?? '')),
            'app_secret' => (string) (getenv('PICKWELL_WA_APP_SECRET') ?: ($fromFile['app_secret'] ?? '')),
            'verify_token' => (string) (getenv('PICKWELL_WA_VERIFY_TOKEN') ?: ($fromFile['verify_token'] ?? '')),
            'business_number' => (string) (getenv('PICKWELL_WA_BUSINESS_NUMBER') ?: ($fromFile['business_number'] ?? '')),
        ];
        return $config;
    }
}

if (!function_exists('dd_whatsapp_configured')) {
    function dd_whatsapp_configured($forWebhook = false)
    {
        $config = dd_whatsapp_config();
        $required = ['phone_number_id', 'business_number', 'graph_api_version', 'access_token', 'verify_token'];
        if ($forWebhook) $required[] = 'app_secret';
        foreach ($required as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                dd_whatsapp_diag('config_validate', 'failure', 'required_setting_missing');
                return false;
            }
        }
        $valid = (bool) preg_match('/^v[0-9]+\.[0-9]+$/', (string) $config['graph_api_version'])
            && (bool) preg_match('/^[0-9]+$/', (string) $config['phone_number_id'])
            && dd_whatsapp_normalize_phone($config['business_number']) !== null;
        dd_whatsapp_diag('config_validate', $valid ? 'success' : 'failure', $valid ? 'required_settings_valid' : 'required_setting_format_invalid');
        return $valid;
    }
}

if (!function_exists('dd_whatsapp_normalize_phone')) {
    function dd_whatsapp_normalize_phone($phone)
    {
        $phone = trim((string) $phone);
        if ($phone === '' || preg_match('/[^0-9+().\s-]/', $phone)) return null;
        if (substr_count($phone, '+') > 1 || (strpos($phone, '+') !== false && strpos($phone, '+') !== 0)) return null;
        $digits = preg_replace('/\D+/', '', $phone);
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $digits)) return null;
        return '+' . $digits;
    }
}

if (!function_exists('dd_whatsapp_code_hash')) {
    function dd_whatsapp_code_hash($userId, $phone, $code)
    {
        $config = dd_whatsapp_config();
        $pepper = trim((string) ($config['app_secret'] ?? ''));
        if ($pepper === '') throw new RuntimeException('WhatsApp App Secret is not configured.');
        return hash_hmac('sha256', (int) $userId . "\0" . (string) $phone . "\0" . (string) $code, $pepper);
    }
}

if (!function_exists('dd_whatsapp_start_link')) {
    function dd_whatsapp_start_link(PDO $pdo, $userId, $phoneInput)
    {
        if (!dd_whatsapp_configured(true)) throw new RuntimeException('The Pickwell WhatsApp bot is not configured yet. Please contact the administrator.');
        $userId = (int) $userId;
        $phone = dd_whatsapp_normalize_phone($phoneInput);
        if ($userId < 1 || $phone === null) throw new InvalidArgumentException('Enter a valid international number, for example +923001234567.');

        $existingPhone = $pdo->prepare('SELECT user_id FROM whatsapp_user_phones WHERE phone_e164 = ? LIMIT 1');
        $existingPhone->execute([$phone]);
        $owner = $existingPhone->fetchColumn();
        if ($owner !== false && (int) $owner !== $userId) throw new InvalidArgumentException('This WhatsApp number is already linked to another Pickwell account. Contact support if you believe this is an error.');
        if ($owner !== false) throw new InvalidArgumentException('This WhatsApp number is already verified on your account.');

        $pdo->exec('DELETE FROM whatsapp_link_codes WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 500');
        $rate = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_link_codes WHERE (user_id = ? OR phone_e164 = ?) AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR');
        $rate->execute([$userId, $phone]);
        if ((int) $rate->fetchColumn() >= 3) throw new RuntimeException('Too many verification requests. Please try again in about an hour.');

        // Only the latest issued code remains valid for this account/number pair.
        $expire = $pdo->prepare('UPDATE whatsapp_link_codes SET expires_at = UTC_TIMESTAMP() WHERE consumed_at IS NULL AND (user_id = ? OR phone_e164 = ?)');
        $expire->execute([$userId, $phone]);
        $code = (string) random_int(10000000, 99999999);
        $hash = dd_whatsapp_code_hash($userId, $phone, $code);
        $insert = $pdo->prepare('INSERT INTO whatsapp_link_codes (user_id, phone_e164, code_hash, expires_at, attempts, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL 10 MINUTE, 0, UTC_TIMESTAMP())');
        $insert->execute([$userId, $phone, $hash]);
        return ['phone' => $phone, 'code' => $code];
    }
}

if (!function_exists('dd_whatsapp_verify_link_code')) {
    function dd_whatsapp_verify_link_code(PDO $pdo, $phone, $code)
    {
        dd_whatsapp_diag('verification', 'started', 'link_code_check');
        $phone = dd_whatsapp_normalize_phone($phone);
        if ($phone === null || !preg_match('/^[0-9]{8}$/', (string) $code)) {
            dd_whatsapp_diag('verification', 'rejected', 'invalid_phone_or_code_format');
            return ['status' => 'invalid'];
        }
        $find = $pdo->prepare('SELECT id, user_id, code_hash, attempts FROM whatsapp_link_codes WHERE phone_e164 = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP() AND attempts < 5 ORDER BY id DESC LIMIT 1');
        $find->execute([$phone]);
        $pending = $find->fetch(PDO::FETCH_ASSOC);
        if (!$pending) {
            dd_whatsapp_diag('verification', 'rejected', 'no_active_code_for_sender');
            return ['status' => 'expired'];
        }

        $expected = dd_whatsapp_code_hash((int) $pending['user_id'], $phone, (string) $code);
        if (!hash_equals((string) $pending['code_hash'], $expected)) {
            $wrong = $pdo->prepare('UPDATE whatsapp_link_codes SET attempts = attempts + 1 WHERE id = ? AND attempts < 5');
            $wrong->execute([(int) $pending['id']]);
            dd_whatsapp_diag('verification', 'rejected', 'code_mismatch');
            return ['status' => 'invalid'];
        }

        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id, user_id FROM whatsapp_link_codes WHERE id = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP() AND attempts < 5 FOR UPDATE');
            $lock->execute([(int) $pending['id']]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$locked) {
                $pdo->rollBack();
                dd_whatsapp_diag('verification', 'rejected', 'code_expired_or_consumed_during_check');
                return ['status' => 'expired'];
            }
            $other = $pdo->prepare('SELECT user_id FROM whatsapp_user_phones WHERE phone_e164 = ? LIMIT 1 FOR UPDATE');
            $other->execute([$phone]);
            $phoneOwner = $other->fetchColumn();
            if ($phoneOwner !== false && (int) $phoneOwner !== (int) $locked['user_id']) {
                $consume = $pdo->prepare('UPDATE whatsapp_link_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = ?');
                $consume->execute([(int) $locked['id']]);
                $pdo->commit();
                dd_whatsapp_diag('verification', 'rejected', 'phone_linked_to_another_account');
                return ['status' => 'already_linked'];
            }
            $user = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $user->execute([(int) $locked['user_id']]);
            if (!$user->fetchColumn()) {
                $pdo->rollBack();
                dd_whatsapp_diag('verification', 'rejected', 'account_not_found');
                return ['status' => 'account_missing'];
            }
            $save = $pdo->prepare('INSERT INTO whatsapp_user_phones (user_id, phone_e164, verified_at, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE phone_e164 = VALUES(phone_e164), verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()');
            $save->execute([(int) $locked['user_id'], $phone]);
            $consume = $pdo->prepare('UPDATE whatsapp_link_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = ?');
            $consume->execute([(int) $locked['id']]);
            $pdo->commit();
            dd_whatsapp_diag('verification', 'success', 'phone_linked');
            return ['status' => 'verified'];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && strpos((string) $error->getCode(), '23') === 0) {
                dd_whatsapp_diag('verification', 'rejected', 'phone_link_conflict');
                return ['status' => 'already_linked'];
            }
            dd_whatsapp_diag('verification', 'failure', 'database_or_verification_exception', ['error_class' => get_class($error)]);
            throw $error;
        }
    }
}

if (!function_exists('dd_whatsapp_send_text')) {
    function dd_whatsapp_send_text($to, $message)
    {
        dd_whatsapp_diag('outbound_send', 'started', 'graph_api_attempt');
        if (!dd_whatsapp_configured(false)) {
            dd_whatsapp_diag('outbound_send', 'failure', 'required_settings_invalid');
            throw new RuntimeException('WhatsApp Cloud API credentials are incomplete.');
        }
        if (!function_exists('curl_init')) {
            dd_whatsapp_diag('outbound_send', 'failure', 'curl_extension_missing');
            throw new RuntimeException('PHP cURL is required to send WhatsApp messages.');
        }
        $config = dd_whatsapp_config();
        $url = 'https://graph.facebook.com/' . $config['graph_api_version'] . '/' . rawurlencode($config['phone_number_id']) . '/messages';
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', (string) $to),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => (string) $message],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['access_token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($response === false || $status < 200 || $status >= 300 || empty($decoded['messages'][0]['id'])) {
            $metaCode = (string) ($decoded['error']['code'] ?? '');
            $safeMetaCode = preg_match('/^[0-9]{1,12}$/', $metaCode) ? $metaCode : 'unavailable';
            $category = $response === false ? 'transport_error' : (($status < 200 || $status >= 300) ? 'http_error' : 'unexpected_response');
            dd_whatsapp_diag('outbound_send', 'failure', $category, ['http_status' => $status, 'meta_error_code' => $safeMetaCode]);
            error_log('[Pickwell WhatsApp] Graph API send failed; HTTP ' . $status . '; Meta code ' . $safeMetaCode . ($curlError !== '' ? '; transport error' : ''));
            throw new RuntimeException('WhatsApp message delivery failed.');
        }
        dd_whatsapp_diag('outbound_send', 'success', 'graph_api_accepted', ['http_status' => $status]);
        return (string) $decoded['messages'][0]['id'];
    }
}

if (!function_exists('dd_whatsapp_send_image')) {
    function dd_whatsapp_send_image($to, $imageUrl, $caption)
    {
        dd_whatsapp_diag('outbound_image', 'started', 'graph_api_image_attempt');
        if (!dd_whatsapp_configured(false)) throw new RuntimeException('WhatsApp Cloud API credentials are incomplete.');
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required to send WhatsApp messages.');
        $config = dd_whatsapp_config();
        $url = 'https://graph.facebook.com/' . $config['graph_api_version'] . '/' . rawurlencode($config['phone_number_id']) . '/messages';
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', (string) $to),
            'type' => 'image',
            'image' => ['link' => (string) $imageUrl, 'caption' => (string) $caption],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['access_token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($response === false || $status < 200 || $status >= 300 || empty($decoded['messages'][0]['id'])) {
            $metaCode = (string) ($decoded['error']['code'] ?? '');
            $safeMetaCode = preg_match('/^[0-9]{1,12}$/', $metaCode) ? $metaCode : 'unavailable';
            $category = $response === false ? 'transport_error' : (($status < 200 || $status >= 300) ? 'http_error' : 'unexpected_response');
            dd_whatsapp_diag('outbound_image', 'failure', $category, ['http_status' => $status, 'meta_error_code' => $safeMetaCode]);
            error_log('[Pickwell WhatsApp] Graph API image send failed; HTTP ' . $status . '; Meta code ' . $safeMetaCode . ($curlError !== '' ? '; transport error' : ''));
            throw new RuntimeException('WhatsApp image delivery failed.');
        }
        dd_whatsapp_diag('outbound_image', 'success', 'graph_api_accepted', ['http_status' => $status]);
        return (string) $decoded['messages'][0]['id'];
    }
}

if (!function_exists('dd_whatsapp_product_image_url')) {
    function dd_whatsapp_product_image_url(PDO $pdo, $asin, $marketplace)
    {
        $statement = $pdo->prepare('SELECT image FROM products WHERE asin = ? AND marketplace = ? ORDER BY id DESC LIMIT 1');
        $statement->execute([(string) $asin, (string) $marketplace]);
        $storedImage = trim((string) ($statement->fetchColumn() ?: ''));
        $imageUrl = function_exists('dd_amazon_image') ? dd_amazon_image($storedImage) : $storedImage;
        $parts = is_string($imageUrl) ? parse_url($imageUrl) : false;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !empty($parts['user']) || !empty($parts['pass'])) return '';
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $amazonImageHost = $host === 'm.media-amazon.com'
            || $host === 'images-amazon.com'
            || $host === 'images.amazon.com'
            || (bool) preg_match('/(^|\.)ssl-images-amazon\.com$/', $host);
        $extension = strtolower(pathinfo((string) ($parts['path'] ?? ''), PATHINFO_EXTENSION));
        if (!$amazonImageHost || !in_array($extension, ['jpg', 'jpeg', 'png'], true)) return '';
        return $imageUrl;
    }
}

if (!function_exists('dd_whatsapp_product_input_category')) {
    function dd_whatsapp_product_input_category(array $parsed)
    {
        $reason = strtolower((string) ($parsed['error'] ?? ''));
        if ($reason === 'marketplace_conflict') return 'marketplace_conflict';
        if (!empty($parsed['ambiguous'])) return 'marketplace_ambiguity';
        if (strpos($reason, 'http_404') !== false) return 'short_link_http_404';
        if (in_array($reason, ['invalid_url', 'unsupported_host', 'unsafe_redirect', 'unsafe_address', 'unsafe_final_url'], true)) return 'short_link_' . $reason;
        if (in_array($reason, ['redirect_loop', 'too_many_redirects'], true)) return 'short_link_' . $reason;
        if (in_array($reason, ['http_error', 'dns_failure', 'transport_error', 'curl_unavailable'], true)) return 'short_link_' . $reason;
        if (strpos($reason, 'timeout') !== false) return 'short_link_timeout';
        if (strpos($reason, 'unsafe') !== false || strpos($reason, 'invalid_url') !== false) return 'short_link_unsafe_or_invalid';
        if ($reason !== '') return 'short_link_resolution_failed';
        return empty($parsed['asin']) ? 'asin_extraction_failed' : 'marketplace_missing';
    }
}

if (!function_exists('dd_whatsapp_product_input_problem')) {
    function dd_whatsapp_product_input_problem(array $parsed)
    {
        $reason = (string) ($parsed['error'] ?? '');
        if (!empty($parsed['ambiguous']) && $reason === 'marketplace_conflict') {
            return 'The country in this Amazon link conflicts with the country written in the message. Please confirm the correct marketplace.';
        }
        if (!empty($parsed['ambiguous'])) return 'This product input matches more than one marketplace. Add the correct country next to that product.';
        if ($reason !== '') return 'I could not safely resolve that Amazon link. Send the full country-specific Amazon product URL or the ASIN with its marketplace.';
        return 'I could not identify a valid ASIN. Send an Amazon product URL or an ASIN with its marketplace.';
    }
}

if (!function_exists('dd_whatsapp_generate_for_user')) {
    function dd_whatsapp_generate_for_user(PDO $pdo, array $user, $message, $parsedOverride = null)
    {
        require_once __DIR__ . '/product-share.php';
        require_once __DIR__ . '/amazon-products.php';
        $message = trim((string) $message);
        if ($message === '' || strlen($message) > 16384) {
            dd_whatsapp_diag('product_request', 'rejected', 'empty_or_oversized_input');
            throw new InvalidArgumentException('Please send one Amazon product URL or an ASIN with its marketplace.');
        }
        if (is_array($parsedOverride)) {
            $parsed = $parsedOverride;
        } else {
            dd_whatsapp_diag('product_request', 'started', 'parse_product_input');
            try {
                $parsed = dd_extract_product_input($pdo, $message);
            } catch (Throwable $error) {
                dd_whatsapp_diag('product_request', 'failure', 'input_parser_exception', ['error_class' => get_class($error)]);
                throw $error;
            }
        }
        if (!empty($parsed['ambiguous'])) {
            dd_whatsapp_diag('marketplace_check', 'failure', 'ambiguous_marketplace');
            throw new InvalidArgumentException('I found more than one marketplace. Send one country-specific Amazon URL or add a marketplace such as UK or USA.');
        }
        $asin = strtoupper((string) ($parsed['asin'] ?? ''));
        $marketplace = trim((string) ($parsed['marketplace'] ?? ''));
        if (!empty($parsed['error']) && (!$asin || !$marketplace)) {
            dd_whatsapp_diag('product_request', 'rejected', dd_whatsapp_product_input_category($parsed));
            throw new InvalidArgumentException(dd_whatsapp_product_input_problem($parsed));
        }
        if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) {
            dd_whatsapp_diag('product_request', 'rejected', 'asin_invalid');
            throw new InvalidArgumentException('I could not find a 10-character ASIN. Send an Amazon product URL or ASIN with marketplace.');
        }
        dd_whatsapp_diag('product_request', 'success', 'product_input_parsed');

        $userId = (int) ($user['id'] ?? 0);
        $email = trim((string) ($user['email'] ?? ''));
        if ($userId < 1 || $email === '') {
            dd_whatsapp_diag('account_linking', 'failure', 'contributor_account_invalid');
            throw new RuntimeException('The contributor account could not be loaded.');
        }
        if ($marketplace === '') {
            dd_whatsapp_diag('marketplace_check', 'started', 'infer_assigned_marketplace');
            $available = $pdo->prepare('SELECT DISTINCT ut.marketplace FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\'');
            $available->execute([$email]);
            $marketplaces = $available->fetchAll(PDO::FETCH_COLUMN);
            if (count($marketplaces) === 1) $marketplace = (string) $marketplaces[0];
            elseif (count($marketplaces) > 1) {
                dd_whatsapp_diag('marketplace_check', 'failure', 'multiple_assigned_marketplaces');
                throw new InvalidArgumentException('This ASIN does not identify a marketplace. Send an Amazon product URL or include a marketplace such as UK or USA.');
            } else {
                dd_whatsapp_diag('marketplace_check', 'failure', 'no_active_assigned_marketplace');
                throw new RuntimeException('Your Pickwell account has no active marketplace tracking ID yet. Please ask the administrator to assign one.');
            }
        }
        dd_whatsapp_diag('marketplace_check', 'success', 'marketplace_resolved');

        dd_whatsapp_diag('tracking_id_check', 'started', 'find_assigned_tag');
        $tagQuery = $pdo->prepare('SELECT ut.tracking_id, ut.marketplace FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND LOWER(TRIM(ut.marketplace)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\' ORDER BY ut.id ASC LIMIT 1');
        $tagQuery->execute([$email, $marketplace]);
        $tag = $tagQuery->fetch(PDO::FETCH_ASSOC);
        if (!$tag) {
            dd_whatsapp_diag('tracking_id_check', 'failure', 'no_active_tag_for_marketplace');
            throw new RuntimeException('Your account has no active tracking ID for this Amazon marketplace. Ask the administrator to assign one.');
        }
        dd_whatsapp_diag('tracking_id_check', 'success', 'active_tag_found');

        dd_whatsapp_diag('product_lookup', 'started', 'amazon_api_or_fresh_cache');
        try {
            $post = dd_create_product_share($pdo, $userId, $email, $asin, $marketplace, $tag, true);
        } catch (Throwable $error) {
            dd_whatsapp_diag('product_lookup', 'failure', 'product_or_amazon_lookup_exception', ['error_class' => get_class($error)]);
            throw $error;
        }
        dd_whatsapp_diag('product_lookup', 'success', 'product_share_created');
        $post['image_url'] = dd_whatsapp_product_image_url($pdo, $asin, $marketplace);
        return $post;
    }
}

if (!function_exists('dd_whatsapp_extract_product_inputs')) {
    function dd_whatsapp_extract_product_inputs(PDO $pdo, $message)
    {
        require_once __DIR__ . '/product-inputs.php';
        return dd_extract_product_inputs($pdo, $message);
    }
}

if (!function_exists('dd_whatsapp_product_caption')) {
    function dd_whatsapp_product_caption(PDO $pdo, array $post, array $input)
    {
        require_once __DIR__ . '/product-inputs.php';
        $context = (string) ($input['notes_context'] ?? ($input['context'] ?? ''));
        $notes = dd_extract_product_notes($context);
        $notes = array_values(array_filter($notes, static function ($line) { return trim((string) $line) !== '✨ Pickwell'; }));
        $lines = array_merge([(string) $post['post_url']], $notes, ['✨ Pickwell']);
        $caption = implode("\n", $lines);
        return $caption;
    }
}

if (!function_exists('dd_whatsapp_generate_products_for_user')) {
    function dd_whatsapp_generate_products_for_user(PDO $pdo, array $user, $message)
    {
        try {
            $inputs = dd_whatsapp_extract_product_inputs($pdo, $message);
        } catch (Throwable $error) {
            return [['success' => false, 'reply' => $error->getMessage()]];
        }
        if (!$inputs) {
            try {
                $post = dd_whatsapp_generate_for_user($pdo, $user, $message);
                return [['success' => true, 'post_url' => $post['post_url'], 'caption' => dd_whatsapp_product_caption($pdo, $post, ['context' => $message, 'notes_context' => $message, 'type' => 'url']), 'image_url' => $post['image_url'] ?? '', 'asin' => $post['asin'], 'marketplace' => $post['marketplace']]];
            } catch (Throwable $error) {
                return [['success' => false, 'reply' => dd_whatsapp_product_error_reply($error, $user)]];
            }
        }

        $results = [];
        $generated = [];
        foreach ($inputs as $input) {
            $parsed = $input['parsed'];
            if (!empty($parsed['ambiguous']) || empty($parsed['asin'])) {
                dd_whatsapp_diag('product_input', 'rejected', dd_whatsapp_product_input_category($parsed));
                $messageText = dd_whatsapp_product_input_problem($parsed);
                $results[] = ['success' => false, 'reply' => $messageText];
                continue;
            }
            if (!empty($parsed['error'])) dd_whatsapp_diag('short_link_resolution', 'recovered', 'independent_product_clues_available');
            try {
                $post = dd_whatsapp_generate_for_user($pdo, $user, $input['raw'], $parsed);
                $key = strtoupper((string) $post['asin']) . '|' . strtolower((string) $post['marketplace']);
                if (isset($generated[$key])) continue;
                $generated[$key] = true;
                $results[] = ['success' => true, 'post_url' => $post['post_url'], 'caption' => dd_whatsapp_product_caption($pdo, $post, $input), 'image_url' => $post['image_url'] ?? '', 'asin' => $post['asin'], 'marketplace' => $post['marketplace']];
            } catch (Throwable $error) {
                $results[] = ['success' => false, 'reply' => dd_whatsapp_product_error_reply($error, $user)];
            }
        }
        return $results;
    }
}

if (!function_exists('dd_whatsapp_product_error_reply')) {
    function dd_whatsapp_product_error_reply(Throwable $error, array $user)
    {
        if ($error instanceof InvalidArgumentException) return $error->getMessage();
        error_log('[Pickwell WhatsApp] Product-link request failed; account ' . (int) ($user['id'] ?? 0) . '; ' . get_class($error));
        if ($error instanceof RuntimeException && preg_match('/^(Your account|Your Pickwell|Your Amazon|This ASIN)/', $error->getMessage())) return $error->getMessage();
        if ($error instanceof RuntimeException && stripos($error->getMessage(), 'Amazon') !== false) {
            return 'Amazon could not confirm this product for that marketplace. Check the product country and the Amazon API connection, then send the full country-specific Amazon URL or ASIN with its marketplace.';
        }
        return 'I could not create that product post right now. Check the Amazon URL or ASIN and marketplace, then try again or contact Pickwell support.';
    }
}

if (!function_exists('dd_whatsapp_product_discovery')) {
    function dd_whatsapp_product_discovery(PDO $pdo, array $user, $sender, $message, $apiSearch = null, $apiItemLookup = null)
    {
        require_once __DIR__ . '/product-discovery.php';
        $scope = dd_product_discovery_scope_hash('whatsapp', (int) $user['id'], (string) $sender);
        $text = trim((string) $message);
        if (preg_match('/^([1-9])$/', $text, $choice)) {
            $pending = dd_product_discovery_pending($pdo, 'whatsapp', (int) $user['id'], $scope);
            if (!$pending) return 'That product choice has expired or is no longer active. Send the keyword, seller, or store again to start a fresh search.';
            if ($choice[1] === '9') {
                dd_product_discovery_delete_selection($pdo, $pending['token']);
                dd_whatsapp_diag('product_selection', 'rejected', 'all_candidates_rejected');
                return 'Got it — I cleared those choices and did not create a product post. Send a better keyword, seller, or store clue to search again.';
            }
            $candidate = dd_product_discovery_selected_candidate($pending, $choice[1]);
            if (!$candidate) {
                return 'That number is not in the current product list. Reply with one of the displayed numbers or send a fresh search.';
            }
            dd_whatsapp_diag('product_selection', 'accepted', 'owner_scoped_candidate_selected');
            try {
                $parsed = ['asin' => $candidate['asin'], 'marketplace' => (string) $candidate['marketplace'], 'ambiguous' => false, 'error' => ''];
                $post = dd_whatsapp_generate_for_user($pdo, $user, $parsed['asin'] . ' ' . $parsed['marketplace'], $parsed);
                dd_product_discovery_delete_selection($pdo, $pending['token']);
                return ['wa_products' => [[
                    'success' => true,
                    'post_url' => $post['post_url'],
                    'caption' => dd_whatsapp_product_caption($pdo, $post, ['notes_context' => '']),
                    'image_url' => $post['image_url'] ?? '',
                    'asin' => $post['asin'],
                    'marketplace' => $post['marketplace'],
                ]]];
            } catch (Throwable $error) {
                return ['wa_products' => [['success' => false, 'reply' => dd_whatsapp_product_error_reply($error, $user)]]];
            }
        }

        if (dd_product_discovery_has_product_identifier($text)) return null;
        $criteria = dd_product_discovery_parse($pdo, $text);
        if (empty($criteria['valid'])) return (string) ($criteria['error'] ?? 'Send a product keyword, seller, or store name.');
        $marketplace = (string) ($criteria['marketplace'] ?? '');
        if ($marketplace === '') {
            $available = $pdo->prepare('SELECT DISTINCT ut.marketplace FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND ms.is_active = 1 AND ut.tracking_id IS NOT NULL AND ut.tracking_id <> \'\'');
            $available->execute([(string) $user['email']]);
            $markets = $available->fetchAll(PDO::FETCH_COLUMN);
            if (count($markets) !== 1) return 'Add one marketplace to the search (for example, “Marketplace: USA”) so I search the right Amazon store.';
            $marketplace = (string) $markets[0];
        }
        if (!dd_product_discovery_has_active_tag($pdo, (string) $user['email'], $marketplace)) {
            return 'Your Pickwell account has no active tracking ID for that Amazon marketplace yet. Ask the administrator to assign one, or search a marketplace you already have assigned.';
        }
        try {
            $result = dd_product_discovery_search($pdo, $criteria, $marketplace, $apiSearch, $apiItemLookup);
            if (!$result['candidates']) {
                $sellerNote = trim((string) ($criteria['seller'] ?? '')) !== '' ? ' No verified seller match was found for your Sold by clue.' : '';
                return 'No reliable product match was found.' . $sellerNote . ' Try a more specific keyword, seller, or store clue, or send the Amazon product URL or ASIN.';
            }
            $token = dd_product_discovery_store_selection($pdo, 'whatsapp', (int) $user['id'], $scope, $result['candidates']);
            dd_whatsapp_diag('product_discovery', 'success', 'candidate_list_saved', ['candidate_count' => count($result['candidates']), 'source' => $result['source']]);
            return ['wa_discovery' => ['token' => $token, 'candidates' => $result['candidates'], 'seller_match_found' => !empty($result['seller_match_found']), 'seller_clue_present' => trim((string) ($criteria['seller'] ?? '')) !== '']];
        } catch (Throwable $error) {
            dd_whatsapp_diag('product_discovery', 'failure', 'search_or_selection_storage_failed', ['error_class' => get_class($error)]);
            if ($error instanceof InvalidArgumentException) return $error->getMessage();
            return 'Product discovery is temporarily unavailable. Send the Amazon product URL or ASIN instead, and I will use the existing link generator.';
        }
    }
}

if (!function_exists('dd_whatsapp_process_text')) {
    function dd_whatsapp_process_text(PDO $pdo, $sender, $message)
    {
        $sender = dd_whatsapp_normalize_phone($sender);
        if ($sender === null) {
            dd_whatsapp_diag('account_linking', 'rejected', 'sender_number_invalid');
            return 'I could not verify this WhatsApp number. Please contact Pickwell support.';
        }
        $text = trim((string) $message);
        if (preg_match('/^LINK\s+([0-9]{8})$/i', $text, $match)) {
            dd_whatsapp_diag('account_linking', 'started', 'verification_command_received');
            $result = dd_whatsapp_verify_link_code($pdo, $sender, $match[1]);
            dd_whatsapp_diag('account_linking', $result['status'] === 'verified' ? 'success' : 'rejected', 'verification_result_' . $result['status']);
            if ($result['status'] === 'verified') return 'Your WhatsApp number is now verified with your Pickwell account. Send an Amazon product URL or ASIN to create your product post.';
            if ($result['status'] === 'already_linked') return 'This number could not be linked because it is already verified on a different Pickwell account. Contact Pickwell support if you believe this is an error.';
            if ($result['status'] === 'account_missing') return 'We could not complete account linking. Sign in to Pickwell and start the WhatsApp verification again.';
            return 'That verification code is invalid or expired. Sign in to Pickwell, request a new code, and send LINK followed by the new code.';
        }

        $account = $pdo->prepare('SELECT u.id, u.name, u.email FROM whatsapp_user_phones p JOIN users u ON u.id = p.user_id WHERE p.phone_e164 = ? LIMIT 1');
        dd_whatsapp_diag('account_linking', 'started', 'lookup_verified_phone_mapping');
        try {
            $account->execute([$sender]);
        } catch (Throwable $error) {
            dd_whatsapp_diag('account_linking', 'failure', 'phone_mapping_query_exception', ['error_class' => get_class($error)]);
            throw $error;
        }
        $user = $account->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            dd_whatsapp_diag('account_linking', 'not_linked', 'no_verified_phone_mapping');
            $linkUrl = dd_url('/whatsapp-link/');
            return 'To use Pickwell WhatsApp link generation, sign in to your Pickwell account and verify this number first: ' . $linkUrl . ' . Then send the LINK code shown on that page here.';
        }
        dd_whatsapp_diag('account_linking', 'success', 'verified_user_found');
        if (strtolower($text) === 'help') return 'Send an Amazon URL or ASIN, or search with a product keyword, Sold by, or Store clue. Add a marketplace such as USA or UK if needed.';
        if ($text === '') {
            dd_whatsapp_diag('product_request', 'rejected', 'image_or_message_has_no_identifying_text');
            return 'I could not identify an exact product from that image alone. Please send an Amazon product URL or ASIN with its marketplace.';
        }
        require_once __DIR__ . '/product-discovery.php';
        $discovery = dd_whatsapp_product_discovery($pdo, $user, $sender, $text);
        if ($discovery !== null) return $discovery;
        return ['wa_products' => dd_whatsapp_generate_products_for_user($pdo, $user, $text)];
    }
}
