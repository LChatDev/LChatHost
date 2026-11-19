<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: sandbox allow-scripts allow-forms allow-modals; default-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'none'; form-action 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'");

$id = strtolower((string)($_GET['site'] ?? ''));
$meta = temp_site_get($id);
if (!$meta) { http_response_code(404); echo 'Временный сайт не найден.'; exit; }
if ((int)($meta['expires_at'] ?? 0) <= time()) { temp_site_remove($id); http_response_code(410); echo 'Срок действия сайта истёк.'; exit; }
$relative = trim(rawurldecode((string)($_GET['path'] ?? '')), '/');
if ($relative === '') {
    foreach (['index.html', 'index.htm', 'index.php'] as $index) if (in_array($index, $meta['files'] ?? [], true)) { $relative = $index; break; }
}
if (!temp_site_valid_path($relative) || !in_array($relative, $meta['files'] ?? [], true)) { http_response_code(404); echo 'Файл не найден.'; exit; }
$file = temp_site_root() . '/' . $id . '/files/' . $relative;
if (!is_file($file)) { http_response_code(404); echo 'Файл не найден.'; exit; }
$extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
$types = ['html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'php' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8', 'txt' => 'text/plain; charset=utf-8', 'svg' => 'image/svg+xml'];
header('Content-Type: ' . ($types[$extension] ?? 'text/plain; charset=utf-8'));
$source = (string)file_get_contents($file);
echo $extension === 'php' ? temp_site_php($source) : $source;
