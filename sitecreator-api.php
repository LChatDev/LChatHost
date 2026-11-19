<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
    $ctx=context(); ensure_features(); $user=require_user($ctx); $uid=(int)$user['id'];
    if ($_SERVER['REQUEST_METHOD']==='GET') result(['permission'=>user_site_permission($uid),'site'=>user_site_info($uid),'files'=>user_site_files($uid),'csrf'=>$ctx['csrf']]);
    if ($_SERVER['REQUEST_METHOD']!=='POST') fail(405,'Используйте GET или POST.');
    if (!hash_equals($ctx['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail(403,'Обновите страницу и повторите действие.');
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>1200000) fail(413,'Слишком большой запрос.');
    $data=json_decode(file_get_contents('php://input',false,null,0,1200001)?:'',true); if(!is_array($data))fail(400,'Ожидается JSON.');
    $action=(string)($data['action']??'save');
    if($action==='delete'){user_site_delete($uid);result(['ok'=>true]);}
    if($action!=='save'||!is_array($data['files']??null))fail(422,'Некорректное действие.');
    result(['site'=>user_site_publish($uid,(string)($data['name']??''),$data['files'])]);
} catch(DomainException $e){fail(409,$e->getMessage());} catch(Throwable $e){error_log('LChat site creator: '.$e->getMessage());fail(500,'Не удалось сохранить сайт.');}
