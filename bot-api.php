<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function bot_error(int $status, string $description): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error_code' => $status, 'description' => $description], JSON_UNESCAPED_UNICODE);
    exit;
}
function bot_result(mixed $value): never {
    echo json_encode(['ok' => true, 'result' => $value], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function bot_number(mixed $value, string $name, int $minimum = 1): int {
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]+$/D', (string)$value)) bot_error(400, 'Некорректное поле: ' . $name);
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
    if ($number === false) bot_error(400, 'Некорректное поле: ' . $name);
    return (int)$number;
}
function bot_text(mixed $value): string {
    if (!is_string($value)) bot_error(400, 'Укажите текст сообщения.');
    $value = trim($value);
    if ($value === '' || mb_strlen($value, 'UTF-8') > 4000) bot_error(400, 'Текст должен содержать от 1 до 4000 символов.');
    return $value;
}
function bot_boolean(mixed $value): bool {
    if (is_bool($value)) return $value;
    if (!is_scalar($value)) throw new DomainException('Ожидается логическое значение.');
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
}
function bot_chat(int $chatId, int $botId): array {
    $chat = one('SELECT c.id, c.kind, c.title, m.role FROM chats c JOIN members m ON m.chat_id = c.id WHERE c.id = ? AND m.user_id = ?', [$chatId, $botId]);
    if (!$chat) bot_error(403, 'Бот не состоит в этом чате.');
    return $chat;
}
function bot_message(array $message): array {
    return [
        'message_id' => (int)$message['id'],
        'from' => ['id' => (int)$message['user_id'], 'is_bot' => (bool)($message['is_bot'] ?? false), 'first_name' => $message['name'], 'username' => $message['username']],
        'chat' => ['id' => (int)$message['chat_id'], 'type' => $message['kind'] === 'direct' ? 'private' : $message['kind'], 'title' => $message['title']],
        'date' => strtotime($message['created_at'] . ' UTC'),
        'text' => $message['body'],
    ];
}
function bot_message_by_id(int $messageId): array {
    return bot_platform_message($messageId) ?? bot_error(404, 'Сообщение не найдено.');
}
function bot_limit(int $botId, int $maximum, int $seconds): void {
    $key = hash('sha256', 'bot-api:' . $botId . ':' . $maximum);
    $now = time(); $end = $now + $seconds;
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        query('INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = IF(reset_at < ?, 1, hits + 1), reset_at = IF(reset_at < ?, ?, reset_at)', [$key, $end, $now, $now, $end]);
    } else {
        query('INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, ?) ON CONFLICT(bucket) DO UPDATE SET hits = CASE WHEN reset_at < ? THEN 1 ELSE hits + 1 END, reset_at = CASE WHEN reset_at < ? THEN ? ELSE reset_at END', [$key, $end, $now, $now, $end]);
    }
    $row = one('SELECT hits FROM rate_limits WHERE bucket = ?', [$key]);
    if ((int)$row['hits'] > $maximum) { header('Retry-After: ' . $seconds); bot_error(429, 'Слишком много запросов.'); }
}

try {
    ensure_features();
    $token = bot_request_token();
    if ($token === '') bot_error(401, 'Токен не получен сервером. Передайте Authorization: Bearer или X-LChat-Bot-Token.');
    if (!preg_match('/^([0-9]+):([a-f0-9]{48})$/D', $token, $parts)) bot_error(401, 'Неверный токен бота.');
    $botId = (int)$parts[1];
    $bot = one('SELECT b.bot_user_id, b.owner_user_id, u.username, u.name FROM bot_accounts b JOIN users u ON u.id = b.bot_user_id WHERE b.bot_user_id = ? AND b.token_hash = ?', [$botId, hash('sha256', $token)]);
    if (!$bot || service_ban($botId) || service_ban((int)$bot['owner_user_id'])) bot_error(401, 'Неверный токен бота.');
    bot_limit($botId, 180, 60);
    $method = (string)($_GET['method'] ?? '');
    $readMethods = ['getMe', 'getUpdates', 'getChat', 'getWebhookInfo', 'getFile'];
    if (in_array($method, $readMethods, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') bot_error(405, 'Используйте GET.');
        $data = $_GET;
    } else {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') bot_error(405, 'Используйте POST.');
        $multipart = str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
        $maxBytes = $multipart ? 10 * 1024 * 1024 : 65536;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) bot_error(413, 'Слишком большой запрос.');
        if ($multipart) $data = $_POST;
        else {
            $raw = file_get_contents('php://input', false, null, 0, 65537);
            if ($raw === false || strlen($raw) > 65536) bot_error(413, 'Слишком большой запрос.');
            $data = str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json') ? json_decode($raw, true) : $_POST;
        }
        if (!is_array($data)) bot_error(400, 'Ожидается JSON или данные формы.');
    }
    if ($method === 'getMe') bot_result(['id' => $botId, 'is_bot' => true, 'first_name' => $bot['name'], 'username' => $bot['username']]);
    if ($method === 'setWebhook') {
        $url = trim((string)($data['url'] ?? ''));
        if ($url === '') {
            query('DELETE FROM bot_webhooks WHERE bot_user_id = ?', [$botId]);
            bot_result(true);
        }
        if (!extension_loaded('curl')) bot_error(503, 'Для вебхуков нужно расширение curl.');
        bot_platform_webhook_target($url);
        $secret = (string)($data['secret_token'] ?? '');
        if ($secret !== '' && !preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $secret)) bot_error(400, 'secret_token должен содержать латинские буквы, цифры, _ или -.');
        transaction(static function () use ($botId, $url, $secret): void {
            query('UPDATE users SET name = name WHERE id = ?', [$botId]);
            if (one('SELECT bot_user_id FROM bot_webhooks WHERE bot_user_id = ?', [$botId])) query('UPDATE bot_webhooks SET url = ?, secret_token = ?, last_error_message = ? WHERE bot_user_id = ?', [$url, $secret, '', $botId]);
            else query('INSERT INTO bot_webhooks (bot_user_id, url, secret_token) VALUES (?, ?, ?)', [$botId, $url, $secret]);
        });
        if (bot_boolean($data['drop_pending_updates'] ?? false)) query('DELETE FROM bot_updates WHERE bot_user_id = ? AND delivered_at IS NULL', [$botId]);
        bot_platform_schedule_dispatch();
        bot_result(true);
    }
    if ($method === 'deleteWebhook') {
        query('DELETE FROM bot_webhooks WHERE bot_user_id = ?', [$botId]);
        if (bot_boolean($data['drop_pending_updates'] ?? false)) query('DELETE FROM bot_updates WHERE bot_user_id = ? AND delivered_at IS NULL', [$botId]);
        bot_result(true);
    }
    if ($method === 'getWebhookInfo') {
        $hook = one('SELECT url, last_error_message, last_error_at FROM bot_webhooks WHERE bot_user_id = ?', [$botId]);
        $pending = (int)(one('SELECT COUNT(*) AS n FROM bot_updates WHERE bot_user_id = ? AND delivered_at IS NULL', [$botId])['n'] ?? 0);
        $info = ['url' => $hook['url'] ?? '', 'has_custom_certificate' => false, 'pending_update_count' => $pending];
        if (!empty($hook['last_error_at'])) { $info['last_error_date'] = strtotime($hook['last_error_at'] . ' UTC'); $info['last_error_message'] = $hook['last_error_message']; }
        bot_result($info);
    }
    if ($method === 'getUpdates') {
        if (one('SELECT bot_user_id FROM bot_webhooks WHERE bot_user_id = ?', [$botId])) bot_error(409, 'Сначала отключите вебхук через deleteWebhook.');
        $offset = bot_number($data['offset'] ?? 0, 'offset', 0);
        $limit = min(100, bot_number($data['limit'] ?? 50, 'limit'));
        if ($offset > 0) query('DELETE FROM bot_updates WHERE bot_user_id = ? AND id < ?', [$botId, $offset]);
        $rows = query('SELECT payload FROM bot_updates WHERE bot_user_id = ? AND delivered_at IS NULL AND id >= ? ORDER BY id LIMIT ' . $limit, [$botId, $offset])->fetchAll();
        bot_result(array_map(static fn(array $row): array => json_decode($row['payload'], true) ?: [], $rows));
    }
    if ($method === 'getFile') {
        $fileId = bot_number($data['file_id'] ?? null, 'file_id');
        $file = one('SELECT f.message_id, f.size, f.original_name, m.created_at, mem.joined_at FROM message_files f JOIN messages m ON m.id = f.message_id JOIN members mem ON mem.chat_id = m.chat_id AND mem.user_id = ? WHERE f.message_id = ?', [$botId, $fileId]);
        if (!$file || $file['created_at'] < $file['joined_at']) bot_error(404, 'Файл недоступен боту.');
        bot_result(['file_id' => (string)$fileId, 'file_unique_id' => substr(hash('sha256', (string)$fileId), 0, 32), 'file_size' => (int)$file['size'], 'file_path' => 'bot-file.php?file_id=' . $fileId]);
    }
    if ($method === 'answerCallbackQuery') {
        $id = (string)($data['callback_query_id'] ?? '');
        $answer = trim((string)($data['text'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || mb_strlen($answer, 'UTF-8') > 200) bot_error(400, 'Некорректный ответ на нажатие кнопки.');
        $changed = query('UPDATE bot_callback_queries SET answer_text = ?, answered_at = ? WHERE id = ? AND bot_user_id = ? AND answered_at IS NULL', [$answer, stamp(), $id, $botId]);
        if ($changed->rowCount() !== 1) bot_error(404, 'Нажатие не найдено или уже обработано.');
        bot_result(true);
    }
    $chatId = bot_number($data['chat_id'] ?? null, 'chat_id');
    $chat = bot_chat($chatId, $botId);
    if ($method === 'getChat') bot_result(['id' => $chatId, 'type' => $chat['kind'] === 'direct' ? 'private' : $chat['kind'], 'title' => $chat['title']]);
    if ($method === 'sendMessage') {
        if ($chat['kind'] === 'channel' && $chat['role'] !== 'admin') bot_error(403, 'Боту нельзя публиковать в этом канале.');
        bot_limit($botId, 90, 60);
        $text = bot_text($data['text'] ?? null);
        $reply = isset($data['reply_to_message_id']) ? bot_number($data['reply_to_message_id'], 'reply_to_message_id') : null;
        if ($reply && !one('SELECT id FROM messages WHERE id = ? AND chat_id = ?', [$reply, $chatId])) bot_error(400, 'Сообщение для ответа не найдено в этом чате.');
        $markup = bot_platform_markup($data['reply_markup'] ?? null);
        $messageId = transaction(static function () use ($chatId, $botId, $text, $reply, $markup): int {
            query('INSERT INTO messages (chat_id, user_id, body, nonce, reply_to_id, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$chatId, $botId, $text, 'bot_' . bin2hex(random_bytes(20)), $reply, stamp()]);
            $id = (int)db()->lastInsertId();
            if ($markup !== null) bot_platform_save_markup($id, $markup);
            return $id;
        });
        bot_platform_publish_message($messageId);
        bot_result(bot_message_by_id($messageId));
    }
    if (in_array($method, ['sendDocument', 'sendPhoto', 'sendAudio', 'sendVideo', 'sendVoice', 'sendAnimation'], true)) {
        if ($chat['kind'] === 'channel' && $chat['role'] !== 'admin') bot_error(403, 'Боту нельзя публиковать в этом канале.');
        bot_limit($botId, 30, 3600);
        $kind = match ($method) { 'sendPhoto' => 'photo', 'sendAudio' => 'audio', 'sendVideo' => 'video', 'sendVoice' => 'voice', 'sendAnimation' => 'animation', default => 'document' };
        bot_result(bot_file_send($botId, $chatId, $kind, $data, bot_platform_markup($data['reply_markup'] ?? null)));
    }
    if ($method === 'editMessageText') {
        $messageId = bot_number($data['message_id'] ?? null, 'message_id');
        $text = bot_text($data['text'] ?? null);
        $message = one('SELECT m.id, m.body, f.message_id AS file_id, i.message_id AS invite_id, e.message_id AS event_id FROM messages m LEFT JOIN message_files f ON f.message_id = m.id LEFT JOIN message_invites i ON i.message_id = m.id LEFT JOIN message_events e ON e.message_id = m.id WHERE m.id = ? AND m.chat_id = ? AND m.user_id = ?', [$messageId, $chatId, $botId]);
        if (!$message) bot_error(404, 'Сообщение бота не найдено.');
        if ($message['file_id'] || $message['invite_id'] || $message['event_id']) bot_error(400, 'Этот тип сообщения нельзя редактировать.');
        $markupProvided = array_key_exists('reply_markup', $data);
        $markup = $markupProvided ? bot_platform_markup($data['reply_markup']) : null;
        if ($markup !== null && !isset(json_decode($markup, true)['inline_keyboard'])) bot_error(400, 'При редактировании доступны только inline-кнопки.');
        if ($message['body'] !== $text || $markupProvided) transaction(function () use ($chatId, $messageId, $text, $markup, $markupProvided): void {
            $at = stamp();
            query('UPDATE messages SET body = ?, edited_at = ? WHERE id = ? AND chat_id = ?', [$text, $at, $messageId, $chatId]);
            if ($markupProvided) bot_platform_save_markup($messageId, $markup);
            query('INSERT INTO message_edits (chat_id, message_id, edited_at) VALUES (?, ?, ?)', [$chatId, $messageId, $at]);
        });
        bot_result(bot_message_by_id($messageId));
    }
    if ($method === 'editMessageReplyMarkup') {
        $messageId = bot_number($data['message_id'] ?? null, 'message_id');
        if (!one('SELECT id FROM messages WHERE id = ? AND chat_id = ? AND user_id = ?', [$messageId, $chatId, $botId])) bot_error(404, 'Сообщение бота не найдено.');
        $markup = bot_platform_markup($data['reply_markup'] ?? null);
        if ($markup !== null && !isset(json_decode($markup, true)['inline_keyboard'])) bot_error(400, 'При редактировании доступны только inline-кнопки.');
        transaction(static function () use ($chatId, $messageId, $markup): void {
            bot_platform_save_markup($messageId, $markup);
            $at = stamp();
            query('UPDATE messages SET edited_at = ? WHERE id = ?', [$at, $messageId]);
            query('INSERT INTO message_edits (chat_id, message_id, edited_at) VALUES (?, ?, ?)', [$chatId, $messageId, $at]);
        });
        bot_result(bot_message_by_id($messageId));
    }
    if ($method === 'deleteMessage') {
        $messageId = bot_number($data['message_id'] ?? null, 'message_id');
        $file = one('SELECT f.stored_name FROM message_files f JOIN messages m ON m.id = f.message_id WHERE m.id = ? AND m.chat_id = ? AND m.user_id = ?', [$messageId, $chatId, $botId]);
        $deleted = query('DELETE FROM messages WHERE id = ? AND chat_id = ? AND user_id = ?', [$messageId, $chatId, $botId]);
        if ($deleted->rowCount() !== 1) bot_error(404, 'Сообщение бота не найдено.');
        if ($file && preg_match('/^[a-f0-9]{48}\.bin$/D', $file['stored_name'])) { $path = __DIR__ . '/files/' . $file['stored_name']; if (is_file($path)) unlink($path); }
        bot_result(true);
    }
    bot_error(404, 'Метод Bot API не найден.');
} catch (DomainException $e) {
    bot_error(400, $e->getMessage());
} catch (Throwable $e) {
    error_log('LChat Bot API: ' . get_class($e) . ' ' . $e->getMessage());
    bot_error(500, 'Ошибка Bot API. Проверьте журнал PHP.');
}
