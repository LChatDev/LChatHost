<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function moderation_action(string $action, array $data, array $user): void {
    if ($action === 'ban') {
        $target = (int)($data['user_id'] ?? 0);
        if (!is_bool($data['blocked'] ?? null)) fail(422, 'Укажите состояние блокировки.');
        if (!one('SELECT id FROM users WHERE id = ?', [$target])) fail(404, 'Пользователь не найден.');
        $reason = field(['reason' => $data['reason'] ?? 'Нарушение правил'], 'reason', 1, 200);
        transaction(function() use ($target, $reason, $data, $user) {
            admin_lock((int)$user['id']);
            if ($target === service_admin_id()) throw new DomainException('Нельзя заблокировать администратора.');
            query('DELETE FROM user_bans WHERE user_id = ?', [$target]);
            if ($data['blocked']) {
                query('INSERT INTO user_bans (user_id, reason, created_by, created_at) VALUES (?, ?, ?, ?)', [$target, $reason, $user['id'], stamp()]);
                query('UPDATE participants SET left_at = ? WHERE user_id = ? AND left_at IS NULL', [stamp(), $target]);
            }
            admin_audit((int)$user['id'], $data['blocked'] ? 'user.ban' : 'user.unban', (string)$target);
        }); result(['ok' => true]);
    }
    if ($action === 'transfer') {
        rate('admin-transfer:' . $user['id'], 10, 3600);
        $username = strtolower(ltrim(field($data, 'username', 3, 33), '@'));
        $target = one('SELECT id, username FROM users WHERE username = ?', [$username]);
        if (!$target || (int)$target['id'] === (int)$user['id'] || service_ban((int)$target['id'])) fail(422, 'Выберите другой существующий незаблокированный аккаунт.');
        $password = $data['password'] ?? '';
        $hash = one('SELECT password_hash FROM users WHERE id = ?', [$user['id']])['password_hash'];
        if (!is_string($password) || strlen($password) > 72 || !password_verify($password, $hash)) fail(403, 'Неверный пароль текущего администратора.');
        if (($data['confirm'] ?? '') !== $target['username']) fail(422, 'Подтвердите передачу прав.');
        transaction(function() use ($target, $user) {
            admin_lock((int)$user['id']);
            if (service_ban((int)$target['id'])) throw new DomainException('Аккаунт заблокирован.');
            query('UPDATE settings SET value = ? WHERE name = ?', [(string)$target['id'], 'admin_owner_id']);
            admin_audit((int)$user['id'], 'admin.transfer', (string)$target['id']);
        }); result(['ok' => true]);
    }
    if ($action === 'chats') {
        $search = $data['q'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 60) fail(422, 'Поиск: до 60 символов.');
        $pattern = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], trim($search)) . '%';
        $after = max(0, (int)($data['after'] ?? 0));
        $rows = query("SELECT c.id, c.title, c.kind, c.created_at, (SELECT GROUP_CONCAT(u.username) FROM users u JOIN members m ON m.user_id = u.id WHERE m.chat_id = c.id) AS people FROM chats c WHERE c.id > ? AND (c.title LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM users u JOIN members m ON m.user_id = u.id WHERE m.chat_id = c.id AND u.username LIKE ? ESCAPE '!')) ORDER BY c.id LIMIT 51", [$after, $pattern, $pattern])->fetchAll();
        $more = count($rows) > 50; $rows = array_slice($rows, 0, 50);
        result(['chats' => $rows, 'has_more' => $more, 'next' => $rows ? (int)end($rows)['id'] : $after]);
    }
    if ($action === 'chat.messages') {
        $chatId = (int)($data['chat_id'] ?? 0);
        $chat = one('SELECT id, kind, title FROM chats WHERE id = ?', [$chatId]);
        if (!$chat) fail(404, 'Чат не найден.');
        admin_audit((int)$user['id'], 'chat.view', (string)$chatId);
        result(['chat' => $chat] + moderation_messages($chatId, $data));
    }
    if ($action === 'chat.send') {
        $chatId = (int)($data['chat_id'] ?? 0);
        if (!one('SELECT id FROM chats WHERE id = ?', [$chatId])) fail(404, 'Чат не найден.');
        $body = field($data, 'body', 1, 4000); $nonce = field($data, 'nonce', 16, 64);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $nonce)) fail(422, 'Некорректный идентификатор сообщения.');
        $sentMessageId = transaction(function() use ($chatId, $body, $nonce, $user): int {
            admin_lock((int)$user['id']);
            $existing = one('SELECT id, chat_id FROM messages WHERE user_id = ? AND nonce = ?', [$user['id'], $nonce]);
            if ($existing) { if ((int)$existing['chat_id'] !== $chatId) throw new DomainException('Идентификатор уже использован.'); return (int)$existing['id']; }
            query('INSERT INTO messages (chat_id, user_id, body, nonce, created_at) VALUES (?, ?, ?, ?, ?)', [$chatId, $user['id'], $body, $nonce, stamp()]);
            $messageId = (int)db()->lastInsertId();
            query('INSERT INTO moderator_messages (message_id) VALUES (?)', [$messageId]);
            admin_audit((int)$user['id'], 'chat.send', (string)$chatId);
            return $messageId;
        });
        bot_platform_publish_message($sentMessageId);
        result(['ok' => true]);
    }
    if ($action === 'audit') result(['events' => query('SELECT a.id, a.action, a.target, a.created_at, u.username FROM admin_audit a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 100')->fetchAll()]);
}
