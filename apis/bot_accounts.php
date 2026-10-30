<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function bot_request_token(): string {
    $authorization = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (is_string($_SERVER[$key] ?? null) && trim($_SERVER[$key]) !== '') { $authorization = trim($_SERVER[$key]); break; }
    }
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headers = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : [];
    if ($authorization === '' && is_string($headers['authorization'] ?? null)) $authorization = trim($headers['authorization']);
    if (preg_match('/^Bearer\s+(.+)$/iD', $authorization, $match)) return trim($match[1]);
    foreach ([$_SERVER['HTTP_X_LCHAT_BOT_TOKEN'] ?? null, $headers['x-lchat-bot-token'] ?? null, $_GET['token'] ?? null] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') return trim($candidate);
    }
    return '';
}

function bot_account_create(int $ownerId, string $name, string $username): array {
    $name = trim($name);
    $username = strtolower(trim(ltrim($username, '@')));
    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 60) throw new DomainException('Имя бота: от 2 до 60 символов.');
    if (!preg_match('/^[a-z][a-z0-9_]{2,28}bot$/D', $username)) throw new DomainException('Логин должен начинаться с буквы и заканчиваться на bot; разрешены латинские буквы, цифры и _.');
    $create = static function () use ($ownerId, $name, $username): array {
        query('UPDATE users SET name = name WHERE id = ?', [$ownerId]);
        $count = one('SELECT COUNT(*) AS n FROM bot_accounts WHERE owner_user_id = ?', [$ownerId]);
        if ((int)$count['n'] >= 10) throw new DomainException('Можно создать не больше 10 ботов.');
        try {
            $email = 'bot-' . bin2hex(random_bytes(12)) . '@lchat.invalid';
            query('INSERT INTO users (username, email, name, password_hash, created_at, last_seen) VALUES (?, ?, ?, ?, ?, ?)', [$username, $email, $name, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), stamp(), stamp()]);
        } catch (PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) throw new DomainException('Этот логин уже занят.', 0, $e);
            throw $e;
        }
        $id = (int)db()->lastInsertId();
        $token = $id . ':' . bin2hex(random_bytes(24));
        query('INSERT INTO bot_accounts (bot_user_id, owner_user_id, token_hash, created_at) VALUES (?, ?, ?, ?)', [$id, $ownerId, hash('sha256', $token), stamp()]);
        return ['id' => $id, 'username' => $username, 'token' => $token];
    };
    return db()->inTransaction() ? $create() : transaction($create);
}

function bot_account_rotate(int $ownerId, int $botId): string {
    if ($botId < 1 || !one('SELECT bot_user_id FROM bot_accounts WHERE bot_user_id = ? AND owner_user_id = ?', [$botId, $ownerId])) throw new DomainException('Бот не найден.');
    $token = $botId . ':' . bin2hex(random_bytes(24));
    query('UPDATE bot_accounts SET token_hash = ? WHERE bot_user_id = ? AND owner_user_id = ?', [hash('sha256', $token), $botId, $ownerId]);
    return $token;
}
