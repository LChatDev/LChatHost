<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
header('Cache-Control: no-store');
$saved = null;
try {
    ensure_features(); $ctx = context(); $user = require_user($ctx);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Используйте POST.');
    if (!hash_equals($ctx['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Обновите страницу и повторите действие.');
    if (!extension_loaded('gd')) fail(503, 'Для фото профиля нужно включить расширение GD на хостинге.');
    $file = $_FILES['photo'] ?? null;
    if (!$file || ($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) fail(422, 'Выберите фото JPEG, PNG или WebP до 2 МБ.');
    $size = filesize($file['tmp_name']);
    if (!$size || $size > 2 * 1024 * 1024) fail(422, 'Фото должно быть не больше 2 МБ.');
    $info = getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] > 4000 || $info[1] > 4000) fail(422, 'Фото: JPEG, PNG или WebP, до 4000 × 4000 пикселей.');
    rate('avatar:' . $user['id'], 20, 3600);
    $source = imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$source) fail(422, 'Не удалось прочитать фото.');
    $image = imagecreatetruecolor(400, 400);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $side = min($info[0], $info[1]);
    imagecopyresampled($image, $source, 0, 0, (int)(($info[0] - $side) / 2), (int)(($info[1] - $side) / 2), 400, 400, $side, $side);
    $stored = bin2hex(random_bytes(24)) . '.png'; $saved = __DIR__ . '/files/' . $stored;
    if (!imagepng($image, $saved, 7)) throw new RuntimeException('Cannot save avatar.');
    imagedestroy($source); imagedestroy($image); chmod($saved, 0640);
    $old = transaction(function() use ($user, $stored) {
        query('UPDATE users SET name = name WHERE id = ?', [$user['id']]);
        $old = one('SELECT stored_name FROM user_avatars WHERE user_id = ?', [$user['id']]);
        query('DELETE FROM user_avatars WHERE user_id = ?', [$user['id']]);
        query('INSERT INTO user_avatars (user_id, stored_name, updated_at) VALUES (?, ?, ?)', [$user['id'], $stored, stamp()]);
        return $old['stored_name'] ?? '';
    });
    $saved = null;
    if (preg_match('/^[a-f0-9]{48}\.png$/D', $old) && is_file(__DIR__ . '/files/' . $old)) unlink(__DIR__ . '/files/' . $old);
    result(['ok' => true]);
} catch (Throwable $e) {
    if ($saved && is_file($saved)) unlink($saved);
    error_log('LChat avatar: ' . $e->getMessage()); fail(500, 'Не удалось сохранить фото профиля.');
}
