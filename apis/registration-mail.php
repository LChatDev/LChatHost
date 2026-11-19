<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function registration_table(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS pending_registrations (token_hash CHAR(64) PRIMARY KEY, username VARCHAR(32) NOT NULL, email VARCHAR(190) NOT NULL, name VARCHAR(60) NOT NULL, password_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL)');
}
function registration_send(string $to, string $token, string $purpose = 'registration'): void {
    if (!in_array($purpose, ['registration', 'password_reset'], true)) throw new InvalidArgumentException('Invalid mail purpose');
    $mail = config()['smtp'] ?? [];
    $host = (string)($mail['host'] ?? '');
    $port = (int)($mail['port'] ?? 465);
    if ($host === '' || empty($mail['username']) || empty($mail['password'])) throw new RuntimeException('Configure SMTP in config.php before enabling email registration.');
    $baseUrl = rtrim((string)(config()['public_url'] ?? ''), '/');
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('Configure public_url before sending email.');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $s = stream_socket_client('tls://' . $host . ':' . $port, $errno, $error, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException('SMTP connection failed');
    stream_set_timeout($s,15);
    $read=static function($s,array $codes):void { $line=''; do { $line=fgets($s,4096); if ($line===false) throw new RuntimeException('SMTP read failed'); } while (isset($line[3]) && $line[3]==='-'); if (!in_array((int)substr($line,0,3),$codes,true)) throw new RuntimeException('SMTP rejected request'); };
    $cmd=static function($s,string $command,array $codes) use ($read):void { if (fwrite($s,$command."\r\n")===false) throw new RuntimeException('SMTP write failed'); $read($s,$codes); };
    try {
        $read($s,[220]); $cmd($s,'EHLO ' . (parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost'),[250]); $cmd($s,'AUTH LOGIN',[334]);
        $cmd($s,base64_encode($mail['username']),[334]); $cmd($s,base64_encode($mail['password']),[235]);
        $cmd($s,'MAIL FROM:<'.$mail['username'].'>',[250]); $cmd($s,'RCPT TO:<'.$to.'>',[250,251]); $cmd($s,'DATA',[354]);
        if ($purpose === 'password_reset') {
            $url=$baseUrl . '/reset-password.php#'.$token;
            $subject='=?UTF-8?B?'.base64_encode('Восстановление пароля LChat').'?=';
            $body="Здравствуйте!\r\n\r\nДля восстановления пароля откройте ссылку:\r\n$url\r\n\r\nСсылка действует 30 минут и может быть использована один раз. Если вы не запрашивали восстановление, проигнорируйте письмо.\r\n";
        } else {
            $url=$baseUrl . '/verify-email.php?token='.$token;
            $subject='=?UTF-8?B?'.base64_encode('Подтвердите email в LChat').'?=';
            $body="Здравствуйте!\r\n\r\nДля подтверждения адреса откройте ссылку:\r\n$url\r\n\r\nСсылка действует 24 часа. Если вы не регистрировались, проигнорируйте письмо.\r\n";
        }
        $headers="From: LChat <{$mail['username']}>\r\nTo: <$to>\r\nSubject: $subject\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $cmd($s,$headers."\r\n".rtrim(chunk_split(base64_encode($body),76,"\r\n"))."\r\n.",[250]);
        $cmd($s,'QUIT',[221]);
    } finally { fclose($s); }
}
