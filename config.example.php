<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

return [
    // host: PHP and database run here. pc: forward application requests to an HTTPS LChat backend on your PC.
    'backend' => ['mode' => 'host', 'url' => ''],
    'database' => [
        'dsn' => 'mysql:host=localhost;port=3306;dbname=YOUR_DATABASE;charset=utf8mb4',
        'username' => 'YOUR_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
    ],
    'install_key' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET',
    'registration_open' => true,
    'session_name' => 'lchat_session',
    'session_domain' => '',
    // WebRTC соединяет участников друг с другом; используйте небольшой лимит.
    'max_call_participants' => 8,
    'ice_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
    ],
    'turn' => [
        'urls' => '',
        'secret' => '',
        'ttl' => 3600,
    ],
    'call_transport' => 'all',
    'public_url' => 'https://your-domain.example',
];
