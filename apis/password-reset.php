<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }
function password_reset_install(): void {
    $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    db()->exec('CREATE TABLE IF NOT EXISTS password_reset_tokens (token_hash CHAR(64) PRIMARY KEY, user_id INTEGER NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL)' . $suffix);
}
function password_reset_allow(string $key, int $max, int $seconds): bool {
    $key=hash('sha256',$key); $now=time(); $end=$now+$seconds;
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        query('INSERT INTO rate_limits (bucket,hits,reset_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=IF(reset_at < ?,1,hits+1), reset_at=IF(reset_at < ?,?,reset_at)',[$key,$end,$now,$now,$end]);
    } else {
        query('INSERT INTO rate_limits (bucket,hits,reset_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=CASE WHEN reset_at < ? THEN 1 ELSE hits+1 END,reset_at=CASE WHEN reset_at < ? THEN ? ELSE reset_at END',[$key,$end,$now,$now,$end]);
    }
    return (int)(one('SELECT hits FROM rate_limits WHERE bucket=?',[$key])['hits']??0) <= $max;
}
function password_reset_request(string $email,string $ip): void {
    $email=strtolower(trim($email));
    if (!password_reset_allow('reset-ip:'.$ip,12,3600)) return;
    if (strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)) return;
    if (!password_reset_allow('reset-email:'.$email,3,3600)) return;
    $user=one('SELECT u.id,u.email FROM users u WHERE u.email=? AND NOT EXISTS (SELECT 1 FROM bot_accounts b WHERE b.bot_user_id=u.id) AND NOT EXISTS (SELECT 1 FROM system_bots b WHERE b.user_id=u.id)',[$email]);
    if (!$user) return;
    password_reset_install();
    $token=bin2hex(random_bytes(32)); $digest=hash('sha256',$token);
    query('DELETE FROM password_reset_tokens WHERE expires_at < ?', [stamp()]);
    query('INSERT INTO password_reset_tokens (token_hash,user_id,expires_at,created_at) VALUES (?,?,?,?)',[$digest,$user['id'],stamp(1800),stamp()]);
    try { registration_send($user['email'],$token,'password_reset'); }
    catch (Throwable $e) { query('DELETE FROM password_reset_tokens WHERE token_hash=?',[$digest]); error_log('LChat password reset mail: '.get_class($e)); }
}
function password_reset_complete(string $token,string $password): void {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new DomainException('Ссылка недействительна или срок её действия истёк.');
    if (!mb_check_encoding($password,'UTF-8') || mb_strlen($password,'UTF-8')<10 || strlen($password)>72) throw new DomainException('Пароль: минимум 10 символов и максимум 72 байта.');
    password_reset_install();
    $digest=hash('sha256',$token); $hash=password_hash($password,PASSWORD_DEFAULT);
    transaction(function() use ($digest,$hash) {
        $lock=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $row=one('SELECT user_id FROM password_reset_tokens WHERE token_hash=? AND expires_at>=?'.$lock,[$digest,stamp()]);
        if (!$row) throw new DomainException('Ссылка недействительна или срок её действия истёк.');
        if (query('UPDATE users SET password_hash=? WHERE id=?',[$hash,$row['user_id']])->rowCount()!==1) throw new DomainException('Аккаунт не найден.');
        query('DELETE FROM password_reset_tokens WHERE user_id=?',[$row['user_id']]);
    });
}
