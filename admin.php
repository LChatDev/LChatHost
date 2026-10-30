<?php
// Standalone administrator page: upload only admin.php beside the existing index.php.
// Room signaling and call UI are bundled copies of the current LChat implementation.
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
require __DIR__ . '/apis/sync.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
function admin_page_extra_ids(): array {
    $row = one('SELECT value FROM settings WHERE name = ?', ['admin_extra_ids']);
    $ids = json_decode($row['value'] ?? '[]', true);
    if (!is_array($ids)) return [];
    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
}
function admin_page_ids(): array {
    return array_values(array_unique(array_filter(array_merge([service_admin_id()], admin_page_extra_ids()))));
}
function admin_page_is_admin(array $user): bool { return in_array((int)$user['id'], admin_page_ids(), true); }
function admin_page_lock(int $uid): void {
    query('UPDATE settings SET value = value WHERE name = ?', ['admin_owner_id']);
    if (!one('SELECT name FROM settings WHERE name = ?', ['admin_extra_ids'])) {
        query('INSERT INTO settings (name, value) VALUES (?, ?)', ['admin_extra_ids', '[]']);
    }
    query('UPDATE settings SET value = value WHERE name = ?', ['admin_extra_ids']);
    if (!in_array($uid, admin_page_ids(), true) || service_ban($uid)) throw new DomainException('Права администратора отозваны.');
}
function admin_page_manage(string $action, array $data, array $user, array $ctx): void {
    if ($action === 'me') {
        $user['is_admin'] = true;
        result(['user' => $user, 'csrf' => $ctx['csrf'], 'public_url' => config()['public_url'] ?? '', 'registration_open' => site_settings()['registration_open'], 'call_state' => account_call_state($ctx['uid'])]);
    }
    if (in_array($action, ['site.settings', 'site.save', 'site.reset'], true)) {
        if ((int)$user['id'] !== service_admin_id()) fail(403, 'Настройки сервера доступны только владельцу.');
        if ($action === 'site.settings') {
            $s = site_settings();
            $turnUrls = $s['turn']['urls'];
            result(['site_name' => $s['site_name'], 'registration_open' => $s['registration_open'], 'max_call_participants' => $s['max_call_participants'], 'call_transport' => $s['call_transport'], 'turn_urls' => is_array($turnUrls) ? (string)($turnUrls[0] ?? '') : (string)$turnUrls, 'turn_multiple' => is_array($turnUrls) && count($turnUrls) > 1, 'turn_secret_set' => !empty($s['turn']['secret']), 'turn_ttl' => $s['turn']['ttl']]);
        }
        $values = $action === 'site.save' ? site_settings_validate($data) : [];
        transaction(function() use ($action, $user, $values) {
            admin_page_lock((int)$user['id']);
            if ((int)$user['id'] !== service_admin_id()) throw new DomainException('Права владельца изменились.');
            $keys = ['site_name','registration_open','max_call_participants','call_transport','turn_urls','turn_secret','turn_ttl'];
            if ($action === 'site.reset') {
                foreach ($keys as $key) query('DELETE FROM settings WHERE name = ?', ['site_' . $key]);
            } else {
                foreach ($values as $key => $value) {
                    query('DELETE FROM settings WHERE name = ?', ['site_' . $key]);
                    query('INSERT INTO settings (name, value) VALUES (?, ?)', ['site_' . $key, $value]);
                }
            }
            admin_audit((int)$user['id'], $action, 'site settings');
        });
        result(['ok' => true]);
    }
    if ($action === 'admins') {
        $ids = admin_page_ids();
        $rows = $ids ? query('SELECT id, username, name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id', $ids)->fetchAll() : [];
        result(['admins' => $rows, 'owner_id' => service_admin_id(), 'can_manage' => (int)$user['id'] === service_admin_id()]);
    }
    if ($action === 'transfer') fail(404, 'Передача прав отключена. Используйте добавление администратора.');
    if (in_array($action, ['admin.add', 'admin.remove'], true)) {
        if ((int)$user['id'] !== service_admin_id()) fail(403, 'Управлять администраторами может только владелец.');
        if ($action === 'admin.add') {
            $username = strtolower(ltrim(field($data, 'username', 3, 33), '@'));
            $target = one('SELECT id FROM users WHERE username = ?', [$username]);
        } else $target = one('SELECT id FROM users WHERE id = ?', [(int)($data['user_id'] ?? 0)]);
        if (!$target) fail(404, 'Пользователь не найден. Сначала он должен зарегистрироваться.');
        transaction(function() use ($action, $target, $user) {
            admin_page_lock((int)$user['id']);
            if ((int)$user['id'] !== service_admin_id()) throw new DomainException('Права владельца изменились.');
            if ((int)$target['id'] === service_admin_id()) throw new DomainException('Владелец сохраняет права администратора.');
            if ($action === 'admin.add' && service_ban((int)$target['id'])) throw new DomainException('Сначала разблокируйте пользователя.');
            $ids = admin_page_extra_ids();
            if ($action === 'admin.add') {
                if (in_array((int)$target['id'], $ids, true)) return;
                $ids[] = (int)$target['id'];
            } else $ids = array_values(array_diff($ids, [(int)$target['id']]));
            $json = json_encode($ids, JSON_THROW_ON_ERROR);
            if (count($ids) > 20 || strlen($json) > 250) throw new DomainException('Можно добавить до 20 администраторов.');
            query('UPDATE settings SET value = ? WHERE name = ?', [$json, 'admin_extra_ids']);
            if ($action === 'admin.remove') {
                query('UPDATE participants SET left_at = ? WHERE user_id = ? AND name LIKE ? AND left_at IS NULL', [stamp(), $target['id'], '% · администратор']);
            }
            admin_audit((int)$user['id'], $action, (string)$target['id']);
        }); result(['ok' => true]);
    }
    if ($action === 'file') {
        $file = one('SELECT * FROM message_files WHERE message_id = ?', [(int)($data['id'] ?? 0)]);
        if (!$file || !preg_match('/^[a-f0-9]{48}\.bin$/D', $file['stored_name'])) fail(404, 'Файл не найден.');
        $path = __DIR__ . '/files/' . $file['stored_name'];
        if (!is_file($path)) fail(404, 'Файл недоступен.');
        admin_audit((int)$user['id'], 'file.download', (string)$file['message_id']);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
        header('Content-Length: ' . filesize($path));
        header("Content-Security-Policy: sandbox; default-src 'none'");
        readfile($path); exit;
    }
}
function admin_page_moderation(string $action, array $data, array $user): void {
    if ($action === 'chat.delete') {
        $messageId = (int)($data['message_id'] ?? 0);
        $chatId = (int)($data['chat_id'] ?? 0);
        if ($messageId < 1 || $chatId < 1) fail(422, 'Укажите сообщение и чат.');
        $stored = transaction(function() use ($messageId, $chatId, $user) {
            admin_page_lock((int)$user['id']);
            $message = one('SELECT m.chat_id, f.stored_name FROM messages m LEFT JOIN message_files f ON f.message_id = m.id WHERE m.id = ?', [$messageId]);
            if (!$message) return null;
            if ((int)$message['chat_id'] !== $chatId) throw new DomainException('Сообщение находится в другом чате.');
            query('DELETE FROM messages WHERE id = ? AND chat_id = ?', [$messageId, $chatId]);
            admin_audit((int)$user['id'], 'message.delete', 'chat:' . $chatId . ' message:' . $messageId);
            return $message['stored_name'];
        });
        if (is_string($stored) && preg_match('/^[a-f0-9]{48}\.bin$/D', $stored)) {
            $path = __DIR__ . '/files/' . $stored;
            if (is_file($path) && !unlink($path)) error_log('LChat: cannot remove deleted message attachment ' . $messageId);
        }
        result(['ok' => true]);
    }
    if ($action === 'ban') {
        $target = (int)($data['user_id'] ?? 0);
        if (!is_bool($data['blocked'] ?? null)) fail(422, 'Укажите состояние блокировки.');
        if (!one('SELECT id FROM users WHERE id = ?', [$target])) fail(404, 'Пользователь не найден.');
        $reason = field(['reason' => $data['reason'] ?? 'Нарушение правил'], 'reason', 1, 200);
        transaction(function() use ($target, $reason, $data, $user) {
            admin_page_lock((int)$user['id']);
            if (in_array($target, admin_page_ids(), true)) throw new DomainException('Нельзя заблокировать администратора.');
            query('DELETE FROM user_bans WHERE user_id = ?', [$target]);
            if ($data['blocked']) {
                query('INSERT INTO user_bans (user_id, reason, created_by, created_at) VALUES (?, ?, ?, ?)', [$target, $reason, $user['id'], stamp()]);
                query('UPDATE participants SET left_at = ? WHERE user_id = ? AND left_at IS NULL', [stamp(), $target]);
            }
            admin_audit((int)$user['id'], $data['blocked'] ? 'user.ban' : 'user.unban', (string)$target);
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
        $body = message_text($data); $nonce = field($data, 'nonce', 16, 64);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $nonce)) fail(422, 'Некорректный идентификатор сообщения.');
        $sentMessageId = transaction(function() use ($chatId, $body, $nonce, $user): int {
            admin_page_lock((int)$user['id']);
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

function admin_room(string $token): array {
    if (!preg_match('/^[a-f0-9]{48}$/D', $token)) fail(404, 'Звонок не найден.');
    $room = one('SELECT * FROM rooms WHERE token = ?', [$token]);
    if (!$room) fail(404, 'Звонок не найден.');
    if ($room['ended_at'] || $room['expires_at'] < stamp()) fail(410, 'Звонок уже завершён.');
    return $room;
}
try {
    $ctx = context(); ensure_features();
    $user = $ctx['uid'] ? one('SELECT id, username, name FROM users WHERE id = ?', [$ctx['uid']]) : null;
    if (!$user || !admin_page_is_admin($user) || service_ban((int)$user['id'])) {
        if (isset($_GET['action'])) fail(403, 'Требуются права администратора.');
        fail(403, 'Требуются права администратора.');
    }
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
        $data = $_GET;
        if (in_array($action, ['me','admins','file','overview','users','room.info','chats','chat.messages','audit','site.settings'], true)) {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, 'Используйте GET.');
        } else {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Используйте POST.');
            if (!hash_equals($ctx['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Обновите страницу и повторите действие.');
            $maxRequest = $action === 'chat.send' ? MESSAGE_REQUEST_MAX_BYTES : 65536;
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxRequest) fail(413, 'Слишком большой запрос.');
            $raw = file_get_contents('php://input', false, null, 0, $maxRequest + 1);
            if ($raw === false || strlen($raw) > $maxRequest) fail(413, 'Слишком большой запрос.');
            $data = json_decode($raw, true);
            if (!is_array($data)) fail(400, 'Ожидается JSON.');
        }
        if ($action === 'overview') {
            $stats = [];
            foreach (['users','chats','messages'] as $table) $stats[$table] = (int)one("SELECT COUNT(*) AS n FROM $table")['n'];
            $rooms = query('SELECT r.id, r.token, r.title, r.is_public, u.username AS owner, COUNT(p.id) AS participants FROM rooms r JOIN users u ON u.id = r.owner_id JOIN participants p ON p.room_id = r.id AND p.left_at IS NULL AND p.last_seen >= ? WHERE r.ended_at IS NULL AND r.expires_at > ? GROUP BY r.id, r.token, r.title, r.is_public, u.username ORDER BY r.id DESC LIMIT 50', [stamp(-35), stamp()])->fetchAll();
            result(['stats' => $stats, 'rooms' => $rooms]);
        }
        if ($action === 'users') {
            $search = $data['q'] ?? '';
            if (!is_string($search) || mb_strlen($search) > 60) fail(422, 'Поиск: до 60 символов.');
            $search = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], trim($search)) . '%';
            $after = max(0, (int)($data['after'] ?? 0));
            $users = query("SELECT u.id, u.username, u.name, u.email, u.created_at, u.last_seen, b.reason AS ban_reason FROM users u LEFT JOIN user_bans b ON b.user_id = u.id WHERE u.id > ? AND (u.username LIKE ? ESCAPE '!' OR u.name LIKE ? ESCAPE '!') ORDER BY u.id LIMIT 51", [$after, $search, $search])->fetchAll();
            $more = count($users) > 50; $users = array_slice($users, 0, 50);
            result(['users' => user_call_flags($users), 'has_more' => $more, 'next' => $users ? (int)end($users)['id'] : $after, 'admin_id' => service_admin_id(), 'admin_ids' => admin_page_ids()]);
        }
        admin_page_manage($action, $data, $user, $ctx); admin_page_moderation($action, $data, $user);
        if (str_starts_with($action, 'room.')) {
        $token = field($data, 'token', 48, 48); $r = admin_room($token);
        if ($action === 'room.info') result(['room' => ['title' => $r['title'], 'video' => (bool)$r['video'], 'is_public' => (bool)$r['is_public'], 'owner' => true], 'limit' => site_settings()['max_call_participants']]);
        if ($action === 'room.end') {

            transaction(function() use ($user, $r) { admin_page_lock((int)$user['id']); query('UPDATE rooms SET ended_at = ? WHERE id = ?', [stamp(), $r['id']]); admin_audit((int)$user['id'], 'call.end', (string)$r['id']); }); result(['ok' => true]);
        }
        if ($action === 'room.join') {
            rate('join:' . $ctx['actor'], 25, 600);
            $u = $ctx['uid'] ? require_user($ctx) : null;
            $name = mb_substr($u['name'], 0, 45) . ' · администратор';
            $hidden = !empty($data['listen']); $id = bin2hex(random_bytes(16));
            transaction(function() use ($ctx, $r, $id, $u, $name, $hidden) {
                admin_page_lock((int)$u['id']); assert_account_available((int)$u['id']); admin_audit((int)$u['id'], 'call.join', (string)$r['id']);
                // Serialize admission by locking the room row; SQLite serializes writes.
                query('UPDATE rooms SET title = title WHERE id = ?', [$r['id']]);
                $fresh = one('SELECT ended_at FROM rooms WHERE id = ?', [$r['id']]);
                if ($fresh['ended_at']) throw new DomainException('Звонок завершён.');
                $n = one('SELECT COUNT(*) AS n FROM participants WHERE room_id = ? AND left_at IS NULL AND last_seen >= ?', [$r['id'], stamp(-35)]);
                if ((int)$n['n'] >= (int)(site_settings()['max_call_participants'])) throw new DomainException('Комната заполнена.');
                query('INSERT INTO participants (id, room_id, owner_key, user_id, name, last_seen, joined_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $r['id'], hash('sha256', $ctx['actor']), $u['id'] ?? null, $name, stamp(), stamp()]);
                query('INSERT INTO call_moderators (participant_id, tile_hidden) VALUES (?, ?)', [$id, $hidden ? 1 : 0]);
                group_call_started($r, (int)($u['id'] ?? 0));
            });
            result(['id' => $id, 'ice_servers' => ice_servers($ctx), 'ice_transport_policy' => call_transport()]);
        }
        $id = field($data, 'id', 32, 32); participant($r, $id, $ctx);
        if ($action === 'room.poll') {
            query('UPDATE participants SET last_seen = ?, muted = ?, camera = ? WHERE id = ?', [stamp(), !empty($data['muted']) ? 1 : 0, !empty($data['camera']) ? 1 : 0, $id]);
            $peers = query('SELECT p.id, p.name, p.muted, p.camera, CASE WHEN cm.participant_id IS NULL THEN 0 ELSE 1 END AS is_admin, COALESCE(cm.tile_hidden, 0) AS tile_hidden FROM participants p LEFT JOIN call_moderators cm ON cm.participant_id = p.id WHERE p.room_id = ? AND p.id <> ? AND p.left_at IS NULL AND p.last_seen >= ? ORDER BY p.joined_at, p.id', [$r['id'], $id, stamp(-35)])->fetchAll();
            $after = max(0, (int)($data['after'] ?? 0));
            $signals = query('SELECT id, sender, payload FROM signals WHERE room_id = ? AND recipient = ? AND id > ? ORDER BY id LIMIT 200', [$r['id'], $id, $after])->fetchAll();
            foreach ($signals as &$s) $s['payload'] = json_decode($s['payload'], true);
            unset($s);
            // Acknowledge only previously processed signals, never delete unread ones.
            query('DELETE FROM signals WHERE recipient = ? AND id <= ?', [$id, $after]);
            $reply = ['peers' => $peers, 'signals' => $signals, 'incoming_calls' => account_incoming_calls($ctx['uid'])];
            if (!empty($data['refresh_ice'])) $reply['ice_servers'] = ice_servers($ctx);
            result($reply);
        }
        if ($action === 'room.signals') {
            rate('signal-batch:' . $id, 120, 60);
            $packets = $data['packets'] ?? null;
            if (!is_array($packets) || !$packets || count($packets) > 24) fail(422, 'Некорректный пакет сигналов.');
            $results = transaction(function() use ($packets, $r, $id) {
                $results = [];
                $insert = db()->prepare('INSERT INTO signals (room_id, sender, recipient, payload, created_at) VALUES (?, ?, ?, ?, ?)');
                foreach ($packets as $packet) {
                    $to = is_array($packet) ? ($packet['to'] ?? '') : ''; $payload = is_array($packet) ? ($packet['payload'] ?? null) : null;
                    if (!is_string($to) || !preg_match('/^[a-f0-9]{32}$/D', $to) || !is_array($payload) || !in_array($payload['type'] ?? '', ['offer', 'answer', 'candidate', 'restart'], true)) { $results[] = ['status' => 422, 'error' => 'Некорректный сигнал.']; continue; }
                    if (!one('SELECT id FROM participants WHERE id = ? AND room_id = ? AND left_at IS NULL AND last_seen >= ?', [$to, $r['id'], stamp(-35)])) { $results[] = ['status' => 404, 'error' => 'Участник покинул звонок.']; continue; }
                    $json = json_encode($payload, JSON_THROW_ON_ERROR);
                    if (strlen($json) > 48000) { $results[] = ['status' => 413, 'error' => 'Слишком большой сигнал.']; continue; }
                    $insert->execute([$r['id'], $id, $to, $json, stamp()]); $results[] = ['ok' => true];
                }
                return $results;
            }); result(['results' => $results]);
        }
        if ($action === 'room.signal') {
            rate('signal:' . $id, 1200, 60);
            $to = field($data, 'to', 32, 32);
            if (!one('SELECT id FROM participants WHERE id = ? AND room_id = ? AND left_at IS NULL AND last_seen >= ?', [$to, $r['id'], stamp(-35)])) fail(404, 'Участник покинул звонок.');
            $payload = $data['payload'] ?? null;
            if (!is_array($payload) || !in_array($payload['type'] ?? '', ['offer', 'answer', 'candidate', 'restart'], true)) fail(422, 'Некорректный сигнал.');
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            if (strlen($json) > 48000) fail(413, 'Слишком большой сигнал.');
            query('INSERT INTO signals (room_id, sender, recipient, payload, created_at) VALUES (?, ?, ?, ?, ?)', [$r['id'], $id, $to, $json, stamp()]); result(['ok' => true]);
        }
        if ($action === 'room.leave') {
            transaction(function() use ($r, $id) {
                query('UPDATE rooms SET title = title WHERE id = ?', [$r['id']]);
                query('UPDATE participants SET left_at = ? WHERE id = ?', [stamp(), $id]);
                $live = one('SELECT COUNT(*) AS n FROM participants WHERE room_id = ? AND left_at IS NULL AND last_seen >= ?', [$r['id'], stamp(-35)]);
                if (!$live['n']) query('UPDATE rooms SET ended_at = ? WHERE id = ?', [stamp(), $r['id']]);
            }); result(['ok' => true]);
        }
        fail(404, 'Действие не найдено.');
    }

        fail(404, 'Действие не найдено.');
    }
} catch (DomainException $e) { fail(409, $e->getMessage()); }
catch (Throwable $e) { error_log('LChat admin: ' . $e->getMessage()); fail(503, 'Админка временно недоступна. Проверьте журнал PHP.'); }
fail(404, 'Доступны только действия API.');
