<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }
function active_calls(int $uid): array {
    return query('SELECT r.id, r.token, r.chat_id, r.title, r.video, r.owner_id, COUNT(p.id) AS participants FROM rooms r JOIN members m ON m.chat_id = r.chat_id JOIN participants p ON p.room_id = r.id AND p.left_at IS NULL AND p.last_seen >= ? WHERE m.user_id = ? AND r.ended_at IS NULL AND r.expires_at > ? GROUP BY r.id, r.token, r.chat_id, r.title, r.video, r.owner_id ORDER BY r.id DESC', [stamp(-35), $uid, stamp()])->fetchAll();
}
function message_page(int $chatId, int $uid, array $data, ?array $calls = null): array {
    member($chatId, $uid);
    $after = max(0, (int)($data['after'] ?? 0)); $before = max(0, (int)($data['before'] ?? 0));
    $base = 'SELECT m.id, m.user_id, m.body, m.nonce, m.created_at, m.reply_to_id, m.edited_at, SUBSTR(reply.body, 1, 500) AS reply_body, reply_user.name AS reply_name, u.name, u.username, i.kind AS invite_kind, i.token AS invite_token, i.title AS invite_title, e.kind AS event_kind, e.token AS event_token, f.original_name AS file_name, f.size AS file_size, v.duration AS voice_duration, mm.message_id AS moderator, k.markup_json AS reply_markup FROM messages m JOIN users u ON u.id = m.user_id LEFT JOIN messages reply ON reply.id = m.reply_to_id AND reply.chat_id = m.chat_id LEFT JOIN users reply_user ON reply_user.id = reply.user_id LEFT JOIN message_invites i ON i.message_id = m.id LEFT JOIN message_events e ON e.message_id = m.id LEFT JOIN message_files f ON f.message_id = m.id LEFT JOIN message_voice v ON v.message_id = m.id LEFT JOIN moderator_messages mm ON mm.message_id = m.id LEFT JOIN bot_message_markup k ON k.message_id = m.id WHERE m.chat_id = ?';
    // Choose IDs using byte lengths before loading bodies; never return an unbounded batch of large messages.
    $length = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'OCTET_LENGTH(body)' : 'LENGTH(CAST(body AS BLOB))';
    $limit = $after ? 100 : 50;
    $condition = $after ? ' AND id > ?' : ($before ? ' AND id < ?' : '');
    $params = ($after || $before) ? [$chatId, $after ?: $before] : [$chatId];
    $order = $after ? 'ASC' : 'DESC';
    $meta = query('SELECT id, ' . $length . ' AS bytes FROM messages WHERE chat_id = ?' . $condition . ' ORDER BY id ' . $order . ' LIMIT ' . $limit, $params)->fetchAll();
    $ids = []; $total = 0;
    foreach ($meta as $item) {
        $size = (int)$item['bytes'];
        if ($ids && $total + $size > MESSAGE_TEXT_MAX_BYTES) break;
        $ids[] = (int)$item['id']; $total += $size;
    }
    $hasMore = count($ids) < count($meta) || count($meta) === $limit;
    $rows = $ids ? query($base . ' AND m.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY m.id ASC', array_merge([$chatId], $ids))->fetchAll() : [];
    $people = query('SELECT u.id, u.name, u.username, u.last_seen, m.role, m.last_read FROM users u JOIN members m ON m.user_id = u.id WHERE m.chat_id = ? ORDER BY u.name', [$chatId])->fetchAll();
    $calls ??= active_calls($uid);
    $active = null;
    foreach ($calls as $call) if ((int)$call['chat_id'] === $chatId) { $active = $call; break; }
    $known = array_values(array_unique(array_filter(array_map('intval', array_slice(explode(',',substr((string)($data['known_ids'] ?? ''),0,8192)),0,200)),static fn($id)=>$id>0)));
    $removed = [];
    if ($known) {
        $present = query('SELECT id FROM messages WHERE chat_id=? AND id IN ('.implode(',',array_fill(0,count($known),'?')).')',array_merge([$chatId],$known))->fetchAll();
        $removed = array_values(array_diff($known,array_map(static fn($m)=>(int)$m['id'],$present)));
    }
    $reactionIds = array_values(array_unique(array_merge($known, array_map(static fn($m) => (int)$m['id'], $rows))));
    $reactionState = [];
    if ($reactionIds) {
        $grouped = array_fill_keys($reactionIds, []);
        $reactionRows = query('SELECT r.message_id, r.user_id, r.emoji FROM message_reactions r JOIN messages m ON m.id = r.message_id WHERE m.chat_id = ? AND r.message_id IN (' . implode(',', array_fill(0, count($reactionIds), '?')) . ')', array_merge([$chatId], $reactionIds))->fetchAll();
        foreach ($reactionRows as $reaction) {
            $mid = (int)$reaction['message_id']; $emoji = $reaction['emoji'];
            $grouped[$mid][$emoji] ??= ['emoji' => $emoji, 'count' => 0, 'mine' => false];
            $grouped[$mid][$emoji]['count']++;
            if ((int)$reaction['user_id'] === $uid) $grouped[$mid][$emoji]['mine'] = true;
        }
        foreach ($grouped as $mid => $items) $reactionState[] = ['message_id' => (int)$mid, 'reactions' => array_values($items)];
    }
    $editAfter = max(0, (int)($data['edit_after'] ?? 0));
    $pollingEdits = $after > 0 || $editAfter > 0;
    $edits = $pollingEdits ? query('SELECT e.id AS event_id, m.id AS message_id, m.body, m.edited_at, k.markup_json AS reply_markup FROM message_edits e JOIN messages m ON m.id = e.message_id LEFT JOIN bot_message_markup k ON k.message_id = m.id WHERE e.chat_id = ? AND e.id > ? ORDER BY e.id LIMIT 1', [$chatId, $editAfter])->fetchAll() : [];
    $editCursor = $edits ? (int)end($edits)['event_id'] : ($pollingEdits ? $editAfter : (int)(one('SELECT MAX(id) AS id FROM message_edits WHERE chat_id = ?', [$chatId])['id'] ?? 0));
    return ['messages' => $rows, 'edited_messages' => $edits, 'edit_cursor' => $editCursor, 'reaction_state' => $reactionState, 'removed_ids' => $removed, 'members' => $people, 'active_call' => $active, 'bot_keyboard' => bot_platform_keyboard($chatId, $uid), 'has_more' => $hasMore];
}
function group_call_started(array $room, int $uid): void {
    if (!$room['chat_id'] || !$uid) return;
    $chat = one('SELECT kind FROM chats WHERE id = ?', [$room['chat_id']]);
    if (($chat['kind'] ?? '') !== 'group' || one('SELECT message_id FROM message_events WHERE room_id = ? AND kind = ?', [$room['id'], 'call_started'])) return;
    query('INSERT INTO messages (chat_id, user_id, body, nonce, created_at) VALUES (?, ?, ?, ?, ?)', [$room['chat_id'], $uid, 'Звонок начался', 'system_' . bin2hex(random_bytes(20)), stamp()]);
    $id = (int)db()->lastInsertId();
    query('INSERT INTO message_events (message_id, kind, room_id, token) VALUES (?, ?, ?, ?)', [$id, 'call_started', $room['id'], $room['token']]);
    bot_platform_publish_message($id);
}
