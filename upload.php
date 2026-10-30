<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
header('Cache-Control: no-store');
$stored = null;
try {
    ensure_features(); $ctx = context(); $user = require_user($ctx);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Используйте POST.');
    if (!hash_equals($ctx['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Обновите страницу и повторите действие.');
    $chatId = (int)($_POST['chat_id'] ?? 0); $chat = member($chatId, (int)$user['id']);
    if ($chat['kind'] === 'channel' && $chat['role'] !== 'owner') fail(403, 'Публиковать в канале может только владелец.');
    $voice = ($_POST['voice'] ?? '') === '1'; $duration = (int)($_POST['duration'] ?? 0);
    if ($voice && ($duration < 1 || $duration > 180)) fail(422, 'Голосовое сообщение: от 1 до 180 секунд.');
    $nonce = field($_POST, 'nonce', 16, 64);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $nonce)) fail(422, 'Некорректный идентификатор файла.');
    $existing = one('SELECT id, chat_id FROM messages WHERE user_id = ? AND nonce = ?', [$user['id'], $nonce]);
    if ($existing) { if ((int)$existing['chat_id'] !== $chatId) fail(409, 'Идентификатор уже использован.'); bot_platform_publish_message((int)$existing['id']); result(['id' => $existing['id']]); }
    rate('upload:' . $user['id'], 30, 3600);
    $file = $_FILES['file'] ?? null;
    if (!$file || !is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) fail(422, 'Не удалось загрузить файл. Максимум — 8 МБ.');
    $size = filesize($file['tmp_name']); if ($size === false || $size < 1 || $size > 8 * 1024 * 1024) fail(422, 'Файл должен быть от 1 байта до 8 МБ.');
    if (!extension_loaded('fileinfo')) fail(503, 'На сервере нужно включить расширение fileinfo.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($voice && !in_array($mime, ['audio/ogg','application/ogg','audio/webm','video/webm','audio/mp4','audio/x-m4a','video/mp4','audio/mpeg','audio/wav','audio/x-wav'], true)) fail(422, 'Файл голосового сообщения должен быть аудио.');
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf','text/plain','application/zip','application/x-zip-compressed','application/gzip','application/x-7z-compressed','application/vnd.rar','application/x-rar','audio/mpeg','audio/ogg','audio/wav','audio/x-wav','audio/mp4','audio/x-m4a','video/mp4','video/webm','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    if (!$voice && !in_array($mime, $allowed, true)) fail(422, 'Этот формат файла не поддерживается. Можно отправить фото, PDF, текст, архив, аудио, видео или документ Office.');
    $name = str_replace(['/', '\\'], '_', preg_replace('/[\x00-\x1f\x7f]/u', '_', (string)$file['name']) ?? '');
    $name = mb_substr($name ?: 'Файл', 0, 190);
    $body = field(['body' => $_POST['body'] ?? ''], 'body', 0, 4000);
    if ($voice) $body = '🎤 Голосовое сообщение'; elseif ($body === '') $body = '📎 ' . $name;
    $dir = __DIR__ . '/files';
    if (!is_dir($dir) || !is_writable($dir)) fail(503, 'Папка files должна существовать и быть доступна PHP для записи.');
    $storedName = bin2hex(random_bytes(24)) . '.bin'; $stored = $dir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $stored)) throw new RuntimeException('Cannot save upload.');
    chmod($stored, 0640);
    $id = transaction(function() use ($user, $chatId, $nonce, $body, $storedName, $name, $mime, $size, $voice, $duration) {
        query('UPDATE users SET name = name WHERE id = ?', [$user['id']]);
        $quota = one('SELECT COALESCE(SUM(f.size), 0) AS n FROM message_files f JOIN messages m ON m.id = f.message_id WHERE m.user_id = ?', [$user['id']]);
        if ((int)$quota['n'] + $size > 200 * 1024 * 1024) throw new DomainException('Лимит хранения файлов аккаунта — 200 МБ.');
        query('INSERT INTO messages (chat_id, user_id, body, nonce, created_at) VALUES (?, ?, ?, ?, ?)', [$chatId, $user['id'], $body, $nonce, stamp()]);
        $id = (int)db()->lastInsertId();
        query('INSERT INTO message_files (message_id, stored_name, original_name, mime, size) VALUES (?, ?, ?, ?, ?)', [$id, $storedName, $name, $mime, $size]);
        if ($voice) query('INSERT INTO message_voice (message_id,duration) VALUES (?,?)',[$id,$duration]);
        return $id;
    });
    $stored = null; bot_platform_publish_message($id); result(['id' => $id]);
} catch (Throwable $e) {
    if ($stored && is_file($stored)) unlink($stored);
    if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) {
        $existing = one('SELECT id, chat_id FROM messages WHERE user_id = ? AND nonce = ?', [$user['id'], $nonce]);
        if ($existing && (int)$existing['chat_id'] === $chatId) result(['id' => $existing['id']]);
    }
    error_log('LChat upload: ' . $e->getMessage());
    fail($e instanceof DomainException ? 422 : 500, $e instanceof DomainException ? $e->getMessage() : 'Не удалось сохранить файл. Проверьте журнал PHP.');
}
