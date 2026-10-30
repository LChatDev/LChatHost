<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$error = ''; $done = false;
try {
    if (PHP_VERSION_ID < 80200 || !extension_loaded('mbstring')) throw new RuntimeException('Нужны PHP 8.2+ и расширение mbstring.');
    $cfg = config();
    try { $done = (int)(one('SELECT value FROM settings WHERE name = ?', ['schema_version'])['value'] ?? 0) >= 3; if ($done) ensure_features(); }
    catch (PDOException $e) {
        if (!in_array($e->getCode(), ['42S02', 'HY000'], true)) throw $e;
    }
    if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $key = $cfg['install_key'] ?? '';
        if (strlen($key) < 32 || str_starts_with($key, 'REPLACE_') || !hash_equals($key, (string)($_POST['key'] ?? ''))) throw new RuntimeException('Неверный ключ установки. Задайте свой секретный ключ в config.php.');
        require __DIR__ . '/apis/schema.php';
        install_schema(db()); $done = true;
    }
} catch (Throwable $e) {
    error_log('LChat install: ' . $e->getMessage());
    $error = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Не удалось подключиться к базе или создать таблицы. Проверьте настройки и журнал PHP.';
}
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка · LChat</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/theme.css?v=20261013-dark1"><link rel="stylesheet" href="assets/details.css?v=20261014"><link rel="stylesheet" href="assets/aurora.css?v=20261026-aurora1"><link rel="stylesheet" href="assets/weba-pages.css?v=20261029-weba2"></head>
<body class="install-body"><main class="install-card"><span class="brand-mark">L</span><h1>Установка LChat</h1>
<?php if ($done): ?><p>Таблицы созданы. Теперь можно зарегистрироваться.</p><p class="muted">Удалите install.php и очистите install_key в конфигурации.</p><a class="button primary" href="index.php">Открыть мессенджер</a>
<?php else: ?><p>Подключение к базе задано в <code>config.php</code>. Введите ключ установки, чтобы создать или обновить таблицы.</p>
<?php if ($error && $_SERVER['REQUEST_METHOD'] === 'POST'): ?><p class="form-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post"><label>Ключ установки<input type="password" name="key" required autocomplete="off"></label><button class="primary">Создать таблицы</button></form><?php endif; ?>
</main></body></html>
