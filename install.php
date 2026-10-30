<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
try {
    if (PHP_VERSION_ID < 80200 || !extension_loaded('mbstring')) throw new RuntimeException('PHP 8.2+ и расширение mbstring обязательны.');
    $cfg = config(); $done = false;
    try { $done = (int)(one('SELECT value FROM settings WHERE name = ?', ['schema_version'])['value'] ?? 0) >= 3; if ($done) ensure_features(); }
    catch (PDOException $e) { if (!in_array($e->getCode(), ['42S02', 'HY000'], true)) throw $e; }
    if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $key = $cfg['install_key'] ?? '';
        if (strlen($key) < 32 || str_starts_with($key, 'REPLACE_') || !hash_equals($key, (string)($_POST['key'] ?? ''))) throw new RuntimeException('Неверный ключ установки.');
        require __DIR__ . '/apis/schema.php'; install_schema(db()); $done = true;
    } elseif (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) fail(405, 'Используйте GET или POST.');
    result(['done' => $done]);
} catch (Throwable $e) {
    error_log('LChat install: ' . $e->getMessage());
    $message = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Не удалось подключиться к базе или создать таблицы. Проверьте журнал PHP.';
    fail(503, $message);
}
