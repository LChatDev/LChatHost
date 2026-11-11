<?php
$config = require __DIR__ . '/config.example.php';
$config['database'] = [
    'dsn' => 'mysql:host=db;dbname=lchat;charset=utf8mb4',
    'username' => 'lchat',
    'password' => getenv('DB_PASSWORD'),
];
$config['proxy_secret'] = getenv('PROXY_SECRET');
$config['install_key'] = getenv('INSTALL_KEY');
$config['public_url'] = getenv('WEB_URL');
$config['turn'] = ['urls' => getenv('TURN_URL'), 'secret' => getenv('TURN_SECRET'), 'ttl' => 3600];
$config['call_transport'] = 'all';
return $config;
