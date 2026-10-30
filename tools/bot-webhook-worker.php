<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('LCHAT_INTERNAL', true);
require dirname(__DIR__) . '/app.php';
try {
    ensure_features();
    if (!extension_loaded('curl')) throw new RuntimeException('Enable the curl extension.');
    $count = bot_platform_dispatch_pending(100);
    fwrite(STDOUT, "Delivered webhook updates: {$count}\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Webhook worker failed: ' . $e->getMessage() . "\n");
    exit(1);
}
