<?php
declare(strict_types=1);
define('LCHAT_INTERNAL',true);
require __DIR__.'/app.php';
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD']!=='GET') fail(405,'Используйте GET.');
    $ctx=context(); ensure_features(); $viewer=require_user($ctx); rate('profile-view:'.$viewer['id'],120,60); $id=(int)($_GET['id']??0); $chatId=(int)($_GET['chat_id']??0);
    if ($id!==(int)$viewer['id']) {
        $chat=member($chatId,(int)$viewer['id']);
        if ($chat['kind']!=='direct' || !one('SELECT user_id FROM members WHERE chat_id=? AND user_id=?',[$chatId,$id])) fail(404,'Профиль недоступен.');
    }
    $profile=one('SELECT u.id,u.name,u.username,u.last_seen,COALESCE(p.bio,\'\') AS bio,COALESCE(w.verified,0) AS verified FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id LEFT JOIN lchat_wallets w ON w.user_id=u.id WHERE u.id=?',[$id]);
    if (!$profile) fail(404,'Пользователь не найден.');
    $offset=max(0,min(600000,(int)($_GET['offset']??0)));
    $rows=query('SELECT o.nonce,o.gift FROM lchat_gift_orders o LEFT JOIN lchat_gift_recipients r ON r.nonce=o.nonce WHERE r.recipient_id=? OR (r.recipient_id IS NULL AND o.buyer_id=?) ORDER BY o.created_at DESC,o.nonce DESC LIMIT 101 OFFSET '.$offset,[$id,$id])->fetchAll();
    $hasMore=count($rows)>100;$rows=array_slice($rows,0,100);
    $gifts=[]; $catalog=rewards_catalog(); foreach($rows as $row) if(isset($catalog[$row['gift']])) $gifts[]=['id'=>$row['gift'],'fingerprint'=>$row['nonce'],'emoji'=>$catalog[$row['gift']][0],'name'=>$catalog[$row['gift']][1],'count'=>1,'sprite'=>(int)($catalog[$row['gift']][3]??-1),'variant'=>$catalog[$row['gift']][5]??'original','sheet'=>$catalog[$row['gift']][6]??'','tgs'=>$catalog[$row['gift']][7]??''];
    $shop=[];foreach($catalog as $key=>$item)$shop[]=['id'=>$key,'emoji'=>$item[0],'name'=>$item[1],'price'=>$item[2],'sprite'=>(int)($item[3]??-1),'variant'=>$item[5]??'original','sheet'=>$item[6]??'','tgs'=>$item[7]??''];
    result(['profile'=>$profile,'gifts'=>$gifts,'has_more'=>$hasMore,'next_offset'=>$offset+100,'catalog'=>$shop]);
} catch(Throwable $e) { error_log('LChat profile: '.$e->getMessage()); fail(500,'Не удалось открыть профиль.'); }
