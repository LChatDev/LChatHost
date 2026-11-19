<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
$id=(string)($_GET['id']??'');
if (!preg_match('/^gift_[0-9]{19}$/D',$id)) { http_response_code(404); exit; }
$file=__DIR__.'/assets/lchat-gifts/tgs/'.$id.'.tgs';
if (!is_file($file) || filesize($file)>300000) { http_response_code(404); exit; }
$compressed=file_get_contents($file);$json=$compressed===false?false:gzdecode($compressed,4000000);
if ($json===false) { http_response_code(500); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $json;
