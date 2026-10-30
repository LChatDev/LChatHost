<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function bot_file_allowed(string $mime, string $kind): bool {
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf','text/plain','application/zip','application/x-zip-compressed','application/gzip','application/x-7z-compressed','application/vnd.rar','application/x-rar','audio/mpeg','audio/ogg','audio/wav','audio/x-wav','audio/mp4','audio/x-m4a','audio/webm','video/mp4','video/webm','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    if (!in_array($mime, $allowed, true)) return false;
    if ($kind === 'photo') return in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true);
    if ($kind === 'voice' || $kind === 'audio') return str_starts_with($mime, 'audio/');
    if ($kind === 'video') return str_starts_with($mime, 'video/');
    if ($kind === 'animation') return in_array($mime, ['image/gif','video/mp4','video/webm'], true);
    return true;
}

function bot_file_source(int $botId, string $field, array $data, string $kind): array {
    $upload = $_FILES[$field] ?? null;
    if ($upload) {
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) throw new DomainException('Не удалось загрузить файл. Максимум — 8 МБ.');
        $source = $upload['tmp_name'];
        $size = filesize($source);
        if ($size === false || $size < 1 || $size > 8 * 1024 * 1024) throw new DomainException('Файл должен быть от 1 байта до 8 МБ.');
        if (!extension_loaded('fileinfo')) throw new RuntimeException('Для файлов требуется расширение fileinfo.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
        if (!is_string($mime) || !bot_file_allowed($mime, $kind)) throw new DomainException('Этот формат файла не поддерживается.');
        $name = str_replace(['/', '\\'], '_', preg_replace('/[\x00-\x1f\x7f]/u', '_', (string)($upload['name'] ?? '')) ?? '');
        return [$source, mb_substr($name ?: 'Файл', 0, 190), $mime, (int)$size, true];
    }
    $fileId = $data[$field] ?? null;
    if ((!is_string($fileId) && !is_int($fileId)) || !preg_match('/^[0-9]+$/D', (string)$fileId)) throw new DomainException('Передайте файл через multipart/form-data или укажите file_id.');
    $file = one('SELECT f.stored_name, f.original_name, f.mime, f.size, m.chat_id, m.created_at, mem.joined_at FROM message_files f JOIN messages m ON m.id = f.message_id JOIN members mem ON mem.chat_id = m.chat_id AND mem.user_id = ? WHERE f.message_id = ?', [$botId, (int)$fileId]);
    if (!$file || $file['created_at'] < $file['joined_at']) throw new DomainException('Файл недоступен этому боту.');
    if (!bot_file_allowed($file['mime'], $kind)) throw new DomainException('Тип файла не подходит для этого метода.');
    if (!preg_match('/^[a-f0-9]{48}\.bin$/D', $file['stored_name'])) throw new DomainException('Файл недоступен.');
    $source = dirname(__DIR__) . '/files/' . $file['stored_name'];
    if (!is_file($source)) throw new DomainException('Файл больше недоступен.');
    return [$source, $file['original_name'], $file['mime'], (int)$file['size'], false];
}

function bot_file_send(int $botId, int $chatId, string $kind, array $data, ?string $markup): array {
    $field = match ($kind) { 'photo' => 'photo', 'audio' => 'audio', 'video' => 'video', 'voice' => 'voice', 'animation' => 'animation', default => 'document' };
    [$source, $name, $mime, $size, $uploaded] = bot_file_source($botId, $field, $data, $kind);
    if (isset($data['caption']) && !is_string($data['caption'])) throw new DomainException('Подпись должна быть строкой.');
    $caption = trim($data['caption'] ?? '');
    if (mb_strlen($caption, 'UTF-8') > 1024) throw new DomainException('Подпись к файлу — не больше 1024 символов.');
    $body = $caption !== '' ? $caption : ($kind === 'voice' ? '🎤 Голосовое сообщение' : '📎 ' . $name);
    $reply = isset($data['reply_to_message_id']) ? (int)$data['reply_to_message_id'] : null;
    if ($reply && !one('SELECT id FROM messages WHERE id = ? AND chat_id = ?', [$reply, $chatId])) throw new DomainException('Сообщение для ответа не найдено.');
    $duration = $kind === 'voice' ? max(1, min(180, (int)($data['duration'] ?? 1))) : 0;
    $dir = dirname(__DIR__) . '/files';
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Папка files недоступна для записи.');
    $storedName = bin2hex(random_bytes(24)) . '.bin';
    $path = $dir . '/' . $storedName;
    if ($uploaded ? !move_uploaded_file($source, $path) : !copy($source, $path)) throw new RuntimeException('Не удалось сохранить файл бота.');
    chmod($path, 0640);
    try {
        $messageId = transaction(static function () use ($botId, $chatId, $body, $reply, $storedName, $name, $mime, $size, $kind, $duration, $markup): int {
            query('UPDATE users SET name = name WHERE id = ?', [$botId]);
            $quota = one('SELECT COALESCE(SUM(f.size), 0) AS n FROM message_files f JOIN messages m ON m.id = f.message_id WHERE m.user_id = ?', [$botId]);
            if ((int)$quota['n'] + $size > 200 * 1024 * 1024) throw new DomainException('Лимит хранения файлов бота — 200 МБ.');
            query('INSERT INTO messages (chat_id, user_id, body, nonce, reply_to_id, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$chatId, $botId, $body, 'bot_' . bin2hex(random_bytes(20)), $reply, stamp()]);
            $id = (int)db()->lastInsertId();
            query('INSERT INTO message_files (message_id, stored_name, original_name, mime, size) VALUES (?, ?, ?, ?, ?)', [$id, $storedName, $name, $mime, $size]);
            query('INSERT INTO bot_media_kinds (message_id, kind) VALUES (?, ?)', [$id, $kind]);
            if ($kind === 'voice') query('INSERT INTO message_voice (message_id, duration) VALUES (?, ?)', [$id, $duration]);
            if ($markup !== null) bot_platform_save_markup($id, $markup);
            return $id;
        });
    } catch (Throwable $e) { if (is_file($path)) unlink($path); throw $e; }
    bot_platform_publish_message($messageId);
    return bot_platform_message($messageId) ?? [];
}
