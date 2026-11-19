<?php
declare(strict_types=1);
define('LCHAT_INTERNAL',true);
require __DIR__.'/app.php';
header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
$ctx=context(); ensure_features(); $user=require_user($ctx); $admin=service_is_admin($user); $error=''; $notice=''; $successGift=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!hash_equals($ctx['csrf'],(string)($_POST['csrf']??''))) throw new DomainException('Обновите страницу и повторите действие.');
        $action=(string)($_POST['action']??'');
        if ($action==='buy') {
            $gift=(string)($_POST['gift']??'');$nonce=(string)($_POST['nonce']??'');
            $recipient=rewards_purchase($user,$gift,(string)($_POST['recipient']??''),$nonce);
            $successGift=$gift;$notice=(int)$recipient['id']===(int)$user['id']?'Подарок добавлен в вашу коллекцию.':'Подарок отправлен пользователю @'.$recipient['username'].'.';
        } elseif (in_array($action,['grant','verify'],true) && $admin) {
            $username=strtolower(ltrim(trim((string)($_POST['username']??'')),'@'));
            if (!preg_match('/^[a-z0-9_]{3,32}$/D',$username)) throw new DomainException('Укажите логин пользователя.');
            $target=one('SELECT id FROM users WHERE username=?',[$username]); if (!$target) throw new DomainException('Пользователь не найден.');
            $amount=filter_var($_POST['amount']??null,FILTER_VALIDATE_INT);
            if ($action==='grant' && ($amount===false || $amount<1 || $amount>100000)) throw new DomainException('Количество: от 1 до 100000 тикетов.');
            $grantNonce=(string)($_POST['nonce']??'');
            if ($action==='grant' && !preg_match('/^[a-f0-9]{48}$/D',$grantNonce)) throw new DomainException('Некорректный запрос.');
            $checked=(string)($_POST['verified']??'')==='1' ? 1 : 0;
            transaction(function() use ($user,$target,$action,$amount,$checked,$grantNonce) {
                $uid=(int)$user['id']; $id=(int)$target['id'];
                query('UPDATE users SET name=name WHERE id=?',[$uid]);
                $fresh=one('SELECT id,username FROM users WHERE id=?',[$uid]);
                if (!$fresh || !service_is_admin($fresh) || service_ban($uid)) throw new DomainException('Права администратора отозваны.');
                query('UPDATE users SET name=name WHERE id=?',[$id]);
                if (!one('SELECT user_id FROM lchat_wallets WHERE user_id=?',[$id])) query('INSERT INTO lchat_wallets (user_id,tickets,verified) VALUES (?,0,0)',[$id]);
                if ($action==='grant') { $prior=one('SELECT actor_id,user_id,amount FROM lchat_ticket_grants WHERE nonce=?',[$grantNonce]); if ($prior) { if ((int)$prior['actor_id']!==$uid || (int)$prior['user_id']!==$id || (int)$prior['amount']!==$amount) throw new DomainException('Некорректный запрос.'); return; } if (query('UPDATE lchat_wallets SET tickets=tickets+? WHERE user_id=? AND tickets<=?',[$amount,$id,1000000000-$amount])->rowCount()!==1) throw new DomainException('Превышен допустимый баланс.'); query('INSERT INTO lchat_ticket_grants (nonce,actor_id,user_id,amount,created_at) VALUES (?,?,?,?,?)',[$grantNonce,$uid,$id,$amount,stamp()]); }
                else query('UPDATE lchat_wallets SET verified=? WHERE user_id=?',[$checked,$id]);
                admin_audit($uid,$action==='grant'?'tickets.grant':'verification.set',"user:$id value:".($action==='grant'?$amount:$checked));
            }); $notice=$action==='grant'?'Тикеты выданы.':'Отметка обновлена.';
        } else throw new DomainException('Действие недоступно.');
    } catch (Throwable $e) { error_log('LChat rewards: '.$e->getMessage()); $error=$e instanceof DomainException?$e->getMessage():'Не удалось выполнить действие.'; }
}
$wallet=rewards_wallet((int)$user['id']);
$orders=query('SELECT o.gift,COUNT(*) AS n FROM lchat_gift_orders o LEFT JOIN lchat_gift_recipients r ON r.nonce=o.nonce WHERE r.recipient_id=? OR (r.recipient_id IS NULL AND o.buyer_id=?) GROUP BY o.gift',[$user['id'],$user['id']])->fetchAll();
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*60;
$instances=query('SELECT o.nonce,o.gift FROM lchat_gift_orders o LEFT JOIN lchat_gift_recipients r ON r.nonce=o.nonce WHERE r.recipient_id=? OR (r.recipient_id IS NULL AND o.buyer_id=?) ORDER BY o.created_at DESC,o.nonce DESC LIMIT 61 OFFSET '.$offset,[$user['id'],$user['id']])->fetchAll();
$hasMore=count($instances)>60;$instances=array_slice($instances,0,60);
$received=query('SELECT o.gift,o.created_at,u.username FROM lchat_gift_orders o JOIN lchat_gift_recipients r ON r.nonce=o.nonce JOIN users u ON u.id=o.buyer_id WHERE r.recipient_id=? AND o.buyer_id<>? ORDER BY o.created_at DESC LIMIT 20',[$user['id'],$user['id']])->fetchAll();
$counts=[]; foreach($orders as $row) $counts[$row['gift']]=(int)$row['n'];
$catalog=rewards_catalog(); $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$prefill=ltrim(trim((string)($_GET['recipient']??'')),'@'); if(!preg_match('/^[a-zA-Z0-9_]{3,32}$/D',$prefill))$prefill='';
require __DIR__.'/gift-market-view.php';
