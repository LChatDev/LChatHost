<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }
function contacts(int $uid): array {
    return query('SELECT u.id, u.name, u.username, c.id AS chat_id FROM chats c JOIN members mine ON mine.chat_id = c.id JOIN members other ON other.chat_id = c.id JOIN users u ON u.id = other.user_id WHERE c.kind = ? AND mine.user_id = ? AND other.user_id <> ? ORDER BY u.name, u.id', ['direct', $uid, $uid])->fetchAll();
}
function send_invites(int $uid, array $ctx, array $data): array {
    rate('invite-send:' . $uid, 30, 60);
    $kind = field($data, 'kind', 4, 5); $token = field($data, 'token', 48, 48); $nonce = field($data, 'nonce', 16, 40);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $nonce)) fail(422, 'Некорректный идентификатор приглашения.');
    $ids = $data['user_ids'] ?? null;
    if (!is_array($ids) || !$ids || count($ids) > 20) fail(422, 'Выберите от 1 до 20 контактов.');
    $ids = array_unique(array_map(fn($id) => is_numeric($id) ? (int)$id : 0, $ids));
    if ($kind === 'group') {
        $invite = one('SELECT * FROM chats WHERE kind = ? AND invite_token = ?', ['group', $token]);
        if (!$invite) fail(404, 'Приглашение недействительно.');
        member((int)$invite['id'], $uid); $title = $invite['title'];
    } elseif ($kind === 'call') { $invite = room($token, $ctx); $title = $invite['title']; }
    else fail(422, 'Неизвестный тип приглашения.');
    $list = [];
    foreach (contacts($uid) as $contact) $list[(int)$contact['id']] = $contact;
    foreach ($ids as $id) {
        if (!isset($list[$id])) fail(403, 'Отправить приглашение можно только вашему контакту из личного чата.');
        if ($kind === 'call' && !$invite['is_public']) member((int)$invite['chat_id'], $id);
    }
    $sent = transaction(function() use ($ids, $list, $uid, $nonce, $kind, $token, $title) {
        query('UPDATE users SET last_seen = last_seen WHERE id = ?', [$uid]);
        $sent = [];
        foreach ($ids as $id) {
            $key = $nonce . '_' . $id;
            $existing = one('SELECT m.id, i.kind, i.token FROM messages m LEFT JOIN message_invites i ON i.message_id = m.id WHERE m.user_id = ? AND m.nonce = ?', [$uid, $key]);
            if ($existing) {
                if ($existing['kind'] !== $kind || $existing['token'] !== $token) throw new DomainException('Идентификатор приглашения уже использован.');
                $sent[] = $existing['id']; continue;
            }
            $body = ($kind === 'group' ? 'Приглашение в группу: ' : 'Приглашение в звонок: ') . $title;
            query('INSERT INTO messages (chat_id, user_id, body, nonce, created_at) VALUES (?, ?, ?, ?, ?)', [$list[$id]['chat_id'], $uid, $body, $key, stamp()]);
            $messageId = (int)db()->lastInsertId();
            query('INSERT INTO message_invites (message_id, kind, token, title) VALUES (?, ?, ?, ?)', [$messageId, $kind, $token, $title]);
            $sent[] = $messageId;
        }
        return $sent;
    });
    foreach ($sent as $messageId) bot_platform_publish_message((int)$messageId);
    return ['sent' => count($sent), 'message_ids' => $sent];
}
