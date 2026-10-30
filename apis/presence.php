<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }
function account_call_state(int $uid): array {
    $active = $uid ? one('SELECT p.id AS participant_id, r.id AS room_id, r.token, r.title FROM participants p JOIN rooms r ON r.id = p.room_id WHERE p.user_id = ? AND p.left_at IS NULL AND p.last_seen >= ? AND r.ended_at IS NULL AND r.expires_at > ? ORDER BY p.joined_at DESC LIMIT 1', [$uid, stamp(-35), stamp()]) : null;
    return $active ? ['status' => 'in_call'] + $active : ['status' => 'idle'];
}
function assert_account_available(int $uid): void {
    if (!$uid) return;
    query('UPDATE users SET last_seen = last_seen WHERE id = ?', [$uid]);
    if (account_call_state($uid)['status'] === 'in_call') throw new DomainException('Вы уже в звонке в другой вкладке или приложении. Завершите текущий разговор.');
}
function account_incoming_calls(int $uid, ?array $active = null): array {
    $active ??= account_call_state($uid);
    $roomId = (int)($active['room_id'] ?? 0);
    return query('SELECT r.token, r.title, r.video, r.chat_id, r.owner_id AS caller_id, u.name AS caller FROM rooms r JOIN members m ON m.chat_id = r.chat_id JOIN users u ON u.id = r.owner_id WHERE m.user_id = ? AND r.owner_id <> ? AND r.id <> ? AND r.ended_at IS NULL AND r.created_at >= ? AND r.expires_at > ? AND EXISTS (SELECT 1 FROM participants p WHERE p.room_id = r.id AND p.left_at IS NULL AND p.last_seen >= ?) AND NOT EXISTS (SELECT 1 FROM participants self WHERE self.room_id = r.id AND self.user_id = ? ) AND NOT EXISTS (SELECT 1 FROM participants peer WHERE peer.room_id = ? AND peer.user_id = r.owner_id AND peer.left_at IS NULL AND peer.last_seen >= ?) ORDER BY r.created_at DESC LIMIT 10', [$uid, $uid, $roomId, stamp(-90), stamp(), stamp(-35), $uid, $roomId, stamp(-35)])->fetchAll();
}
function user_call_flags(array $users): array {
    if (!$users) return $users;
    $ids = array_values(array_unique(array_map(static fn($u) => (int)$u['id'], $users)));
    $rows = query('SELECT DISTINCT p.user_id FROM participants p JOIN rooms r ON r.id = p.room_id WHERE p.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND p.left_at IS NULL AND p.last_seen >= ? AND r.ended_at IS NULL AND r.expires_at > ?', array_merge($ids, [stamp(-35), stamp()]))->fetchAll();
    $busy = array_fill_keys(array_map(static fn($row) => (int)$row['user_id'], $rows), true);
    foreach ($users as &$user) $user['in_call'] = isset($busy[(int)$user['id']]);
    unset($user); return $users;
}
