<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
try {
    ensure_features(); $ctx = context(); $user = require_user($ctx);
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) fail(405, 'Используйте GET.');
    $file = one('SELECT f.*, m.chat_id, v.duration AS voice_duration FROM message_files f JOIN messages m ON m.id = f.message_id LEFT JOIN message_voice v ON v.message_id = m.id WHERE f.message_id = ?', [(int)($_GET['id'] ?? 0)]);
    if (!$file) fail(404, 'Файл не найден.');
    if (!service_is_admin($user)) member((int)$file['chat_id'], (int)$user['id']);
    else admin_audit((int)$user['id'], 'file.download', (string)$file['message_id']);
    if (!preg_match('/^[a-f0-9]{48}\.bin$/D', $file['stored_name'])) fail(404, 'Файл не найден.');
    $path = __DIR__ . '/files/' . $file['stored_name'];
    if (!is_file($path)) fail(404, 'Файл больше недоступен.');
    $play = ($_GET['play'] ?? '') === '1' && $file['voice_duration'] !== null;
    $size = (int)filesize($path); $start = 0; $end = $size - 1;
    if ($play && isset($_SERVER['HTTP_RANGE'])) {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $_SERVER['HTTP_RANGE'], $range) || ($range[1] === '' && $range[2] === '')) { http_response_code(416);header('Content-Range: bytes */'.$size);exit; }
        if ($range[1] === '') $start = max(0, $size - (int)$range[2]);
        else { $start = (int)$range[1]; if ($range[2] !== '') $end = min($end, (int)$range[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416);header('Content-Range: bytes */'.$size);exit; }
        http_response_code(206); header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Type: '.($play ? $file['mime'] : 'application/octet-stream'));
    header('Content-Disposition: '.($play?'inline':'attachment').'; filename="download"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
    if($play)header('Accept-Ranges: bytes');
    header('Content-Length: '.($end-$start+1));
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");
    if($_SERVER['REQUEST_METHOD']==='GET'){$stream=fopen($path,'rb');fseek($stream,$start);$left=$end-$start+1;while($left>0&&!feof($stream)){ $chunk=fread($stream,min(65536,$left));if($chunk===false||$chunk==='')break;echo $chunk;$left-=strlen($chunk);}fclose($stream);}
} catch (Throwable $e) { error_log('LChat download: ' . $e->getMessage()); fail(500, 'Не удалось скачать файл.'); }