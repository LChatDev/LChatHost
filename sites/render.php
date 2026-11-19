<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require dirname(__DIR__) . '/app.php';
ensure_features();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; media-src data: blob:; font-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'");
$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
if (!preg_match('/^[a-z0-9_]{3,32}$/D', $slug)) { http_response_code(404); exit('Сайт не найден.'); }
$site = one('SELECT user_id FROM user_sites WHERE slug=?', [$slug]);
if (!$site) { http_response_code(404); exit('Сайт не найден.'); }
$path = trim((string)($_GET['path'] ?? ''), '/');
if ($path === '') {
    foreach (['index.html','index.htm','index.php'] as $index) if (is_file(user_site_root((int)$site['user_id']) . '/files/' . $index)) { $path = $index; break; }
}
if (!temp_site_valid_path($path)) { http_response_code(404); exit('Файл не найден.'); }
$root = realpath(user_site_root((int)$site['user_id']) . '/files');
$file = $root ? realpath($root . '/' . $path) : false;
if (!$root || !$file || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) { http_response_code(404); exit('Файл не найден.'); }
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = ['html'=>'text/html; charset=utf-8','htm'=>'text/html; charset=utf-8','php'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','json'=>'application/json; charset=utf-8','txt'=>'text/plain; charset=utf-8','svg'=>'image/svg+xml; charset=utf-8'];
header('Content-Type: ' . ($types[$extension] ?? 'text/plain; charset=utf-8'));
$source = file_get_contents($file);
if ($source === false) { http_response_code(500); exit('Не удалось прочитать файл.'); }
echo $extension === 'php' ? temp_site_php($source, $_GET) : $source;
