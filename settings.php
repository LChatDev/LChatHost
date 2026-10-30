<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $ctx = context();
    ensure_features();
    $user = require_user($ctx);
    $uid = (int)$user['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $prefs = one('SELECT bio, theme, accent, compact FROM user_preferences WHERE user_id = ?', [$uid]);
        result(['profile' => ['id' => $uid, 'username' => $user['username'], 'name' => $user['name']], 'settings' => $prefs ?: ['bio' => '', 'theme' => 'dark', 'accent' => 0, 'compact' => 0]]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Используйте GET или POST.');
    if (!hash_equals($ctx['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Обновите страницу и повторите действие.');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) fail(413, 'Слишком большой запрос.');
    $body = file_get_contents('php://input', false, null, 0, 8193);
    if ($body === false || strlen($body) > 8192) fail(413, 'Слишком большой запрос.');
    $data = json_decode($body, true);
    if (!is_array($data)) fail(400, 'Ожидается JSON.');
    $name = field($data, 'name', 2, 60);
    $bio = $data['bio'] ?? '';
    if (!is_string($bio)) fail(422, 'Некорректное описание профиля.');
    $bio = trim($bio);
    if (mb_strlen($bio, 'UTF-8') > 240) fail(422, 'Описание профиля: максимум 240 символов.');
    $theme = $data['theme'] ?? 'dark';
    if (!in_array($theme, ['dark', 'light'], true)) fail(422, 'Выберите светлую или тёмную тему.');
    $accent = filter_var($data['accent'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
    if ($accent === false) fail(422, 'Выберите цвет из списка.');
    $compact = !empty($data['compact']) ? 1 : 0;
    transaction(function () use ($uid, $name, $bio, $theme, $accent, $compact): void {
        query('UPDATE users SET name = ? WHERE id = ?', [$name, $uid]);
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            query('INSERT INTO user_preferences (user_id, bio, theme, accent, compact) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE bio = VALUES(bio), theme = VALUES(theme), accent = VALUES(accent), compact = VALUES(compact)', [$uid, $bio, $theme, $accent, $compact]);
        } else {
            query('INSERT INTO user_preferences (user_id, bio, theme, accent, compact) VALUES (?, ?, ?, ?, ?) ON CONFLICT(user_id) DO UPDATE SET bio = excluded.bio, theme = excluded.theme, accent = excluded.accent, compact = excluded.compact', [$uid, $bio, $theme, $accent, $compact]);
        }
    });
    result(['ok' => true]);
} catch (Throwable $e) {
    error_log('LChat settings: ' . $e->getMessage());
    fail(500, 'Не удалось сохранить настройки.');
}
