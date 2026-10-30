<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function bot_platform_message(int $messageId): ?array {
    $row = one('SELECT m.id, m.chat_id, m.user_id, m.body, m.created_at, c.kind, c.title, u.name, u.username, CASE WHEN b.bot_user_id IS NULL AND s.user_id IS NULL THEN 0 ELSE 1 END AS is_bot, f.message_id AS file_id, f.original_name AS file_name, f.mime AS file_mime, f.size AS file_size, k.markup_json, mk.kind AS media_kind, v.duration AS voice_duration FROM messages m JOIN chats c ON c.id = m.chat_id JOIN users u ON u.id = m.user_id LEFT JOIN bot_accounts b ON b.bot_user_id = m.user_id LEFT JOIN system_bots s ON s.user_id = m.user_id LEFT JOIN message_files f ON f.message_id = m.id LEFT JOIN bot_message_markup k ON k.message_id = m.id LEFT JOIN bot_media_kinds mk ON mk.message_id = m.id LEFT JOIN message_voice v ON v.message_id = m.id WHERE m.id = ?', [$messageId]);
    if (!$row) return null;
    $result = [
        'message_id' => (int)$row['id'],
        'from' => ['id' => (int)$row['user_id'], 'is_bot' => (bool)$row['is_bot'], 'first_name' => $row['name'], 'username' => $row['username']],
        'chat' => ['id' => (int)$row['chat_id'], 'type' => $row['kind'] === 'direct' ? 'private' : $row['kind'], 'title' => $row['title']],
        'date' => strtotime($row['created_at'] . ' UTC'),
    ];
    if ($row['file_id'] !== null) {
        $file = ['file_id' => (string)$row['file_id'], 'file_unique_id' => substr(hash('sha256', (string)$row['file_id']), 0, 32), 'file_size' => (int)$row['file_size']];
        $kind = $row['media_kind'] ?: ($row['voice_duration'] !== null ? 'voice' : (str_starts_with($row['file_mime'], 'image/') ? 'photo' : 'document'));
        if ($kind === 'photo') $result['photo'] = [$file + ['width' => 0, 'height' => 0]];
        elseif ($kind === 'voice') $result['voice'] = $file + ['duration' => (int)($row['voice_duration'] ?? 0), 'mime_type' => $row['file_mime']];
        elseif (in_array($kind, ['audio', 'video', 'animation'], true)) $result[$kind] = $file + ['file_name' => $row['file_name'], 'mime_type' => $row['file_mime']];
        else $result['document'] = $file + ['file_name' => $row['file_name'], 'mime_type' => $row['file_mime']];
        $result['caption'] = $row['body'];
    } else $result['text'] = $row['body'];
    if ($row['markup_json'] !== null) $result['reply_markup'] = json_decode($row['markup_json'], true);
    return $result;
}

function bot_platform_markup(mixed $input): ?string {
    if ($input === null || $input === '') return null;
    if (is_string($input)) $input = json_decode($input, true);
    if (!is_array($input)) throw new DomainException('Некорректная клавиатура.');
    if (array_key_exists('remove_keyboard', $input)) {
        if ($input['remove_keyboard'] !== true && $input['remove_keyboard'] !== 1) throw new DomainException('remove_keyboard должен быть true.');
        return '{"remove_keyboard":true}';
    }
    $reply = array_key_exists('keyboard', $input);
    $field = $reply ? 'keyboard' : 'inline_keyboard';
    if (!isset($input[$field]) || !is_array($input[$field]) || count($input[$field]) > 8) throw new DomainException('Некорректная клавиатура.');
    $keyboard = []; $total = 0;
    foreach ($input[$field] as $row) {
        if (!is_array($row) || count($row) < 1 || count($row) > 8) throw new DomainException('В строке клавиатуры должно быть от 1 до 8 кнопок.');
        $buttons = [];
        foreach ($row as $button) {
            if (++$total > 32 || !is_array($button) || !is_string($button['text'] ?? null) || mb_strlen(trim($button['text']), 'UTF-8') < 1 || mb_strlen($button['text'], 'UTF-8') > 64) throw new DomainException('Некорректная кнопка клавиатуры.');
            if ($reply) {
                if (count($button) !== 1) throw new DomainException('Обычная клавиатура поддерживает только текстовые кнопки.');
                $buttons[] = ['text' => trim($button['text'])];
                continue;
            }
            $hasData = isset($button['callback_data']); $hasUrl = isset($button['url']);
            if ($hasData === $hasUrl) throw new DomainException('Кнопке нужен callback_data или URL.');
            if ($hasData) {
                if (!is_string($button['callback_data']) || strlen($button['callback_data']) < 1 || strlen($button['callback_data']) > 64) throw new DomainException('callback_data: от 1 до 64 байт.');
                $buttons[] = ['text' => trim($button['text']), 'callback_data' => $button['callback_data']];
            } else {
                if (!is_string($button['url']) || !filter_var($button['url'], FILTER_VALIDATE_URL) || parse_url($button['url'], PHP_URL_SCHEME) !== 'https') throw new DomainException('URL кнопки должен использовать HTTPS.');
                $buttons[] = ['text' => trim($button['text']), 'url' => $button['url']];
            }
        }
        $keyboard[] = $buttons;
    }
    if ($reply) {
        if (!$keyboard) throw new DomainException('Клавиатура должна содержать кнопки.');
        $placeholder = $input['input_field_placeholder'] ?? '';
        if (!is_string($placeholder) || mb_strlen($placeholder, 'UTF-8') > 64) throw new DomainException('Подсказка клавиатуры — не более 64 символов.');
        foreach (['resize_keyboard', 'one_time_keyboard', 'is_persistent'] as $option) if (isset($input[$option]) && !is_bool($input[$option])) throw new DomainException($option . ' должен быть true или false.');
        return json_encode(['keyboard' => $keyboard, 'resize_keyboard' => $input['resize_keyboard'] ?? false, 'one_time_keyboard' => $input['one_time_keyboard'] ?? false, 'is_persistent' => $input['is_persistent'] ?? false, 'input_field_placeholder' => trim($placeholder)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    return json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function bot_platform_save_markup(int $messageId, ?string $markup): void {
    $message = one('SELECT chat_id, user_id FROM messages WHERE id = ?', [$messageId]);
    if (!$message) throw new DomainException('Сообщение для клавиатуры не найдено.');
    query('DELETE FROM bot_chat_keyboards WHERE message_id = ?', [$messageId]);
    query('DELETE FROM bot_message_markup WHERE message_id = ?', [$messageId]);
    if ($markup === null || $markup === '{"inline_keyboard":[]}') return;
    query('INSERT INTO bot_message_markup (message_id, markup_json) VALUES (?, ?)', [$messageId, $markup]);
    $decoded = json_decode($markup, true);
    if (isset($decoded['keyboard'])) {
        query('DELETE FROM bot_chat_keyboards WHERE chat_id = ?', [$message['chat_id']]);
        query('INSERT INTO bot_chat_keyboards (chat_id, bot_user_id, message_id, markup_json) VALUES (?, ?, ?, ?)', [$message['chat_id'], $message['user_id'], $messageId, $markup]);
    } elseif (!empty($decoded['remove_keyboard'])) {
        query('DELETE FROM bot_chat_keyboards WHERE chat_id = ? AND bot_user_id = ?', [$message['chat_id'], $message['user_id']]);
    }
}

function bot_platform_keyboard(int $chatId, int $userId): ?array {
    $row = one('SELECT k.message_id, k.markup_json, d.message_id AS dismissed_message_id FROM bot_chat_keyboards k JOIN members m ON m.chat_id = k.chat_id AND m.user_id = k.bot_user_id LEFT JOIN bot_keyboard_dismissals d ON d.chat_id = k.chat_id AND d.user_id = ? WHERE k.chat_id = ?', [$userId, $chatId]);
    if (!$row) return null;
    return ['message_id' => (int)$row['message_id'], 'markup' => json_decode($row['markup_json'], true), 'dismissed' => (int)$row['dismissed_message_id'] === (int)$row['message_id']];
}

function bot_platform_dismiss_keyboard(int $chatId, int $userId): void {
    $keyboard = bot_platform_keyboard($chatId, $userId);
    if (!$keyboard || empty($keyboard['markup']['one_time_keyboard']) || $keyboard['dismissed']) return;
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') query('INSERT INTO bot_keyboard_dismissals (chat_id, user_id, message_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE message_id = VALUES(message_id)', [$chatId, $userId, $keyboard['message_id']]);
    else query('INSERT INTO bot_keyboard_dismissals (chat_id, user_id, message_id) VALUES (?, ?, ?) ON CONFLICT(chat_id, user_id) DO UPDATE SET message_id = excluded.message_id', [$chatId, $userId, $keyboard['message_id']]);
}

function bot_platform_push(int $botId, string $key, array $update): void {
    $push = static function () use ($botId, $key, $update): void {
        if (one('SELECT id FROM bot_updates WHERE bot_user_id = ? AND event_key = ?', [$botId, $key])) return;
        query('UPDATE settings SET value = value + 1 WHERE name = ?', ['bot_update_seq']);
        $id = (int)(one('SELECT value FROM settings WHERE name = ?', ['bot_update_seq'])['value'] ?? 0);
        if ($id < 1) throw new RuntimeException('Bot update sequence is unavailable.');
        $update['update_id'] = $id;
        query('INSERT INTO bot_updates (id, bot_user_id, event_key, payload, created_at, next_attempt_at) VALUES (?, ?, ?, ?, ?, ?)', [$id, $botId, $key, json_encode($update, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), stamp(), stamp()]);
    };
    if (db()->inTransaction()) $push(); else transaction($push);
    if (one('SELECT bot_user_id FROM bot_webhooks WHERE bot_user_id = ?', [$botId])) bot_platform_schedule_dispatch();
}

function bot_platform_publish_message(int $messageId): void {
    $message = bot_platform_message($messageId);
    if (!$message) return;
    $recipients = query('SELECT b.bot_user_id FROM bot_accounts b JOIN members mem ON mem.user_id = b.bot_user_id WHERE mem.chat_id = ? AND mem.joined_at <= ? AND b.bot_user_id <> ?', [$message['chat']['id'], gmdate('Y-m-d H:i:s', $message['date']), $message['from']['id']])->fetchAll();
    foreach ($recipients as $recipient) bot_platform_push((int)$recipient['bot_user_id'], 'message:' . $messageId, ['message' => $message]);
}

function bot_platform_publish_callback(int $botId, string $callbackId): void {
    $callback = one('SELECT q.id, q.message_id, q.from_user_id, q.data, u.name, u.username FROM bot_callback_queries q JOIN users u ON u.id = q.from_user_id WHERE q.id = ? AND q.bot_user_id = ?', [$callbackId, $botId]);
    if (!$callback) return;
    bot_platform_push($botId, 'callback:' . $callbackId, ['callback_query' => [
        'id' => $callbackId,
        'from' => ['id' => (int)$callback['from_user_id'], 'is_bot' => false, 'first_name' => $callback['name'], 'username' => $callback['username']],
        'message' => bot_platform_message((int)$callback['message_id']),
        'data' => $callback['data'],
    ]]);
}

function bot_platform_webhook_target(string $url): array {
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) throw new DomainException('Укажите корректный HTTPS-адрес вебхука.');
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = (int)($parts['port'] ?? 443);
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($port, [443, 8443], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !preg_match('/^[a-z0-9.-]{4,253}$/D', $host) || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) throw new DomainException('Вебхук должен быть публичным HTTPS-доменом на порту 443 или 8443.');
    $records = dns_get_record($host, DNS_A);
    if (!is_array($records)) throw new DomainException('Не удалось проверить DNS вебхука.');
    foreach ($records as $record) {
        $ip = $record['ip'] ?? '';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [$host, $port, $ip];
    }
    throw new DomainException('Домен вебхука должен иметь публичный IPv4-адрес.');
}

function bot_platform_schedule_dispatch(): void {
    static $scheduled = false;
    if ($scheduled || PHP_SAPI === 'cli') return;
    $scheduled = true;
    register_shutdown_function(static function (): void {
        try {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            bot_platform_dispatch_pending(3);
        } catch (Throwable $e) { error_log('LChat webhook dispatch: ' . get_class($e) . ' ' . $e->getMessage()); }
    });
}

function bot_platform_dispatch_pending(int $limit = 20): int {
    if (!extension_loaded('curl')) return 0;
    $limit = max(1, min(100, $limit));
    $length = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'OCTET_LENGTH(q.payload)' : 'LENGTH(CAST(q.payload AS BLOB))';
    $meta = query('SELECT q.id, ' . $length . ' AS bytes FROM bot_updates q JOIN bot_webhooks w ON w.bot_user_id = q.bot_user_id WHERE q.delivered_at IS NULL AND q.next_attempt_at <= ? ORDER BY q.id LIMIT ' . $limit, [stamp()])->fetchAll();
    $ids = []; $total = 0; foreach ($meta as $item) { $size = (int)$item['bytes']; if ($ids && $total + $size > MESSAGE_TEXT_MAX_BYTES) break; $ids[] = (int)$item['id']; $total += $size; }
    $rows = $ids ? query('SELECT q.id, q.payload, q.attempts, w.bot_user_id, w.url, w.secret_token FROM bot_updates q JOIN bot_webhooks w ON w.bot_user_id = q.bot_user_id WHERE q.delivered_at IS NULL AND q.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY q.id', $ids)->fetchAll() : [];
    $done = 0;
    foreach ($rows as $row) {
        $claimed = query('UPDATE bot_updates SET attempts = attempts + 1, next_attempt_at = ? WHERE id = ? AND delivered_at IS NULL AND next_attempt_at <= ?', [stamp(60), $row['id'], stamp()]);
        if ($claimed->rowCount() !== 1) continue;
        $error = '';
        try {
            [$host, $port, $ip] = bot_platform_webhook_target($row['url']);
            $curl = curl_init($row['url']);
            if ($curl === false) throw new RuntimeException('Cannot initialize cURL.');
            $headers = ['Content-Type: application/json'];
            if ($row['secret_token'] !== '') $headers[] = 'X-Telegram-Bot-Api-Secret-Token: ' . $row['secret_token'];
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $row['payload'], CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip]]);
            if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
            else curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            $response = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($response === false || $status < 200 || $status >= 300) $error = $response === false ? curl_error($curl) : 'HTTP ' . $status;
            curl_close($curl);
        } catch (Throwable $e) { $error = $e->getMessage(); }
        if ($error === '') {
            query('UPDATE bot_updates SET delivered_at = ? WHERE id = ?', [stamp(), $row['id']]);
            query('UPDATE bot_webhooks SET last_error_message = ? WHERE bot_user_id = ?', ['', $row['bot_user_id']]);
            $done++;
        } else {
            $delay = min(3600, 5 * (2 ** min(9, (int)$row['attempts'])));
            query('UPDATE bot_updates SET next_attempt_at = ? WHERE id = ?', [stamp($delay), $row['id']]);
            query('UPDATE bot_webhooks SET last_error_message = ?, last_error_at = ? WHERE bot_user_id = ?', [mb_substr($error, 0, 500), stamp(), $row['bot_user_id']]);
        }
    }
    return $done;
}
