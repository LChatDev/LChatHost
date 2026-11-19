<?php
declare(strict_types=1);
define('LCHAT_INTERNAL',true);
require __DIR__.'/app.php';
require __DIR__.'/apis/registration-mail.php';
require __DIR__.'/apis/password-reset.php';
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$ctx=context(); $message=''; $error=''; $reset=false; $token=''; $success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!hash_equals($ctx['csrf'],(string)($_POST['csrf']??''))) throw new DomainException('Обновите страницу и повторите попытку.');
        $action=(string)($_POST['action']??'');
        if ($action==='request') {
            password_reset_request((string)($_POST['email']??''),(string)($_SERVER['REMOTE_ADDR']??'unknown'));
            $message='Если аккаунт с таким адресом существует, письмо с инструкцией будет отправлено. Проверьте также папку «Спам».';
        } elseif ($action==='reset') {
            $reset=true; $token=(string)($_POST['token']??'');
            if (!password_reset_allow('reset-complete-ip:'.($_SERVER['REMOTE_ADDR']??'unknown'),30,3600)) throw new DomainException('Слишком много попыток. Попробуйте позже.');
            $password=(string)($_POST['password']??''); $confirm=(string)($_POST['confirm']??'');
            if ($password!==$confirm) throw new DomainException('Пароли не совпадают.');
            password_reset_complete($token,$password); $token=''; $reset=false; $success=true;
            $message='Пароль изменён. Теперь вы можете войти с новым паролем.';
        } else throw new DomainException('Некорректный запрос.');
    } catch (Throwable $e) {
        error_log('LChat password recovery: '.get_class($e));
        $error=$e instanceof DomainException?$e->getMessage():'Не удалось выполнить запрос. Повторите попытку позже.';
    }
}
$esc=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Восстановление пароля · LChat</title><link rel="stylesheet" href="assets/reset-password.css?v=20261109-1"><script defer src="assets/reset-password.js?v=20261109-1"></script><script src="assets/lchatai-secondary-theme.js?v=20261111-1"></script><link rel="stylesheet" href="assets/lchatai-secondary.css?v=20261111-1"></head><body class="lchat-secondary lchat-reset"><main class="reset-card"><a class="reset-back" href="index.php">← Вернуться ко входу</a><div class="reset-logo">L</div><p class="reset-eyebrow">LCHAT · БЕЗОПАСНОСТЬ</p><h1>Восстановление пароля</h1><p class="reset-subtitle" id="request-description" <?=$reset||$success?'hidden':''?>>Укажите email аккаунта. Мы отправим ссылку для создания нового пароля.</p><p class="reset-subtitle" id="reset-description" <?=$reset&&!$success?'':'hidden'?>>Придумайте новый пароль для вашего аккаунта.</p>
<?php if($message):?><p class="reset-message" role="status"><?=$esc($message)?></p><?php endif;?><?php if($error):?><p class="reset-error" role="alert"><?=$esc($error)?></p><?php endif;?>
<?php if(!$success):?><form method="post" id="request-form" <?=$reset?'hidden':''?>><input type="hidden" name="csrf" value="<?=$esc($ctx['csrf'])?>"><input type="hidden" name="action" value="request"><label>Email<input type="email" name="email" maxlength="190" required autocomplete="email" placeholder="you@example.com"></label><button type="submit">Отправить ссылку <span>→</span></button></form>
<form method="post" id="reset-form" <?=$reset?'':'hidden'?>><input type="hidden" name="csrf" value="<?=$esc($ctx['csrf'])?>"><input type="hidden" name="action" value="reset"><input type="hidden" name="token" id="reset-token" value="<?=$esc($token)?>"><label>Новый пароль<input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required></label><label>Повторите пароль<input type="password" name="confirm" minlength="10" maxlength="72" autocomplete="new-password" required></label><p class="reset-help">Не менее 10 символов, максимум 72 байта.</p><button type="submit">Сохранить пароль <span>→</span></button><a class="request-again" href="reset-password.php">Запросить новую ссылку</a></form><?php else:?><a class="reset-button" href="index.php">Перейти ко входу →</a><?php endif;?>
<noscript><p class="reset-help">Для открытия ссылки восстановления требуется JavaScript.</p></noscript><p class="reset-foot">Если вы не запрашивали смену пароля, ничего делать не нужно.</p></main></body></html>
