<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function service_bot_install(): void {
    if (one('SELECT user_id FROM system_bots WHERE code = ?', ['service'])) return;
    $occupied = one('SELECT id FROM users WHERE username = ?', ['service']);
    if ($occupied) {
        error_log('LChat service bot: username service is occupied.');
        return;
    }
    transaction(static function (): void {
        query('INSERT INTO users (username, email, name, password_hash, created_at, last_seen) VALUES (?, ?, ?, ?, ?, ?)', ['service', 'system-service@lchat.invalid', 'LChat Service', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), stamp(), stamp()]);
        query('INSERT INTO system_bots (user_id, code) VALUES (?, ?)', [(int)db()->lastInsertId(), 'service']);
    });
}

function service_bot_id(): int {
    return (int)(one('SELECT user_id FROM system_bots WHERE code = ?', ['service'])['user_id'] ?? 0);
}

function service_bot_direct_chat(int $uid): int {
    $serviceId = service_bot_id();
    if (!$serviceId) throw new RuntimeException('Служебный бот пока не установлен.');
    $key = min($uid, $serviceId) . ':' . max($uid, $serviceId);
    $existing = one('SELECT id FROM chats WHERE direct_key = ?', [$key]);
    if ($existing) return (int)$existing['id'];
    try {
        return transaction(static function () use ($uid, $serviceId, $key): int {
            query('INSERT INTO chats (kind, title, owner_id, direct_key, created_at) VALUES (?, ?, ?, ?, ?)', ['direct', '', $uid, $key, stamp()]);
            $id = (int)db()->lastInsertId();
            foreach ([$uid, $serviceId] as $who) query('INSERT INTO members (chat_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$id, $who, 'member', stamp()]);
            return $id;
        });
    } catch (PDOException $e) {
        $existing = one('SELECT id FROM chats WHERE direct_key = ?', [$key]);
        if (!$existing) throw $e;
        return (int)$existing['id'];
    }
}

function service_bot_send(int $uid, string $text): void {
    $chatId = service_bot_direct_chat($uid);
    query('INSERT INTO messages (chat_id, user_id, body, nonce, created_at) VALUES (?, ?, ?, ?, ?)', [$chatId, service_bot_id(), message_store($text), 'service_' . bin2hex(random_bytes(20)), stamp()]);
}

function service_site_permission_request(int $uid, int $botId): array {
    $current = one('SELECT status, requested_at FROM site_permissions WHERE user_id = ? AND requested_by_bot = ?', [$uid, $botId]);
    if (($current['status'] ?? '') === 'approved') return ['status' => 'approved'];
    if (($current['status'] ?? '') === 'pending' && ($current['requested_at'] ?? '') >= stamp(-300)) return ['status' => 'pending'];
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        query('INSERT INTO site_permissions (user_id, requested_by_bot, status, requested_at, decided_at) VALUES (?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE status=VALUES(status), requested_at=VALUES(requested_at), decided_at=NULL', [$uid, $botId, 'pending', stamp()]);
    } else {
        query('INSERT INTO site_permissions (user_id, requested_by_bot, status, requested_at, decided_at) VALUES (?, ?, ?, ?, NULL) ON CONFLICT(user_id, requested_by_bot) DO UPDATE SET status=excluded.status, requested_at=excluded.requested_at, decided_at=NULL', [$uid, $botId, 'pending', stamp()]);
    }
    $botName = one('SELECT username FROM users WHERE id=?', [$botId])['username'] ?? ('id' . $botId);
    service_bot_send($uid, "🌐 Бот @{$botName} запросил разрешение создавать ваш постоянный сайт.\n\nСайт будет доступен публично по адресу chat.lbs.su/sites/ВАШ_ЛОГИН и останется там, пока вы его не удалите. Разрешён один сайт на аккаунт.\n\n/allow sitecreator — разрешить этому боту\n/deny sitecreator — отклонить\n/status — состояние");
    return ['status' => 'pending'];
}

function service_bot_answer(int $uid, string $body): string {
    $body = trim($body);
    if (preg_match('~^/(?:start|help)(?:@service)?$~iD', $body)) return "Я @service — системный бот разрешений LChat.\n\n/allow sitecreator — разрешить постоянный сайт\n/deny sitecreator — отозвать разрешение\n/status — показать состояние";
    if (preg_match('~^/allow(?:@service)?\s+sitecreator$~iD', $body)) {
        $request = one('SELECT requested_by_bot FROM site_permissions WHERE user_id = ? AND status = ? ORDER BY requested_at DESC LIMIT 1', [$uid, 'pending']);
        if (!$request) return 'Сначала запросите создание сайта у @compilerbot командой /sitecreator.';
        query('UPDATE site_permissions SET status = ?, decided_at = ? WHERE user_id = ? AND requested_by_bot = ?', ['approved', stamp(), $uid, $request['requested_by_bot']]);
        $name=one('SELECT username FROM users WHERE id=?',[$request['requested_by_bot']])['username']??'бот';
        return "✅ Разрешение выдано боту @{$name}. Вернитесь в его чат и повторите создание сайта.\n\nСайт можно открыть или удалить в настройках LChat.";
    }
    if (preg_match('~^/deny(?:@service)?\s+sitecreator$~iD', $body)) {
        $request=one('SELECT requested_by_bot FROM site_permissions WHERE user_id=? ORDER BY requested_at DESC LIMIT 1',[$uid]);
        if($request)query('UPDATE site_permissions SET status = ?, decided_at = ? WHERE user_id = ? AND requested_by_bot=?', ['denied', stamp(), $uid,$request['requested_by_bot']]);
        return 'Разрешение отозвано. Уже созданный сайт останется доступен, пока вы не удалите его в настройках.';
    }
    if (preg_match('~^/status(?:@service)?$~iD', $body)) {
        $rows=query('SELECT p.status,u.username FROM site_permissions p JOIN users u ON u.id=p.requested_by_bot WHERE p.user_id=? ORDER BY p.requested_at DESC',[$uid])->fetchAll();
        if(!$rows)return 'Разрешений на постоянные сайты пока нет.';
        return "Разрешения:\n".implode("\n",array_map(static fn($r)=>'@'.$r['username'].' — '.match($r['status']){'approved'=>'разрешено','pending'=>'ожидает решения',default=>'запрещено'},$rows));
    }
    return 'Неизвестная команда. Напишите /help.';
}

function service_bot_process(int $chatId, int $senderId, int $messageId, string $body): void {
    $serviceId = service_bot_id();
    if (!$serviceId || $senderId === $serviceId || one('SELECT bot_user_id FROM bot_accounts WHERE bot_user_id = ?', [$senderId])) return;
    $chat = one('SELECT c.kind FROM chats c JOIN members m ON m.chat_id=c.id AND m.user_id=? WHERE c.id=?', [$serviceId, $chatId]);
    if (($chat['kind'] ?? '') !== 'direct') return;
    transaction(static function () use ($chatId, $senderId, $messageId, $body, $serviceId): void {
        if (one('SELECT message_id FROM service_bot_handled WHERE message_id = ?', [$messageId])) return;
        query('INSERT INTO messages (chat_id, user_id, body, nonce, created_at) VALUES (?, ?, ?, ?, ?)', [$chatId, $serviceId, message_store(service_bot_answer($senderId, $body)), 'service_' . bin2hex(random_bytes(20)), stamp()]);
        query('INSERT INTO service_bot_handled (message_id) VALUES (?)', [$messageId]);
    });
}
