<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
require __DIR__ . '/apis/invites.php';
require __DIR__ . '/apis/sync.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $ctx = context();
    ensure_features();
    $action = $_GET['action'] ?? 'me';
    if ($ctx['uid'] && $action !== 'logout') require_user($ctx);
    $read = ['me', 'chats', 'sync', 'messages', 'users', 'room.info', 'invite.info', 'contacts', 'mobile.events', 'channel.info', 'bot.callback.status'];
    $data = $_GET;
    if (!in_array($action, $read, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Используйте POST.');
        if (!hash_equals($ctx['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Обновите страницу и повторите действие.');
        $maxRequest = in_array($action, ['message.send', 'message.edit'], true) ? MESSAGE_REQUEST_MAX_BYTES : 65536;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxRequest) fail(413, 'Слишком большой запрос.');
        $raw = file_get_contents('php://input', false, null, 0, $maxRequest + 1);
        if ($raw === false || strlen($raw) > $maxRequest) fail(413, 'Слишком большой запрос.');
        $data = json_decode($raw, true);
        if (!is_array($data)) fail(400, 'Ожидается JSON.');
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, 'Используйте GET.');
    if ($action === 'channel.info') {
        $slug = channel_slug($data);
        $channel = one('SELECT c.id,c.title,d.slug,(SELECT COUNT(*) FROM members WHERE chat_id=c.id) AS subscribers FROM chats c JOIN channel_details d ON d.chat_id=c.id WHERE d.slug=? AND c.kind=?',[$slug,'channel']);
        if(!$channel)fail(404,'Канал не найден.');result(['channel'=>$channel]);
    }
    if ($action === 'mobile.release') {
        $user = require_user($ctx); $id = field($data, 'id', 32, 32); $token = field($data, 'token', 48, 48);
        $lease = one('SELECT p.id, p.room_id, p.user_id, p.owner_key FROM participants p JOIN rooms r ON r.id = p.room_id WHERE p.id = ? AND r.token = ?', [$id, $token]);
        if (!$lease) fail(404, 'Подключение уже удалено.');
        if ((int)$lease['user_id'] !== (int)$user['id'] || !hash_equals($lease['owner_key'], hash('sha256', $ctx['actor']))) fail(403, 'Это подключение другой сессии.');
        query('UPDATE participants SET left_at = ? WHERE id = ?', [stamp(), $id]);
        result(['ok' => true]);
    }
    if ($action === 'mobile.events') {
        $user = require_user($ctx);
        $after = max(0, (int)($data['after'] ?? 0));
        $latest = one('SELECT MAX(m.id) AS id FROM messages m JOIN members mem ON mem.chat_id = m.chat_id WHERE mem.user_id = ?', [$user['id']]);
        $cursor = (int)($latest['id'] ?? 0); $items = [];
        if (array_key_exists('after', $data)) {
            $rows = query('SELECT m.id, m.chat_id, m.user_id, SUBSTR(m.body, 1, 500) AS body, m.created_at, u.name, c.title, c.kind, mem.last_read, e.kind AS event_kind FROM messages m JOIN members mem ON mem.chat_id = m.chat_id JOIN chats c ON c.id = m.chat_id JOIN users u ON u.id = m.user_id LEFT JOIN message_events e ON e.message_id = m.id WHERE mem.user_id = ? AND m.id > ? ORDER BY m.id LIMIT 100', [$user['id'], $after])->fetchAll();
            $cursor = $after;
            foreach ($rows as $row) {
                $cursor = max($cursor, (int)$row['id']);
                if ((int)$row['user_id'] !== (int)$user['id'] && (int)$row['id'] > (int)$row['last_read'] && !$row['event_kind'] && $row['created_at'] >= stamp(-86400)) {
                    unset($row['last_read'], $row['event_kind']); $items[] = $row;
                }
            }
        }
        $state = account_call_state((int)$user['id']);
        result(['user_id' => (int)$user['id'], 'csrf' => $ctx['csrf'], 'cursor' => $cursor, 'messages' => $items, 'incoming_calls' => account_incoming_calls((int)$user['id'], $state), 'call_state' => $state]);
    }
    if ($action === 'me') {
        $user = $ctx['uid'] ? one('SELECT id, username, name FROM users WHERE id = ?', [$ctx['uid']]) : null;
        if ($user) $user['is_admin'] = service_is_admin($user);
        result(['user' => $user, 'csrf' => $ctx['csrf'], 'site_name' => site_settings()['site_name'], 'registration_open' => site_settings()['registration_open'], 'public_url' => config()['public_url'] ?? '', 'call_state' => account_call_state($ctx['uid'])]);
    }
    if ($action === 'register' || $action === 'login') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rate('auth-ip:' . $ip, 30, 900);
        if ($action === 'register') {
            if (!(site_settings()['registration_open'])) fail(403, 'Регистрация закрыта.');
            rate('register:' . $ip, 8, 3600);
            $name = field($data, 'name', 2, 60);
            $username = strtolower(field($data, 'username', 3, 32));
            if (!preg_match('/^[a-z0-9_]{3,32}$/D', $username)) fail(422, 'Логин: латинские буквы, цифры и _, от 3 до 32 символов.');
            if (str_ends_with($username, 'bot') || $username === 'botcreator') fail(422, 'Этот логин зарезервирован для ботов.');
            $email = strtolower(field($data, 'email', 3, 190));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'Укажите корректный email.');
            $password = $data['password'] ?? '';
            if (!is_string($password) || mb_strlen($password, 'UTF-8') < 10 || strlen($password) > 72) fail(422, 'Пароль: минимум 10 символов, максимум 72 байта.');
            try {
                query('INSERT INTO users (username, email, name, password_hash, created_at, last_seen) VALUES (?, ?, ?, ?, ?, ?)', [$username, $email, $name, password_hash($password, PASSWORD_DEFAULT), stamp(), stamp()]);
            } catch (PDOException $e) {
                if (str_starts_with((string)$e->getCode(), '23')) fail(409, 'Этот логин или email уже занят.');
                throw $e;
            }
            login_session((int)db()->lastInsertId());
        } else {
            $login = strtolower(field($data, 'login', 3, 190));
            rate('auth-account:' . $login, 15, 900);
            $password = $data['password'] ?? '';
            $u = one('SELECT * FROM users WHERE username = ? OR email = ?', [$login, $login]);
            if ($u && (one('SELECT bot_user_id FROM bot_accounts WHERE bot_user_id = ?', [$u['id']]) || one('SELECT user_id FROM system_bots WHERE user_id = ?', [$u['id']]))) fail(401, 'Для бота используйте API-токен.');
            // Same expensive verification even for unknown usernames.
            $hash = $u['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
            if (!is_string($password) || strlen($password) > 72 || !password_verify($password, $hash) || !$u) fail(401, 'Неверный логин или пароль.');
            if (service_ban((int)$u['id'])) fail(403, 'Ваш аккаунт заблокирован администратором.');
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            login_session((int)$u['id']);
        }
        result(['ok' => true]);
    }
    if ($action === 'logout') {
        session_open(); $_SESSION = []; session_destroy();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'domain' => (string)(config()['session_domain'] ?? ''), 'httponly' => true, 'samesite' => 'Lax', 'secure' => request_is_https()]);
        result(['ok' => true]);
    }
    if (str_starts_with($action, 'room.')) {
        if ($action === 'room.create') {
            $u = require_user($ctx); rate('room-create:' . $u['id'], 20, 600);
            $chatId = (int)($data['chat_id'] ?? 0);
            $chat = $chatId ? member($chatId, (int)$u['id']) : null;
            if (($chat['kind'] ?? '') === 'channel') fail(422, 'Звонки доступны в чатах и группах.');
            $title = $chat ? ($chat['kind'] === 'direct' ? 'Личный звонок' : $chat['title']) : field($data, 'title', 2, 80);
            $public = $chat ? 0 : 1;
            $token = bin2hex(random_bytes(24));
            $token = transaction(function() use ($token, $title, $chatId, $u, $public, $data) {
                if ($chatId) {
                    query('UPDATE chats SET title = title WHERE id = ?', [$chatId]);
                    $existing = one('SELECT r.token FROM rooms r WHERE r.chat_id = ? AND r.ended_at IS NULL AND r.expires_at > ? AND (r.created_at >= ? OR EXISTS (SELECT 1 FROM participants p WHERE p.room_id = r.id AND p.left_at IS NULL AND p.last_seen >= ?)) ORDER BY r.id DESC LIMIT 1', [$chatId, stamp(), stamp(-90), stamp(-35)]);
                    if ($existing) return $existing['token'];
                }
                query('INSERT INTO rooms (token, title, chat_id, owner_id, is_public, video, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$token, $title, $chatId ?: null, $u['id'], $public, !empty($data['video']) ? 1 : 0, stamp(), stamp(21600)]);
                return $token;
            });
            result(['token' => $token]);
        }
        $token = field($data, 'token', 48, 48); $r = room($token, $ctx);
        if ($action === 'room.info') result(['room' => ['title' => $r['title'], 'video' => (bool)$r['video'], 'is_public' => (bool)$r['is_public'], 'owner' => (int)$r['owner_id'] === $ctx['uid']], 'limit' => site_settings()['max_call_participants']]);
        if ($action === 'room.end') {
            if ((int)$r['owner_id'] !== $ctx['uid']) fail(403, 'Завершить звонок может только создатель.');
            query('UPDATE rooms SET ended_at = ? WHERE id = ?', [stamp(), $r['id']]); result(['ok' => true]);
        }
        if ($action === 'room.join') {
            rate('join:' . $ctx['actor'], 25, 600);
            $u = $ctx['uid'] ? require_user($ctx) : null;
            $name = $u ? $u['name'] : field($data, 'name', 2, 60);
            $id = bin2hex(random_bytes(16));
            transaction(function() use ($ctx, $r, $id, $u, $name) {
                assert_account_available((int)($u['id'] ?? 0));
                // Serialize admission by locking the room row; SQLite serializes writes.
                query('UPDATE rooms SET title = title WHERE id = ?', [$r['id']]);
                $fresh = one('SELECT ended_at FROM rooms WHERE id = ?', [$r['id']]);
                if ($fresh['ended_at']) throw new DomainException('Звонок завершён.');
                $n = one('SELECT COUNT(*) AS n FROM participants WHERE room_id = ? AND left_at IS NULL AND last_seen >= ?', [$r['id'], stamp(-35)]);
                if ((int)$n['n'] >= (int)(site_settings()['max_call_participants'])) throw new DomainException('Комната заполнена.');
                query('INSERT INTO participants (id, room_id, owner_key, user_id, name, last_seen, joined_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $r['id'], hash('sha256', $ctx['actor']), $u['id'] ?? null, $name, stamp(), stamp()]);
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
            result(['peers' => $peers, 'signals' => $signals, 'incoming_calls' => $ctx['uid'] ? account_incoming_calls($ctx['uid']) : []]);
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
    $u = require_user($ctx); $uid = (int)$u['id'];
    if ($action === 'channel.create') {
        rate('chat-create:'.$uid,40,600);$slug=channel_slug($data);$title=field($data,'title',2,80);
        try{$id=transaction(function()use($uid,$slug,$title){query('INSERT INTO chats (kind,title,owner_id,created_at) VALUES (?,?,?,?)',['channel',$title,$uid,stamp()]);$id=(int)db()->lastInsertId();query('INSERT INTO channel_details (chat_id,slug) VALUES (?,?)',[$id,$slug]);query('INSERT INTO members (chat_id,user_id,role,joined_at) VALUES (?,?,?,?)',[$id,$uid,'owner',stamp()]);return $id;});}catch(PDOException $e){if(str_starts_with((string)$e->getCode(),'23'))fail(409,'Этот адрес канала уже занят.');throw $e;}result(['id'=>$id,'slug'=>$slug]);
    }
    if ($action === 'channel.join') {
        rate('channel-join:'.$uid,30,600);$slug=channel_slug($data);$c=one('SELECT chat_id FROM channel_details WHERE slug=?',[$slug]);if(!$c)fail(404,'Канал не найден.');
        if(!one('SELECT user_id FROM members WHERE chat_id=? AND user_id=?',[$c['chat_id'],$uid]))try{query('INSERT INTO members (chat_id,user_id,role,joined_at) VALUES (?,?,?,?)',[$c['chat_id'],$uid,'member',stamp()]);}catch(PDOException $e){if(!str_starts_with((string)$e->getCode(),'23'))throw $e;}result(['id'=>$c['chat_id']]);
    }
    if ($action === 'contacts') result(['contacts' => user_call_flags(contacts($uid))]);
    if ($action === 'invite.send') result(send_invites($uid, $ctx, $data));
    if ($action === 'chats' || $action === 'sync') {
        query('UPDATE users SET last_seen = ? WHERE id = ? AND last_seen < ?', [stamp(), $uid, stamp(-15)]);
        $chats = query("SELECT c.id, c.kind, c.title, c.owner_id, c.invite_token, c.created_at, m.role, (SELECT slug FROM channel_details WHERE chat_id=c.id) AS channel_slug,
            SUBSTR(latest.body, 1, 500) AS last_message, latest.created_at AS last_message_at, latest.id AS last_message_id, latest.user_id AS last_message_user_id,
            (SELECT id FROM messages WHERE chat_id = c.id AND user_id <> ? ORDER BY id DESC LIMIT 1) AS last_incoming_id,
            (SELECT COUNT(*) FROM messages WHERE chat_id = c.id AND id > m.last_read AND user_id <> ?) AS unread,
            (SELECT COUNT(*) FROM members WHERE chat_id = c.id) AS member_count
            FROM chats c JOIN members m ON m.chat_id = c.id LEFT JOIN messages latest ON latest.id = (SELECT MAX(id) FROM messages WHERE chat_id = c.id) WHERE m.user_id = ? ORDER BY COALESCE(latest.created_at, c.created_at) DESC, c.id DESC", [$uid, $uid, $uid])->fetchAll();
        foreach ($chats as &$c) {
            if ($c['kind'] === 'direct') {
                $p = one('SELECT u.id, u.name, u.username, u.last_seen FROM users u JOIN members m ON m.user_id = u.id WHERE m.chat_id = ? AND u.id <> ?', [$c['id'], $uid]);
                $c['title'] = $p['name']; $c['peer'] = user_call_flags([$p])[0];
            }
        } unset($c);
        $accountState = account_call_state($uid);
        $calls = account_incoming_calls($uid, $accountState);
        $activeCalls = active_calls($uid);
        $response = ['chats' => $chats, 'calls' => $calls, 'active_calls' => $activeCalls, 'call_state' => $accountState];
        if ($action === 'sync' && (int)($data['chat_id'] ?? 0)) $response += message_page((int)$data['chat_id'], $uid, $data, $activeCalls);
        result($response);
    }
    if ($action === 'users') {
        rate('search:' . $uid, 90, 60);
        $term = strtolower(ltrim(field($data, 'q', 2, 32), '@'));
        if (!preg_match('/^[a-z0-9_]+$/D', $term)) result(['users' => []]);
        $pattern = str_replace(['!', '_'], ['!!', '!_'], $term) . '%';
        result(['users' => query("SELECT id, username, name FROM users WHERE username LIKE ? ESCAPE '!' AND id <> ? ORDER BY username LIMIT 15", [$pattern, $uid])->fetchAll()]);
    }
    if ($action === 'bot.callback.status') {
        $id = (string)($data['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) fail(422, 'Некорректный идентификатор кнопки.');
        $callback = one('SELECT answer_text, answered_at FROM bot_callback_queries WHERE id = ? AND from_user_id = ?', [$id, $uid]);
        if (!$callback) fail(404, 'Нажатие не найдено.');
        result(['answered' => $callback['answered_at'] !== null, 'text' => $callback['answer_text'] ?? '']);
    }
    if ($action === 'chat.direct') {
        rate('chat-create:' . $uid, 40, 600);
        $other = (int)($data['user_id'] ?? 0);
        if ($other === $uid || !one('SELECT id FROM users WHERE id = ?', [$other])) fail(422, 'Выберите другого пользователя.');
        $key = min($uid, $other) . ':' . max($uid, $other);
        $existing = one('SELECT id FROM chats WHERE direct_key = ?', [$key]);
        if ($existing) result(['id' => $existing['id']]);
        try {
            $id = transaction(function() use ($uid, $other, $key) {
                query('INSERT INTO chats (kind, title, owner_id, direct_key, created_at) VALUES (?, ?, ?, ?, ?)', ['direct', '', $uid, $key, stamp()]);
                $id = (int)db()->lastInsertId();
                foreach ([$uid, $other] as $who) query('INSERT INTO members (chat_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$id, $who, 'member', stamp()]);
                return $id;
            });
        } catch (PDOException $e) {
            $existing = one('SELECT id FROM chats WHERE direct_key = ?', [$key]);
            if (!$existing) throw $e; $id = $existing['id'];
        }
        result(['id' => $id]);
    }
    if ($action === 'group.create') {
        rate('chat-create:' . $uid, 40, 600);
        $title = field($data, 'title', 2, 80);
        $id = transaction(function() use ($uid, $title) {
            query('INSERT INTO chats (kind, title, owner_id, invite_token, created_at) VALUES (?, ?, ?, ?, ?)', ['group', $title, $uid, bin2hex(random_bytes(24)), stamp()]);
            $id = (int)db()->lastInsertId();
            query('INSERT INTO members (chat_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$id, $uid, 'owner', stamp()]); return $id;
        }); result(['id' => $id]);
    }
    if ($action === 'invite.info' || $action === 'group.join') {
        $token = field($data, 'token', 48, 48);
        $c = one('SELECT id, title FROM chats WHERE invite_token = ? AND kind = ?', [$token, 'group']);
        if (!$c) fail(404, 'Приглашение недействительно.');
        if ($action === 'invite.info') result(['group' => $c]);
        rate('group-join:' . $uid, 30, 600);
        if (!one('SELECT user_id FROM members WHERE chat_id = ? AND user_id = ?', [$c['id'], $uid])) {
            try { query('INSERT INTO members (chat_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$c['id'], $uid, 'member', stamp()]); }
            catch (PDOException $e) { if (!str_starts_with((string)$e->getCode(), '23')) throw $e; }
        } result(['id' => $c['id']]);
    }
    $chatId = (int)($data['chat_id'] ?? 0); $chat = member($chatId, $uid);
    if ($action === 'bot.callback') {
        rate('bot-callback:' . $uid, 60, 60);
        $messageId = (int)($data['message_id'] ?? 0);
        $callbackData = $data['callback_data'] ?? null;
        if ($messageId < 1 || !is_string($callbackData) || strlen($callbackData) < 1 || strlen($callbackData) > 64) fail(422, 'Некорректная кнопка.');
        $target = one('SELECT m.user_id AS bot_id, k.markup_json FROM messages m JOIN bot_message_markup k ON k.message_id = m.id JOIN bot_accounts b ON b.bot_user_id = m.user_id JOIN members mem ON mem.chat_id = m.chat_id AND mem.user_id = m.user_id WHERE m.id = ? AND m.chat_id = ?', [$messageId, $chatId]);
        if (!$target || service_ban((int)$target['bot_id'])) fail(404, 'Кнопка больше недоступна.');
        $markup = json_decode($target['markup_json'], true);
        $matches = false;
        foreach (($markup['inline_keyboard'] ?? []) as $row) foreach ($row as $button) if (($button['callback_data'] ?? null) === $callbackData) $matches = true;
        if (!$matches) fail(404, 'Кнопка больше недоступна.');
        $id = bin2hex(random_bytes(16));
        query('INSERT INTO bot_callback_queries (id, bot_user_id, message_id, from_user_id, data, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$id, $target['bot_id'], $messageId, $uid, $callbackData, stamp()]);
        bot_platform_publish_callback((int)$target['bot_id'], $id);
        result(['id' => $id]);
    }
    if ($action === 'channel.update') {
        if($chat['kind']!=='channel'||$chat['role']!=='owner')fail(403,'Только владелец может менять адрес канала.');$slug=channel_slug($data);try{query('UPDATE channel_details SET slug=? WHERE chat_id=?',[$slug,$chatId]);}catch(PDOException $e){if(str_starts_with((string)$e->getCode(),'23'))fail(409,'Этот адрес уже занят.');throw $e;}result(['slug'=>$slug]);
    }
    if ($action === 'channel.leave') {if($chat['kind']!=='channel'||$chat['role']==='owner')fail(403,'Владелец остаётся в канале.');query('DELETE FROM members WHERE chat_id=? AND user_id=?',[$chatId,$uid]);result(['ok'=>true]);}
    if ($action === 'messages') {
        result(message_page($chatId, $uid, $data));
    }
    if ($action === 'message.edit') {
        rate('message-edit:' . $uid, 60, 60);
        $id = (int)($data['message_id'] ?? 0);
        if ($id < 1) fail(422, 'Укажите сообщение.');
        $body = message_text($data);
        $message = one('SELECT m.id, m.user_id, m.body, m.edited_at, f.message_id AS file_id, i.message_id AS invite_id, e.message_id AS event_id FROM messages m LEFT JOIN message_files f ON f.message_id = m.id LEFT JOIN message_invites i ON i.message_id = m.id LEFT JOIN message_events e ON e.message_id = m.id WHERE m.id = ? AND m.chat_id = ?', [$id, $chatId]);
        if (!$message) fail(404, 'Сообщение не найдено.');
        if ((int)$message['user_id'] !== $uid) fail(403, 'Можно редактировать только свои сообщения.');
        if ($message['file_id'] || $message['invite_id'] || $message['event_id']) fail(422, 'Этот тип сообщения нельзя редактировать.');
        if ($message['body'] !== $body) transaction(function () use ($id, $chatId, $body): void {
            $at = stamp();
            query('UPDATE messages SET body = ?, edited_at = ? WHERE id = ? AND chat_id = ?', [$body, $at, $id, $chatId]);
            query('INSERT INTO message_edits (chat_id, message_id, edited_at) VALUES (?, ?, ?)', [$chatId, $id, $at]);
        });
        result(['id' => $id, 'body' => $body, 'edited_at' => $message['body'] === $body ? $message['edited_at'] : stamp()]);
    }
    if ($action === 'message.react') {
        rate('message-react:' . $uid, 120, 60);
        $id = (int)($data['message_id'] ?? 0);
        $emoji = $data['emoji'] ?? '';
        if ($id < 1 || !one('SELECT id FROM messages WHERE id = ? AND chat_id = ?', [$id, $chatId])) fail(404, 'Сообщение не найдено.');
        if (!is_string($emoji) || !in_array($emoji, ['👍', '❤️', '😂', '😮', '😢', '👏'], true)) fail(422, 'Выберите реакцию из списка.');
        $existing = one('SELECT emoji FROM message_reactions WHERE message_id = ? AND user_id = ?', [$id, $uid]);
        if ($existing && $existing['emoji'] === $emoji) {
            query('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?', [$id, $uid]);
            result(['ok' => true, 'emoji' => null]);
        }
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            query('INSERT INTO message_reactions (message_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = VALUES(created_at)', [$id, $uid, $emoji, stamp()]);
        } else {
            query('INSERT INTO message_reactions (message_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?) ON CONFLICT(message_id, user_id) DO UPDATE SET emoji = excluded.emoji, created_at = excluded.created_at', [$id, $uid, $emoji, stamp()]);
        }
        result(['ok' => true, 'emoji' => $emoji]);
    }
    if ($action === 'message.delete') {
        $id = (int)($data['message_id'] ?? 0); if ($id < 1) fail(422, 'Укажите сообщение.');
        $stored = transaction(function() use ($id, $chatId, $chat, $u, $uid) {
            query('UPDATE chats SET title = title WHERE id = ?', [$chatId]);
            $m = one('SELECT m.chat_id,m.user_id,f.stored_name FROM messages m LEFT JOIN message_files f ON f.message_id=m.id WHERE m.id=?',[$id]);
            if (!$m) return null;
            if ((int)$m['chat_id'] !== $chatId) throw new DomainException('Сообщение находится в другом чате.');
            $admin = service_is_admin($u);
            $owner = in_array($chat['kind'], ['group','channel'], true) && $chat['role'] === 'owner';
            if ((int)$m['user_id'] !== $uid && !$owner && !$admin) fail(403, 'Можно удалять только свои сообщения.');
            query('DELETE FROM messages WHERE id=? AND chat_id=?',[$id,$chatId]);
            if ($admin) admin_audit($uid,'message.delete','chat:'.$chatId.' message:'.$id);
            return $m['stored_name'];
        });
        if (is_string($stored) && preg_match('/^[a-f0-9]{48}\.bin$/D',$stored)) { $path=__DIR__.'/files/'.$stored;if(is_file($path)&&!unlink($path))error_log('LChat: cannot remove deleted attachment'); }
        result(['ok'=>true]);
    }
    if ($action === 'message.send') {
        if ($chat['kind'] === 'channel' && $chat['role'] !== 'owner') fail(403, 'Публиковать в канале может только владелец.');
        rate('message:' . $uid, 80, 60);
        $body = message_text($data); $nonce = field($data, 'nonce', 16, 64);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $nonce)) fail(422, 'Некорректный идентификатор сообщения.');
        $replyTo = max(0, (int)($data['reply_to_id'] ?? 0));
        if ($replyTo && !one('SELECT id FROM messages WHERE id = ? AND chat_id = ?', [$replyTo, $chatId])) fail(404, 'Сообщение для ответа не найдено.');
        try { query('INSERT INTO messages (chat_id, user_id, body, nonce, reply_to_id, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$chatId, $uid, $body, $nonce, $replyTo ?: null, stamp()]); }
        catch (PDOException $e) { if (!str_starts_with((string)$e->getCode(), '23')) throw $e; }
        $m = one('SELECT id, chat_id FROM messages WHERE user_id = ? AND nonce = ?', [$uid, $nonce]);
        if ((int)$m['chat_id'] !== $chatId) fail(409, 'Идентификатор сообщения уже использован.');
        bot_platform_dismiss_keyboard($chatId, $uid);
        if ((int)$uid !== bot_creator_id()) bot_creator_process($chatId, $uid, (int)$m['id'], $body);
        bot_platform_publish_message((int)$m['id']);
        result(['id' => $m['id']]);
    }
    if ($action === 'chat.read') {
        $last = max(0, (int)($data['last_id'] ?? 0));
        $max = one('SELECT MAX(id) AS id FROM messages WHERE chat_id = ?', [$chatId]);
        $last = min($last, (int)$max['id']);
        query('UPDATE members SET last_read = CASE WHEN last_read < ? THEN ? ELSE last_read END WHERE chat_id = ? AND user_id = ?', [$last, $last, $chatId, $uid]); result(['ok' => true]);
    }
    if ($action === 'group.invite-reset') {
        if ($chat['role'] !== 'owner') fail(403, 'Только владелец может обновить ссылку.');
        $token = bin2hex(random_bytes(24)); query('UPDATE chats SET invite_token = ? WHERE id = ?', [$token, $chatId]); result(['token' => $token]);
    }
    if ($action === 'group.leave') {
        if ($chat['kind'] !== 'group') fail(422, 'Это личный чат.');
        if ($chat['role'] === 'owner') fail(422, 'Владелец остаётся в группе.');
        query('DELETE FROM members WHERE chat_id = ? AND user_id = ?', [$chatId, $uid]); result(['ok' => true]);
    }
    if ($action === 'group.remove') {
        if ($chat['kind'] !== 'group' || $chat['role'] !== 'owner') fail(403, 'Только владелец может исключать участников.');
        $other = (int)($data['user_id'] ?? 0);
        if ($other === $uid) fail(422, 'Нельзя исключить владельца.');
        query('DELETE FROM members WHERE chat_id = ? AND user_id = ?', [$chatId, $other]); result(['ok' => true]);
    }
    fail(404, 'Действие не найдено.');
} catch (DomainException $e) { fail(409, $e->getMessage()); }
catch (Throwable $e) {
    error_log('LChat: ' . get_class($e) . ' ' . $e->getMessage());
    fail(500, 'Ошибка сервера. Проверьте установку и журнал ошибок PHP на хостинге.');
}
