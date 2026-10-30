<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

return [
    'database' => [
        'dsn' => 'mysql:host=localhost;port=3306;dbname=YOUR_DATABASE;charset=utf8mb4',
        'username' => 'YOUR_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
    ],
    'install_key' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET',
    // Must match LChatWeb backend.proxy_secret; keep this secret private.
    'proxy_secret' => 'REPLACE_WITH_THE_SAME_LONG_RANDOM_SECRET',
    'site_name' => 'LChat',
    'registration_open' => true,
    'session_name' => 'lchat_session',
    'session_domain' => '',
    // WebRTC соединяет участников друг с другом; используйте небольшой лимит.
    'max_call_participants' => 8,
    'ice_servers' => [],
    'turn' => [
        'urls' => 'turn:209.25.141.16:45209?transport=tcp',
        // Set this privately in config.php or the LCHAT_TURN_SECRET environment variable.
        'secret' => getenv('LCHAT_TURN_SECRET') ?: '',
        'ttl' => 3600,
    ],
    'call_transport' => 'relay',
    'public_url' => 'https://your-domain.example',
];
