<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__.'/app.php';
require __DIR__.'/apis/registration-mail.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
$message=''; $token=(string)($_POST['token'] ?? $_GET['token'] ?? ''); $ctx=context();
try {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new RuntimeException('Ссылка недействительна.');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!hash_equals($ctx['csrf'],(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Обновите страницу и повторите попытку.');
        registration_table();
        transaction(function() use ($token) {
            $row=one('SELECT * FROM pending_registrations WHERE token_hash = ? AND expires_at >= ? FOR UPDATE',[hash('sha256',$token),stamp()]);
            if (!$row) throw new RuntimeException('Ссылка уже использована или истекла. Зарегистрируйтесь ещё раз.');
            query('INSERT INTO users (username,email,name,password_hash,created_at,last_seen) VALUES (?,?,?,?,?,?)',[$row['username'],$row['email'],$row['name'],$row['password_hash'],stamp(),stamp()]);
            query('DELETE FROM pending_registrations WHERE token_hash = ?',[$row['token_hash']]);
        });
        $message='Адрес подтверждён. Теперь вы можете войти в LChat.'; $token='';
    }
} catch (Throwable $e) { error_log('LChat verify email: '.$e->getMessage()); $message=$e instanceof PDOException?'Не удалось создать аккаунт. Возможно, логин или email уже занят.':$e->getMessage(); $token=''; }
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Подтверждение почты · LChat</title><link rel="stylesheet" href="assets/legal.css?v=1"></head><body><main class="page"><article><h1>Подтверждение почты</h1><?php if ($message): ?><p><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><a href="index.php">Перейти к входу</a><?php else: ?><p>Нажмите кнопку, чтобы подтвердить адрес и завершить регистрацию.</p><form method="post"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="csrf" value="<?=htmlspecialchars($ctx['csrf'],ENT_QUOTES,'UTF-8')?>"><button type="submit">Подтвердить почту</button></form><?php endif; ?></article></main></body></html>
