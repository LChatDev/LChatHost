<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function upgrade_message_columns(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    foreach ([['messages', 'body', 'MEDIUMTEXT'], ['bot_updates', 'payload', 'LONGTEXT']] as [$table, $column, $target]) {
        $info = $pdo->query("SHOW FULL COLUMNS FROM `$table` LIKE '$column'")->fetch(PDO::FETCH_ASSOC);
        if (!$info || $info['Null'] !== 'NO' || !preg_match('/^utf8mb4_[a-z0-9_]+$/iD', (string)$info['Collation'])) throw new RuntimeException('Unexpected message storage schema.');
        $type = strtolower((string)$info['Type']);
        if (!in_array($type, ['tinytext', 'text', 'mediumtext', 'longtext'], true)) throw new RuntimeException('Unexpected message storage type.');
        $widen = $target === 'MEDIUMTEXT' ? in_array($type, ['tinytext', 'text'], true) : $type !== 'longtext';
        if ($widen) $pdo->exec("ALTER TABLE `$table` MODIFY `$column` $target CHARACTER SET utf8mb4 COLLATE " . $info['Collation'] . ' NOT NULL');
    }
}
function ensure_features(): void {
    static $ready = false;
    if ($ready) return;
    $version = one('SELECT value FROM settings WHERE name = ?', ['schema_version']);
    if ((int)($version['value'] ?? 0) < 3) throw new RuntimeException('Сначала установите LChat.');
    if ((int)$version['value'] < 5) {
        $mysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $tables = [
            'user_bans' => 'user_id INTEGER PRIMARY KEY, reason VARCHAR(200) NOT NULL, created_by INTEGER NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE',
            'message_files' => 'message_id INTEGER PRIMARY KEY, stored_name VARCHAR(64) NOT NULL UNIQUE, original_name VARCHAR(190) NOT NULL, mime VARCHAR(100) NOT NULL, size INTEGER NOT NULL, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE',
            'moderator_messages' => 'message_id INTEGER PRIMARY KEY, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE',
            'user_avatars' => 'user_id INTEGER PRIMARY KEY, stored_name VARCHAR(64) NOT NULL UNIQUE, updated_at DATETIME NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE',
            'admin_audit' => "id $id, actor_id INTEGER NOT NULL, action VARCHAR(40) NOT NULL, target VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL",
        ];
        foreach ($tables as $table => $definition) db()->exec("CREATE TABLE IF NOT EXISTS $table ($definition)$suffix");
        query('UPDATE settings SET value = ? WHERE name = ?', ['5', 'schema_version']);
    }
    if ((int)$version['value'] < 6) {
        $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        db()->exec('CREATE TABLE IF NOT EXISTS call_moderators (participant_id VARCHAR(32) PRIMARY KEY, tile_hidden INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(participant_id) REFERENCES participants(id) ON DELETE CASCADE)' . $suffix);
        query('UPDATE settings SET value = ? WHERE name = ?', ['6', 'schema_version']);
    }
    if ((int)$version['value'] < 7) {
        $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        db()->exec('CREATE TABLE IF NOT EXISTS channel_details (chat_id INTEGER PRIMARY KEY, slug VARCHAR(32) NOT NULL UNIQUE, FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE)' . $suffix);
        db()->exec('CREATE TABLE IF NOT EXISTS message_voice (message_id INTEGER PRIMARY KEY, duration INTEGER NOT NULL, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE)' . $suffix);
        query('UPDATE settings SET value = ? WHERE name = ?', ['7', 'schema_version']);
    }
    if ((int)$version['value'] < 8) {
        // Version 8 was previously used by Google OAuth; existing account data is kept.
        query('UPDATE settings SET value = ? WHERE name = ?', ['8', 'schema_version']);
    }
    if ((int)$version['value'] < 9) {
        $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        db()->exec('CREATE TABLE IF NOT EXISTS user_preferences (user_id INTEGER PRIMARY KEY, bio VARCHAR(240) NOT NULL DEFAULT \'\', theme VARCHAR(5) NOT NULL DEFAULT \'dark\', accent INTEGER NOT NULL DEFAULT 0, compact INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)' . $suffix);
        query('UPDATE settings SET value = ? WHERE name = ?', ['9', 'schema_version']);
    }
    if ((int)$version['value'] < 10) {
        foreach (['reply_to_id INTEGER NULL', 'edited_at DATETIME NULL'] as $column) {
            try { db()->exec('ALTER TABLE messages ADD COLUMN ' . $column); }
            catch (PDOException $e) {
                $message = strtolower($e->getMessage());
                if (!str_contains($message, 'duplicate') && !str_contains($message, 'exists')) throw $e;
            }
        }
        $mysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        db()->exec("CREATE TABLE IF NOT EXISTS message_edits (id $id, chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL, edited_at DATETIME NOT NULL, FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE)$suffix");
        try { db()->exec('CREATE INDEX message_edits_chat ON message_edits (chat_id, id)'); }
        catch (PDOException $e) {
            $code = $e->errorInfo[1] ?? 0;
            if (($mysql && $code !== 1061) || (!$mysql && !str_contains(strtolower($e->getMessage()), 'already exists'))) throw $e;
        }
        query('UPDATE settings SET value = ? WHERE name = ?', ['10', 'schema_version']);
    }
    if ((int)$version['value'] < 11) {
        // Version 11 was previously used by Google OAuth. Do not create new OAuth tables.
        query('UPDATE settings SET value = ? WHERE name = ?', ['11', 'schema_version']);
    }
    if ((int)$version['value'] < 12) {
        $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        db()->exec('CREATE TABLE IF NOT EXISTS bot_accounts (bot_user_id INTEGER PRIMARY KEY, owner_user_id INTEGER NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, created_at DATETIME NOT NULL, FOREIGN KEY(bot_user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE)' . $suffix);
        try { db()->exec('CREATE INDEX bot_accounts_owner ON bot_accounts (owner_user_id)'); }
        catch (PDOException $e) {
            $mysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            if (($mysql && ($e->errorInfo[1] ?? 0) !== 1061) || (!$mysql && !str_contains(strtolower($e->getMessage()), 'already exists'))) throw $e;
        }
        query('UPDATE settings SET value = ? WHERE name = ?', ['12', 'schema_version']);
    }
    if ((int)$version['value'] < 13) {
        $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        db()->exec('CREATE TABLE IF NOT EXISTS message_reactions (message_id INTEGER NOT NULL, user_id INTEGER NOT NULL, emoji VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(message_id, user_id), FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)' . $suffix);
        query('UPDATE settings SET value = ? WHERE name = ?', ['13', 'schema_version']);
    }
    if ((int)$version['value'] < 14) {
        $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        db()->exec('CREATE TABLE IF NOT EXISTS system_bots (user_id INTEGER PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)' . $suffix);
        db()->exec('CREATE TABLE IF NOT EXISTS bot_creator_sessions (user_id INTEGER PRIMARY KEY, stage VARCHAR(20) NOT NULL, draft_name VARCHAR(60) NOT NULL DEFAULT \'\', updated_at DATETIME NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)' . $suffix);
        db()->exec('CREATE TABLE IF NOT EXISTS bot_creator_handled (message_id INTEGER PRIMARY KEY, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE)' . $suffix);
        query('UPDATE settings SET value = ? WHERE name = ?', ['14', 'schema_version']);
    }
    if ((int)$version['value'] < 15) {
        $mysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        db()->exec('CREATE TABLE IF NOT EXISTS bot_updates (id BIGINT PRIMARY KEY, bot_user_id INTEGER NOT NULL, event_key VARCHAR(80) NOT NULL, payload ' . ($mysql ? 'LONGTEXT' : 'TEXT') . ' NOT NULL, created_at DATETIME NOT NULL, delivered_at DATETIME NULL, attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at DATETIME NOT NULL, UNIQUE(bot_user_id, event_key), FOREIGN KEY(bot_user_id) REFERENCES users(id) ON DELETE CASCADE)' . $suffix);
        db()->exec('CREATE TABLE IF NOT EXISTS bot_webhooks (bot_user_id INTEGER PRIMARY KEY, url VARCHAR(2048) NOT NULL, secret_token VARCHAR(256) NOT NULL DEFAULT \'\', last_error_message VARCHAR(500) NOT NULL DEFAULT \'\', last_error_at DATETIME NULL, FOREIGN KEY(bot_user_id) REFERENCES users(id) ON DELETE CASCADE)' . $suffix);
        db()->exec('CREATE TABLE IF NOT EXISTS bot_message_markup (message_id INTEGER PRIMARY KEY, markup_json TEXT NOT NULL, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE)' . $suffix);
        db()->exec('CREATE TABLE IF NOT EXISTS bot_media_kinds (message_id INTEGER PRIMARY KEY, kind VARCHAR(16) NOT NULL, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE)' . $suffix);
        db()->exec('CREATE TABLE IF NOT EXISTS bot_callback_queries (id VARCHAR(32) PRIMARY KEY, bot_user_id INTEGER NOT NULL, message_id INTEGER NOT NULL, from_user_id INTEGER NOT NULL, data VARCHAR(64) NOT NULL, answer_text VARCHAR(200) NULL, answered_at DATETIME NULL, created_at DATETIME NOT NULL, FOREIGN KEY(bot_user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE, FOREIGN KEY(from_user_id) REFERENCES users(id) ON DELETE CASCADE)' . $suffix);
        try { db()->exec('CREATE INDEX bot_updates_delivery ON bot_updates (bot_user_id, delivered_at, next_attempt_at, id)'); }
        catch (PDOException $e) {
            if (($mysql && ($e->errorInfo[1] ?? 0) !== 1061) || (!$mysql && !str_contains(strtolower($e->getMessage()), 'already exists'))) throw $e;
        }
        if ($mysql) query('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)', ['bot_update_seq', (string)(one('SELECT COALESCE(MAX(id), 0) AS n FROM messages')['n'] ?? 0)]);
        else query('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)', ['bot_update_seq', (string)(one('SELECT COALESCE(MAX(id), 0) AS n FROM messages')['n'] ?? 0)]);
        query('UPDATE settings SET value = ? WHERE name = ?', ['15', 'schema_version']);
    }
    if ((int)$version['value'] < 16) {
        $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        db()->exec('CREATE TABLE IF NOT EXISTS bot_chat_keyboards (chat_id INTEGER PRIMARY KEY, bot_user_id INTEGER NOT NULL, message_id INTEGER NOT NULL, markup_json TEXT NOT NULL, FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE, FOREIGN KEY(bot_user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE)' . $suffix);
        db()->exec('CREATE TABLE IF NOT EXISTS bot_keyboard_dismissals (chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, message_id INTEGER NOT NULL, PRIMARY KEY(chat_id, user_id), FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE)' . $suffix);
        query('UPDATE settings SET value = ? WHERE name = ?', ['16', 'schema_version']);
    }
    if ((int)$version['value'] < 17) {
        upgrade_message_columns(db());
        query('UPDATE settings SET value = ? WHERE name = ?', ['17', 'schema_version']);
    }
    try { bot_creator_install(); }
    catch (Throwable $e) { error_log('LChat BotCreator provision: ' . get_class($e) . ' ' . $e->getMessage()); }
    $ready = true;
}
function channel_slug(array $data): string {
    $slug = mb_strtolower(field($data, 'slug', 3, 32), 'UTF-8');
    if (!preg_match('/^[\p{L}][\p{L}\p{N}_]{2,31}$/uD', $slug)) fail(422, 'Адрес канала: 3–32 буквы, цифры и _, начиная с буквы.');
    if(in_array($slug,['assets','apis','data','files','selfhost','android','reg-ru'],true))fail(422,'Этот адрес зарезервирован системой.');
    return $slug;
}
function service_admin_id(): int {
    $setting = one('SELECT value FROM settings WHERE name = ?', ['admin_owner_id']);
    if (!$setting) {
        $initial = one('SELECT id FROM users WHERE username = ?', ['leosmleol']);
        if (!$initial) return 0;
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') query('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)', ['admin_owner_id', (string)$initial['id']]);
        else query('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)', ['admin_owner_id', (string)$initial['id']]);
        $setting = one('SELECT value FROM settings WHERE name = ?', ['admin_owner_id']);
    }
    return (int)$setting['value'];
}
function service_is_admin(array $user): bool {
    if ((int)$user['id'] === service_admin_id()) return true;
    $extra = one('SELECT value FROM settings WHERE name = ?', ['admin_extra_ids']);
    $ids = json_decode($extra['value'] ?? '[]', true);
    return is_array($ids) && in_array((int)$user['id'], array_map('intval', $ids), true);
}
function service_ban(int $uid): ?array { return one('SELECT reason FROM user_bans WHERE user_id = ?', [$uid]); }
function admin_audit(int $actor, string $action, string $target): void {
    query('INSERT INTO admin_audit (actor_id, action, target, created_at) VALUES (?, ?, ?, ?)', [$actor, $action, $target, stamp()]);
}
function admin_lock(int $uid): void {
    query('UPDATE settings SET value = value WHERE name = ?', ['admin_owner_id']);
    if (service_admin_id() !== $uid) throw new DomainException('Права администратора уже переданы.');
}
function moderation_messages(int $chatId, array $data): array {
    $before = max(0, (int)($data['before'] ?? 0));
    $sql = 'SELECT m.id, m.user_id, m.body, m.created_at, u.name, u.username, f.original_name AS file_name, f.size AS file_size, v.duration AS voice_duration, mm.message_id AS moderator FROM messages m JOIN users u ON u.id = m.user_id LEFT JOIN message_files f ON f.message_id = m.id LEFT JOIN message_voice v ON v.message_id = m.id LEFT JOIN moderator_messages mm ON mm.message_id = m.id WHERE m.chat_id = ?';
    $length = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'OCTET_LENGTH(body)' : 'LENGTH(CAST(body AS BLOB))';
    $meta = $before ? query('SELECT id, ' . $length . ' AS bytes FROM messages WHERE chat_id = ? AND id < ? ORDER BY id DESC LIMIT 100', [$chatId, $before])->fetchAll() : query('SELECT id, ' . $length . ' AS bytes FROM messages WHERE chat_id = ? ORDER BY id DESC LIMIT 100', [$chatId])->fetchAll();
    $ids = []; $total = 0;
    foreach ($meta as $item) { $size = (int)$item['bytes']; if ($ids && $total + $size > MESSAGE_TEXT_MAX_BYTES) break; $ids[] = (int)$item['id']; $total += $size; }
    $rows = $ids ? query($sql . ' AND m.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY m.id ASC', array_merge([$chatId], $ids))->fetchAll() : [];
    return ['messages' => $rows, 'has_more' => count($ids) < count($meta) || count($meta) === 100];
}
