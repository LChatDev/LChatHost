<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }
function frontend_is_remote(): bool {
    $path = getenv('LCHAT_CONFIG') ?: dirname(__DIR__) . '/config.php';
    if (!is_file($path)) return false;
    $config = require $path;
    return is_array($config) && ($config['backend']['mode'] ?? 'host') === 'pc';
}
