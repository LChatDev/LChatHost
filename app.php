<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

function config(): array {
    static $config;
    if ($config === null) {
        $path = getenv('LCHAT_CONFIG') ?: __DIR__ . '/config.php';
        if (!is_file($path)) fail(503, 'Сначала настройте config.php и откройте install.php.');
        $config = require $path;
        $local = dirname($path) . '/selfhost/settings.php';
        if (is_file($local)) {
            $overrides = require $local;
            $config = array_replace($config, array_intersect_key($overrides, array_flip(['ice_servers', 'turn', 'call_transport'])));
        }
        $publicFile = dirname($path) . '/selfhost/public.php';
        if (is_file($publicFile)) {
            $public = require $publicFile;
            if (is_array($public) && filter_var($public['public_url'] ?? '', FILTER_VALIDATE_URL) && parse_url($public['public_url'], PHP_URL_SCHEME) === 'https') $config['public_url'] = $public['public_url'];
        }
    }
    return $config;
}
function fail(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
function result(array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function stamp(int $offset = 0): string { return gmdate('Y-m-d H:i:s', time() + $offset); }
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $c = config()['database'];
        $pdo = new PDO($c['dsn'], $c['username'] ?? '', $c['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement {
    $s = db()->prepare($sql); $s->execute($params); return $s;
}
function one(string $sql, array $params = []): ?array { return query($sql, $params)->fetch() ?: null; }
function request_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return getenv('LCHAT_TRUST_LOCAL_PROXY') === '1' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
function session_open(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(config()['session_name'] ?? 'lchat_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => (string)(config()['session_domain'] ?? ''), 'httponly' => true, 'samesite' => 'Lax',
        'secure' => request_is_https(),
    ]);
    if (!session_start()) throw new RuntimeException('PHP cannot start a session. Check session.save_path and its write permissions.');
}
function context(): array {
    session_open();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $_SESSION['actor'] ??= bin2hex(random_bytes(32));
    $c = ['csrf' => $_SESSION['csrf'], 'actor' => $_SESSION['actor'], 'uid' => (int)($_SESSION['uid'] ?? 0)];
    if (!session_write_close()) throw new RuntimeException('PHP cannot save the session.'); // Polling does not hold the PHP session lock.
    return $c;
}
function login_session(int $id): void {
    session_open();
    if (!session_regenerate_id(true)) throw new RuntimeException('PHP cannot regenerate the login session.');
    $_SESSION['uid'] = $id; $_SESSION['csrf'] = bin2hex(random_bytes(32));
    if (!session_write_close()) throw new RuntimeException('PHP cannot save the login session.');
}
function require_user(array $ctx): array {
    $user = $ctx['uid'] ? one('SELECT id, username, name, last_seen FROM users WHERE id = ?', [$ctx['uid']]) : null;
    if (!$user) fail(401, 'Войдите в аккаунт.');
    if (service_ban((int)$user['id'])) fail(403, 'Ваш аккаунт заблокирован администратором.');
    return $user;
}
function field(array $data, string $key, int $min, int $max): string {
    $v = $data[$key] ?? '';
    if (!is_string($v)) fail(422, 'Некорректное поле: ' . $key);
    $v = trim($v);
    $n = mb_strlen($v, 'UTF-8');
    if ($n < $min || $n > $max) fail(422, 'Поле «' . $key . '»: от ' . $min . ' до ' . $max . ' символов.');
    return $v;
}
function member(int $chat, int $uid): array {
    $row = one('SELECT c.*, m.role, m.last_read FROM chats c JOIN members m ON m.chat_id = c.id WHERE c.id = ? AND m.user_id = ?', [$chat, $uid]);
    if (!$row) fail(403, 'Нет доступа к этому чату.');
    return $row;
}
function rate(string $key, int $max, int $seconds): void {
    $key = hash('sha256', $key);
    $now = time(); $end = $now + $seconds;
    // Atomic increment avoids bypassing limits with concurrent requests.
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        query('INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = IF(reset_at < ?, 1, hits + 1), reset_at = IF(reset_at < ?, ?, reset_at)', [$key, $end, $now, $now, $end]);
    } else {
        query('INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, ?) ON CONFLICT(bucket) DO UPDATE SET hits = CASE WHEN reset_at < ? THEN 1 ELSE hits + 1 END, reset_at = CASE WHEN reset_at < ? THEN ? ELSE reset_at END', [$key, $end, $now, $now, $end]);
    }
    $r = one('SELECT hits FROM rate_limits WHERE bucket = ?', [$key]);
    if ((int)$r['hits'] > $max) { header('Retry-After: ' . $seconds); fail(429, 'Слишком много запросов. Попробуйте позже.'); }
}
function ice_servers(array $ctx): array {
    $c = config(); $servers = $c['ice_servers'] ?? [];
    $turn = $c['turn'] ?? [];
    if (!empty($turn['secret']) && !empty($turn['urls'])) {
        $username = (time() + (int)($turn['ttl'] ?? 3600)) . ':' . substr(hash('sha256', $ctx['actor']), 0, 12);
        $servers[] = ['urls' => $turn['urls'], 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, $turn['secret'], true))];
    }
    return $servers;
}
function call_transport(): string {
    return (config()['call_transport'] ?? 'all') === 'relay' ? 'relay' : 'all';
}
function room(string $token, array $ctx): array {
    if (!preg_match('/^[a-f0-9]{48}$/D', $token)) fail(404, 'Звонок не найден.');
    $r = one('SELECT * FROM rooms WHERE token = ?', [$token]);
    if (!$r) fail(404, 'Звонок не найден.');
    if (!$r['is_public']) member((int)$r['chat_id'], (int)require_user($ctx)['id']);
    if ($r['ended_at'] || $r['expires_at'] < stamp()) fail(410, 'Этот звонок уже завершён. Создайте новый.');
    return $r;
}
function participant(array $r, string $id, array $ctx): array {
    $p = one('SELECT * FROM participants WHERE id = ? AND room_id = ? AND owner_key = ? AND left_at IS NULL', [$id, $r['id'], hash('sha256', $ctx['actor'])]);
    if (!$p || $p['last_seen'] < stamp(-40)) fail(403, 'Подключение истекло. Войдите в звонок заново.');
    return $p;
}
function transaction(callable $action): mixed {
    db()->beginTransaction();
    try { $value = $action(); db()->commit(); return $value; }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
require_once __DIR__ . '/apis/features.php';
require_once __DIR__ . '/apis/bot_accounts.php';
require_once __DIR__ . '/apis/bot_creator.php';
require_once __DIR__ . '/apis/bot_platform.php';
require_once __DIR__ . '/apis/bot_files.php';
require_once __DIR__ . '/apis/presence.php';
