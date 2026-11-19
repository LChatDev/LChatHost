<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
try {
    ensure_features(); require_user(context());
    $id = (int)($_GET['id'] ?? 0);
    $user = one('SELECT id FROM users WHERE id = ?', [$id]);
    if (!$user) fail(404, 'Пользователь не найден.');
    $photo = one('SELECT stored_name FROM user_avatars WHERE user_id = ?', [$id]);
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=60');
    if ($photo && preg_match('/^[a-f0-9]{48}\.png$/D', $photo['stored_name']) && is_file(lchat_file_path($photo['stored_name']))) {
        header('Content-Type: image/png'); readfile(lchat_file_path($photo['stored_name']));
    } else {
        header('Content-Type: image/svg+xml'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");

        // Stable colors and features from the account ID on a 16 by 16 pixel grid.
        $seed = hash('sha256', 'lchat-avatar:' . $id, true);
        $backgrounds = ['#2946ad', '#773b9b', '#186e67', '#aa4c45', '#a45c25', '#285781'];
        $faces = ['#b6ddff', '#f5c9ff', '#a8edde', '#ffcfb4', '#ffe284', '#e1dcff'];
        $haircuts = [
            'M6 4h5v1H9v1H5V5h1z',
            'M7 3h3v1h2v2h-2V5H6v1H5V4h2z',
            'M6 4h5v1h1v2h-1V6H7v1H5V5h1z',
            'M5 5h6v1H9v1H7V6H5z',
        ];
        $eyes = ['M5 8h2v2H5zM9 8h2v2H9z', 'M5 9h2v1H5zM9 9h2V8h1v2H9z', 'M5 8h2v2H5zM9 9h2v1H9z'];
        $mouths = ['M7 11h2v1H7z', 'M6 11h1v1h2v-1h1v2H6z', 'M7 11h2v2H7z'];

        echo '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 16 16" shape-rendering="crispEdges">';
        printf('<path fill="%s" d="M0 0h16v16H0z"/>', $backgrounds[ord($seed[0]) % count($backgrounds)]);
        echo '<path fill="#fff" opacity=".22" d="M1 2h2v1H1zM2 1h1v3H2zM13 11h1v3h-1zM12 12h3v1h-3z"/>';
        echo '<path fill="#101735" d="M5 2h6v1h2v2h1v7h-1v2h-2v1H5v-1H3v-2H2V5h1V3h2z"/>';
        printf('<path fill="%s" d="M5 4h6v1h2v7h-1v1h-2v1H6v-1H4v-1H3V6h1V5h1z"/>', $faces[ord($seed[1]) % count($faces)]);
        echo '<path fill="#101735" opacity=".14" d="M12 6h1v6h-1v1h-2v1H6v-1h4v-1h2z"/>';
        printf('<path fill="#101735" d="%s%s%s"/></svg>', $haircuts[ord($seed[2]) % count($haircuts)], $eyes[ord($seed[3]) % count($eyes)], $mouths[ord($seed[4]) % count($mouths)]);
    }
} catch (Throwable $e) { error_log('LChat avatar: ' . $e->getMessage()); fail(500, 'Не удалось загрузить фото.'); }
