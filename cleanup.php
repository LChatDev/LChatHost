<?php
// Optional hourly cron: php /absolute/path/to/LChat/cleanup.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
require_once __DIR__ . '/apis/bots/pages/lib.php';
try {
    query('DELETE FROM signals WHERE created_at < ?', [stamp(-600)]);
    query('DELETE FROM rooms WHERE expires_at < ? OR ended_at < ?', [stamp(-86400), stamp(-86400)]);
    query('DELETE FROM rate_limits WHERE reset_at < ?', [time() - 3600]);
    $sites = temp_site_cleanup();
    echo "LChat: temporary call data cleaned; expired sites removed: $sites\n";
} catch (Throwable $e) { error_log('LChat cleanup: ' . $e->getMessage()); exit(1); }
