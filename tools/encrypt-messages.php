<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('LCHAT_INTERNAL', true);
require dirname(__DIR__) . '/app.php';

try {
    if (message_key() === null) throw new RuntimeException('Configure the message encryption key first');
    $mysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $cursor = 0;
    $changed = 0;
    $check = in_array('--check', $argv, true);
    $legacy = 0;
    if ($mysql) {
        $capacity = one('SELECT CHARACTER_OCTET_LENGTH AS bytes FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['messages', 'body']);
        if ((int)($capacity['bytes'] ?? 0) < 14000000 || (int)db()->query('SELECT @@max_allowed_packet')->fetchColumn() < 14000000) {
            throw new RuntimeException('MySQL body capacity and max_allowed_packet must support 14 MB');
        }
    }
    $tables = ['messages' => 'body'];
    $hasBotUpdates = $mysql
        ? one("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bot_updates'")
        : one("SELECT 1 FROM sqlite_master WHERE type='table' AND name='bot_updates'");
    if ($hasBotUpdates) $tables['bot_updates'] = 'payload';
    foreach ($tables as $table => $column) {
        $cursor = $legacy = $changed = 0;
        while ($ids = query("SELECT id FROM $table WHERE id > ? ORDER BY id LIMIT 100", [$cursor])->fetchAll(PDO::FETCH_COLUMN)) {
            foreach ($ids as $id) {
                $cursor = (int)$id;
                transaction(function () use ($cursor, $mysql, $check, $table, $column, &$changed, &$legacy): void {
                    $row = one("SELECT $column FROM $table WHERE id = ?" . ($mysql ? ' FOR UPDATE' : ''), [$cursor]);
                    if (!$row) return;
                    $old = $row[$column];
                    if (str_starts_with($old, MESSAGE_ENVELOPE)) {
                        message_read($old);
                        message_read($old, true);
                        return;
                    }
                    ++$legacy;
                    if ($check) return;
                    $encrypted = message_store($old);
                    if (message_read($encrypted) !== $old) throw new RuntimeException('Encryption round trip failed');
                    query("UPDATE $table SET $column = ? WHERE id = ?", [$encrypted, $cursor]);
                    ++$changed;
                });
            }
        }
        echo "$table: legacy rows $legacy; encrypted this run $changed\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Message migration stopped at ID ' . ($cursor ?? 0) . ': ' . get_class($error) . "\n");
    exit(1);
}
