<?php
declare(strict_types=1);
define('LCHAT_INTERNAL',true);
require __DIR__.'/app.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
    if($_SERVER['REQUEST_METHOD']!=='POST')fail(405,'Используйте POST.');
    $ctx=context();ensure_features();$user=require_user($ctx);
    if(!hash_equals($ctx['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))fail(403,'Обновите страницу и повторите действие.');
    if((int)($_SERVER['CONTENT_LENGTH']??0)>4096)fail(413,'Слишком большой запрос.');
    $data=json_decode((string)file_get_contents('php://input',false,null,0,4097),true);
    if(!is_array($data))fail(400,'Ожидается JSON.');
    foreach(['gift','recipient','nonce'] as $key)if(!is_string($data[$key]??null))fail(422,'Некорректные данные.');
    rate('gift-purchase:'.$user['id'],60,60);
    $recipient=rewards_purchase($user,$data['gift'],$data['recipient'],$data['nonce']);
    result(['ok'=>true,'recipient'=>$recipient['username'],'fingerprint'=>$data['nonce'],'balance'=>(int)rewards_wallet((int)$user['id'])['tickets']]);
} catch(Throwable $e) { error_log('LChat gift purchase: '.$e->getMessage());fail($e instanceof DomainException?422:500,$e instanceof DomainException?$e->getMessage():'Не удалось отправить подарок.'); }
