<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
try {
    ensure_features(); require_user(context());
    $id = (int)($_GET['id'] ?? 0);
    $user = one('SELECT name FROM users WHERE id = ?', [$id]);
    if (!$user) fail(404, 'Пользователь не найден.');
    $photo = one('SELECT stored_name FROM user_avatars WHERE user_id = ?', [$id]);
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=60');
    if ($photo && preg_match('/^[a-f0-9]{48}\.png$/D', $photo['stored_name']) && is_file(__DIR__ . '/files/' . $photo['stored_name'])) {
        header('Content-Type: image/png'); readfile(__DIR__ . '/files/' . $photo['stored_name']);
    } else {
        $letter = htmlspecialchars(mb_strtoupper(mb_substr($user['name'], 0, 1)), ENT_QUOTES | ENT_XML1, 'UTF-8');
        header('Content-Type: image/svg+xml'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" rx="50" fill="#3390ec"/><text x="50" y="64" text-anchor="middle" font-family="sans-serif" font-size="45" fill="white">' . $letter . '</text></svg>';
    }
} catch (Throwable $e) { error_log('LChat avatar: ' . $e->getMessage()); fail(500, 'Не удалось загрузить фото.'); }
