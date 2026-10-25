<?php
require_once __DIR__ . '/../inc/whatsapp.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

function dd_whatsapp_webhook_reply($status, $body = '', $reason = 'response')
{
    dd_whatsapp_diag('webhook_exit', (int) $status >= 400 ? 'failure' : 'complete', $reason, ['http_status' => (int) $status]);
    http_response_code((int) $status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo (string) $body;
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$GLOBALS['dd_whatsapp_diag_enabled'] = true;
dd_whatsapp_diag_start();
dd_whatsapp_diag('webhook_entry', 'received', 'request_started', ['method' => $method]);
$config = dd_whatsapp_config();
if ($method === 'GET') {
    $mode = (string) ($_GET['hub.mode'] ?? $_GET['hub_mode'] ?? '');
    $token = (string) ($_GET['hub.verify_token'] ?? $_GET['hub_verify_token'] ?? '');
    $challenge = (string) ($_GET['hub.challenge'] ?? $_GET['hub_challenge'] ?? '');
    $expected = (string) ($config['verify_token'] ?? '');
    if ($mode === 'subscribe' && $expected !== '' && $token !== '' && hash_equals($expected, $token) && $challenge !== '') {
        dd_whatsapp_diag('webhook_verification', 'success', 'challenge_verified');
        dd_whatsapp_webhook_reply(200, $challenge, 'verification_success');
    }
    dd_whatsapp_diag('webhook_verification', 'failure', 'challenge_rejected');
    dd_whatsapp_webhook_reply(403, 'Verification failed.', 'verification_rejected');
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    dd_whatsapp_webhook_reply(405, 'Method not allowed.', 'unsupported_method');
}
if (!dd_whatsapp_configured(true)) dd_whatsapp_webhook_reply(503, 'WhatsApp webhook is not configured.', 'configuration_invalid');

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 1048576) {
    dd_whatsapp_diag('request_body', 'failure', 'body_too_large');
    dd_whatsapp_webhook_reply(413, 'Payload too large.', 'body_too_large');
}
$rawBody = file_get_contents('php://input', false, null, 0, 1048577);
if (!is_string($rawBody) || strlen($rawBody) > 1048576) {
    dd_whatsapp_diag('request_body', 'failure', 'body_missing_or_too_large');
    dd_whatsapp_webhook_reply(413, 'Payload too large.', 'body_missing_or_too_large');
}
if ($rawBody === '') {
    dd_whatsapp_diag('request_body', 'failure', 'body_empty');
    dd_whatsapp_webhook_reply(400, 'Malformed JSON.', 'body_empty');
}
dd_whatsapp_diag('request_body', 'success', 'body_read');
$signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, (string) $config['app_secret']);
if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
    dd_whatsapp_diag('signature', 'failure', $signature === '' ? 'signature_header_missing' : 'signature_mismatch', ['header_present' => $signature !== '', 'valid' => false]);
    dd_whatsapp_webhook_reply(401, 'Invalid signature.', $signature === '' ? 'signature_header_missing' : 'signature_mismatch');
}
dd_whatsapp_diag('signature', 'success', 'signature_valid', ['header_present' => true, 'valid' => true]);

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    dd_whatsapp_diag('payload_parse', 'failure', 'malformed_json');
    dd_whatsapp_webhook_reply(400, 'Malformed JSON.', 'malformed_json');
}
dd_whatsapp_diag('payload_parse', 'started', 'valid_json_received');
if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
    dd_whatsapp_diag('payload_parse', 'ignored', 'unexpected_event_object');
    dd_whatsapp_webhook_reply(200, 'EVENT_RECEIVED', 'unexpected_event_object');
}

$incoming = [];
$entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];
$sawStatus = false;
$sawMessageEvent = false;
foreach ($entries as $entry) {
    if (!is_array($entry)) continue;
    if (!empty($config['waba_id']) && (string) ($entry['id'] ?? '') !== (string) $config['waba_id']) {
        dd_whatsapp_diag('waba_filter', 'rejected', 'waba_id_mismatch');
        continue;
    }
    dd_whatsapp_diag('waba_filter', 'passed', empty($config['waba_id']) ? 'waba_filter_not_configured' : 'waba_id_match');
    $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
    foreach ($changes as $change) {
        if (!is_array($change)) continue;
        if (($change['field'] ?? '') !== 'messages') {
            $sawStatus = $sawStatus || !empty($change['value']['statuses']);
            continue;
        }
        $sawMessageEvent = true;
        $value = $change['value'] ?? [];
        if (!is_array($value) || ($value['messaging_product'] ?? '') !== 'whatsapp') continue;
        $sawStatus = $sawStatus || !empty($value['statuses']);
        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
        if ($phoneNumberId !== (string) $config['phone_number_id']) {
            dd_whatsapp_diag('phone_number_filter', 'rejected', 'phone_number_id_mismatch');
            continue;
        }
        dd_whatsapp_diag('phone_number_filter', 'passed', 'phone_number_id_match');
        $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
        foreach ($messages as $messageIndex => $message) {
            if (!is_array($message)) continue;
            $messageId = trim((string) ($message['id'] ?? ''));
            $sender = dd_whatsapp_normalize_phone($message['from'] ?? '');
            $messageType = (string) ($message['type'] ?? 'unknown');
            $hasMessageId = $messageId !== '' && strlen($messageId) <= 191;
            dd_whatsapp_diag('incoming_message', $hasMessageId && $sender !== null ? 'accepted' : 'rejected', $hasMessageId && $sender !== null ? 'message_envelope_valid' : 'message_envelope_invalid', ['message_index' => $messageIndex, 'message_type' => $messageType, 'message_id_present' => $hasMessageId, 'valid' => $sender !== null]);
            if (!$hasMessageId || $sender === null) continue;
            $text = ($messageType === 'text')
                ? (string) ($message['text']['body'] ?? '')
                : (($messageType === 'image') ? (string) ($message['image']['caption'] ?? '') : '');
            $incoming[] = ['id' => $messageId, 'sender' => $sender, 'text' => $text, 'type' => $messageType];
        }
    }
}

// Read receipts and status-only events are valid notifications; they need no work.
if (!$incoming) {
    $eventCategory = $sawStatus && !$sawMessageEvent ? 'status_only_notification' : (empty($entries) ? 'payload_has_no_entries' : 'no_eligible_incoming_messages');
    dd_whatsapp_diag('payload_parse', 'complete', $eventCategory);
    dd_whatsapp_webhook_reply(200, 'EVENT_RECEIVED', $eventCategory);
}
dd_whatsapp_diag('payload_parse', 'success', 'incoming_messages', ['message_count' => count($incoming)]);

try {
    require_once __DIR__ . '/../db.php';
    dd_whatsapp_diag('database_connect', 'success', 'database_ready');
} catch (Throwable $error) {
    dd_whatsapp_diag('database_connect', 'failure', 'database_bootstrap_exception', ['error_class' => get_class($error)]);
    throw $error;
}
$queue = [];
try {
    $pdo->exec('DELETE FROM whatsapp_messages WHERE received_at < UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT 500');
    $insert = $pdo->prepare("INSERT IGNORE INTO whatsapp_messages (message_id, state, attempts, received_at) VALUES (?, 'pending', 0, UTC_TIMESTAMP())");
    $recover = $pdo->prepare("UPDATE whatsapp_messages SET state = 'pending' WHERE message_id = ? AND state = 'processing' AND received_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE");
    $claim = $pdo->prepare("UPDATE whatsapp_messages SET state = 'processing', attempts = attempts + 1 WHERE message_id = ? AND state IN ('pending', 'failed')");
    $stateLookup = $pdo->prepare('SELECT state FROM whatsapp_messages WHERE message_id = ? LIMIT 1');
    foreach ($incoming as $messageIndex => $message) {
        $insert->execute([$message['id']]);
        $insertedNew = $insert->rowCount() === 1;
        $stateLookup->execute([$message['id']]);
        $previousState = (string) ($stateLookup->fetchColumn() ?: 'unknown');
        $recover->execute([$message['id']]);
        $wasRecovered = $recover->rowCount() === 1;
        $claim->execute([$message['id']]);
        if ($claim->rowCount() === 1) {
            $queue[] = $message;
            $dedupeCategory = $insertedNew ? 'new_message' : (($wasRecovered || $previousState === 'failed') ? 'failed_reclaimable' : 'claimed_pending');
            dd_whatsapp_diag('deduplication', 'queued', $dedupeCategory, ['message_index' => $messageIndex]);
        } else {
            $dedupeCategory = $previousState === 'processed' ? 'already_processed' : ($previousState === 'processing' ? 'currently_processing' : 'not_claimable');
            dd_whatsapp_diag('deduplication', 'skipped', $dedupeCategory, ['message_index' => $messageIndex]);
        }
    }
} catch (Throwable $error) {
    dd_whatsapp_diag('database_queue', 'failure', 'message_recording_exception', ['error_class' => get_class($error)]);
    error_log('[Zyofy WhatsApp] Could not record webhook message IDs; database or migration unavailable.');
    dd_whatsapp_webhook_reply(500, 'Webhook could not be recorded.', 'database_queue_failed');
}

// Acknowledge before product/API work. PHP-FPM can release the HTTP response while
// this request continues; output flushing is the shared-host fallback.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'EVENT_RECEIVED';
dd_whatsapp_diag('webhook_acknowledgement', 'sent', 'http_200_before_processing', ['http_status' => 200]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}
ignore_user_abort(true);
@set_time_limit(120);

try {
    $complete = $pdo->prepare("UPDATE whatsapp_messages SET state = ?, outcome = ?, completed_at = UTC_TIMESTAMP() WHERE message_id = ?");
} catch (Throwable $error) {
    dd_whatsapp_diag('database_queue', 'failure', 'message_state_statement_exception', ['error_class' => get_class($error)]);
    throw $error;
}
foreach ($queue as $messageIndex => $message) {
    try {
        dd_whatsapp_diag('message_processing', 'started', 'begin_message_handler', ['message_index' => $messageIndex]);
        $reply = dd_whatsapp_process_text($pdo, $message['sender'], $message['text']);
        if (is_array($reply) && isset($reply['wa_discovery']['candidates']) && is_array($reply['wa_discovery']['candidates'])) {
            $candidates = $reply['wa_discovery']['candidates'];
            dd_whatsapp_diag('reply_preparation', 'success', 'discovery_candidates_ready', ['candidate_count' => count($candidates)]);
            foreach ($candidates as $candidateIndex => $candidate) {
                try {
                    $number = $candidateIndex + 1;
                    $caption = dd_product_discovery_candidate_caption($candidate, $number);
                    $imageUrl = trim((string) ($candidate['image'] ?? ''));
                    if ($imageUrl !== '') {
                        try {
                            dd_whatsapp_send_image($message['sender'], $imageUrl, $caption);
                            continue;
                        } catch (Throwable $imageError) {
                            dd_whatsapp_diag('outbound_image', 'fallback', 'discovery_candidate_image_failed', ['message_index' => $candidateIndex, 'error_class' => get_class($imageError)]);
                        }
                    }
                    dd_whatsapp_send_text($message['sender'], $caption);
                } catch (Throwable $candidateError) {
                    dd_whatsapp_diag('product_discovery_reply', 'failure', 'candidate_delivery_failed', ['message_index' => $candidateIndex, 'error_class' => get_class($candidateError)]);
                }
            }
            $selectionPrompt = "Reply 1–" . count($candidates) . " to select a product.\n9 — None of these products match.";
            if (!empty($reply['wa_discovery']['seller_clue_present']) && empty($reply['wa_discovery']['seller_match_found'])) $selectionPrompt .= "\nNo verified seller match was found for your Sold by clue. Check the Sold by line on Amazon before choosing.";
            $selectionPrompt .= "\nChoices expire in 15 minutes.";
            dd_whatsapp_send_text($message['sender'], $selectionPrompt);
        } elseif (is_array($reply) && isset($reply['wa_products']) && is_array($reply['wa_products'])) {
            dd_whatsapp_diag('reply_preparation', 'success', 'product_replies_ready', ['message_count' => count($reply['wa_products'])]);
            foreach ($reply['wa_products'] as $productIndex => $productReply) {
                try {
                    if (empty($productReply['success'])) {
                        dd_whatsapp_send_text($message['sender'], (string) ($productReply['reply'] ?? 'Could not create that product post.'));
                        continue;
                    }
                    $postUrl = (string) $productReply['post_url'];
                    $caption = (string) ($productReply['caption'] ?? ($postUrl . "\n✨ Zyofy"));
                    $imageUrl = trim((string) ($productReply['image_url'] ?? ''));
                    if ($imageUrl !== '') {
                        try {
                            $captionLength = function_exists('mb_strlen') ? mb_strlen($caption, 'UTF-8') : strlen($caption);
                            if ($captionLength <= 1024) {
                                dd_whatsapp_send_image($message['sender'], $imageUrl, $caption);
                                continue;
                            }
                            dd_whatsapp_diag('outbound_image', 'fallback', 'caption_too_long_for_image_send', ['message_index' => $productIndex]);
                        } catch (Throwable $imageError) {
                            dd_whatsapp_diag('outbound_image', 'fallback', 'send_product_url_as_text', ['message_index' => $productIndex, 'error_class' => get_class($imageError)]);
                        }
                    } else {
                        dd_whatsapp_diag('outbound_image', 'unavailable', 'no_valid_product_image_url', ['message_index' => $productIndex]);
                    }
                    dd_whatsapp_send_text($message['sender'], $caption);
                } catch (Throwable $productError) {
                    dd_whatsapp_diag('product_reply', 'failure', 'individual_product_delivery_failed', ['message_index' => $productIndex, 'error_class' => get_class($productError)]);
                    error_log('[Zyofy WhatsApp] Individual product reply failed; ' . get_class($productError));
                }
            }
        } else {
            dd_whatsapp_diag('reply_preparation', 'success', 'text_reply_ready', ['message_index' => $messageIndex]);
            dd_whatsapp_send_text($message['sender'], (string) $reply);
        }
        $complete->execute(['processed', 'reply_sent', $message['id']]);
        dd_whatsapp_diag('message_processing', 'success', 'reply_sent_and_recorded', ['message_index' => $messageIndex]);
    } catch (Throwable $error) {
        dd_whatsapp_diag('message_processing', 'failure', 'processing_or_delivery_exception', ['message_index' => $messageIndex, 'error_class' => get_class($error)]);
        try {
            $complete->execute(['failed', 'processing_or_delivery_error', $message['id']]);
            dd_whatsapp_diag('database_queue', 'updated', 'message_marked_failed', ['message_index' => $messageIndex]);
        } catch (Throwable $completionError) {
            dd_whatsapp_diag('database_queue', 'failure', 'failed_state_update_exception', ['message_index' => $messageIndex, 'error_class' => get_class($completionError)]);
        }
        error_log('[Zyofy WhatsApp] Message processing or reply failed; message ref ' . substr(hash('sha256', $message['id']), 0, 12) . '; ' . get_class($error));
    }
}
