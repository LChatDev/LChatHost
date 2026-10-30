<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }
function install_schema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $messageBody = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $tables = [
        'settings' => 'name VARCHAR(40) PRIMARY KEY, value VARCHAR(255) NOT NULL',
        'users' => "id $id, username VARCHAR(32) NOT NULL UNIQUE, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(60) NOT NULL, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, last_seen DATETIME NOT NULL",
        'chats' => "id $id, kind VARCHAR(10) NOT NULL, title VARCHAR(80) NOT NULL, owner_id INTEGER NOT NULL, direct_key VARCHAR(50) UNIQUE, invite_token VARCHAR(48) UNIQUE, created_at DATETIME NOT NULL, FOREIGN KEY(owner_id) REFERENCES users(id)",
        'members' => 'chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, role VARCHAR(10) NOT NULL, last_read INTEGER NOT NULL DEFAULT 0, joined_at DATETIME NOT NULL, PRIMARY KEY(chat_id, user_id), FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE',
        'messages' => "id $id, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, body $messageBody NOT NULL, nonce VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, UNIQUE(user_id, nonce), FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id)",
        'message_invites' => 'message_id INTEGER PRIMARY KEY, kind VARCHAR(10) NOT NULL, token VARCHAR(48) NOT NULL, title VARCHAR(80) NOT NULL, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE',
        'message_events' => 'message_id INTEGER PRIMARY KEY, kind VARCHAR(20) NOT NULL, room_id INTEGER NOT NULL, token VARCHAR(48) NOT NULL, UNIQUE(room_id, kind), FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE',
        'rooms' => "id $id, token VARCHAR(48) NOT NULL UNIQUE, title VARCHAR(80) NOT NULL, chat_id INTEGER, owner_id INTEGER NOT NULL, is_public INTEGER NOT NULL, video INTEGER NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, ended_at DATETIME, FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE, FOREIGN KEY(owner_id) REFERENCES users(id)",
        'participants' => 'id VARCHAR(32) PRIMARY KEY, room_id INTEGER NOT NULL, owner_key VARCHAR(64) NOT NULL, user_id INTEGER, name VARCHAR(60) NOT NULL, muted INTEGER NOT NULL DEFAULT 0, camera INTEGER NOT NULL DEFAULT 0, last_seen DATETIME NOT NULL, joined_at DATETIME NOT NULL, left_at DATETIME, FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id)',
        'signals' => "id $id, room_id INTEGER NOT NULL, sender VARCHAR(32) NOT NULL, recipient VARCHAR(32) NOT NULL, payload TEXT NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE",
        'rate_limits' => 'bucket VARCHAR(64) PRIMARY KEY, hits INTEGER NOT NULL, reset_at INTEGER NOT NULL',
    ];
    foreach ($tables as $name => $definition) $db->exec("CREATE TABLE IF NOT EXISTS $name ($definition)$suffix");
    $indexes = ['members_user' => 'members (user_id)', 'messages_chat' => 'messages (chat_id, id)', 'rooms_chat' => 'rooms (chat_id, created_at)', 'participants_room' => 'participants (room_id, last_seen)', 'signals_recipient' => 'signals (recipient, id)'];
    foreach ($indexes as $name => $on) {
        try { $db->exec("CREATE INDEX $name ON $on"); }
        catch (PDOException $e) {
            // Installation may be retried after interruption; ignore only duplicate indexes.
            if ($mysql && ($e->errorInfo[1] ?? 0) === 1061) continue;
            if (!$mysql && str_contains($e->getMessage(), 'already exists')) continue;
            throw $e;
        }
    }
    if (!one('SELECT name FROM settings WHERE name = ?', ['installed'])) query('INSERT INTO settings (name, value) VALUES (?, ?)', ['installed', stamp()]);
    $version = one('SELECT value FROM settings WHERE name = ?', ['schema_version']);
    if (!$version) query('INSERT INTO settings (name, value) VALUES (?, ?)', ['schema_version', '3']);
    elseif ((int)$version['value'] < 3) query('UPDATE settings SET value = ? WHERE name = ?', ['3', 'schema_version']);
    ensure_features();
}
