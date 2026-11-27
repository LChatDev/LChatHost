<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function discussion_schema(): void {
    if (one('SELECT value FROM settings WHERE name=?', ['channel_discussions_v1'])) return;
    $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    db()->exec('CREATE TABLE IF NOT EXISTS channel_discussions (channel_id INTEGER PRIMARY KEY, group_id INTEGER NOT NULL UNIQUE, FOREIGN KEY(channel_id) REFERENCES chats(id) ON DELETE CASCADE, FOREIGN KEY(group_id) REFERENCES chats(id) ON DELETE CASCADE)' . $suffix);
    db()->exec('CREATE TABLE IF NOT EXISTS channel_comments (message_id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE, FOREIGN KEY(post_id) REFERENCES messages(id) ON DELETE CASCADE, FOREIGN KEY(channel_id) REFERENCES chats(id) ON DELETE CASCADE)' . $suffix);
    try { db()->exec('CREATE INDEX channel_comments_post ON channel_comments(post_id, message_id)'); }
    catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1061 && !str_contains($e->getMessage(), 'already exists')) throw $e; }
    try { query('INSERT INTO settings(name,value) VALUES (?,?)', ['channel_discussions_v1','1']); }
    catch (PDOException $e) { if (!str_starts_with((string)$e->getCode(), '23')) throw $e; }
}
function discussion_link(int $channel): ?array {
    return one('SELECT d.group_id,c.title FROM channel_discussions d JOIN chats c ON c.id=d.group_id AND c.kind=? WHERE d.channel_id=?', ['group',$channel]);
}
function discussion_register_reply(int $id, int $reply, int $group): void {
    if (!$reply) return;
    $origin=one('SELECT t.post_id,t.channel_id FROM channel_comments t JOIN channel_discussions d ON d.channel_id=t.channel_id WHERE t.message_id=? AND d.group_id=?',[$reply,$group]);
    if (!$origin || one('SELECT message_id FROM channel_comments WHERE message_id=?',[$id])) return;
    query('INSERT INTO channel_comments(message_id,post_id,channel_id) VALUES (?,?,?)',[$id,$origin['post_id'],$origin['channel_id']]);
}
function discussion_api(string $action, array $data, int $uid): void {
    $channel = (int)($data['chat_id'] ?? 0);
    $chat = member($channel, $uid);
    if ($chat['kind'] !== 'channel') fail(422, 'Выберите канал.');
    if ($action === 'discussion.configure') {
        if ($chat['role'] !== 'owner') fail(403, 'Настраивать обсуждение может только владелец канала.');
        rate('discussion-config:'.$uid, 20, 60);
        $group = (int)($data['group_id'] ?? 0);
        $create = ($data['create'] ?? false) === true;
        transaction(function() use ($channel, $chat, $uid, $group, $create): void {
            query('UPDATE chats SET title=title WHERE id=?', [$channel]);
            $target = $group;
            if ($create) {
                if (discussion_link($channel)) fail(409, 'Сначала отключите текущую группу обсуждений.');
                query('INSERT INTO chats(kind,title,owner_id,invite_token,created_at) VALUES (?,?,?,?,?)', ['group',mb_substr($chat['title'].' · Обсуждение',0,80),$uid,bin2hex(random_bytes(24)),stamp()]);
                $target = (int)db()->lastInsertId();
                query('INSERT INTO members(chat_id,user_id,role,joined_at) VALUES (?,?,?,?)', [$target,$uid,'owner',stamp()]);
            }
            if ($target) {
                $g = member($target,$uid);
                if ($g['kind'] !== 'group' || $g['role'] !== 'owner') fail(403, 'Выберите группу, которой вы владеете.');
                $used = one('SELECT channel_id FROM channel_discussions WHERE group_id=?',[$target]);
                if ($used && (int)$used['channel_id'] !== $channel) fail(409, 'Группа уже связана с другим каналом.');
            }
            query('DELETE FROM channel_discussions WHERE channel_id=?',[$channel]);
            if ($target) query('INSERT INTO channel_discussions(channel_id,group_id) VALUES (?,?)',[$channel,$target]);
        });
        result(['discussion'=>discussion_link($channel)]);
    }
    $link = discussion_link($channel);
    if (!$link) fail(404, 'Обсуждение отключено.');
    $post = (int)($data['post_id'] ?? 0);
    if (!one('SELECT id FROM messages WHERE id=? AND chat_id=?',[$post,$channel])) fail(404,'Публикация удалена или не найдена.');
    if ($action === 'discussion.messages') {
        $before = max(0,(int)($data['before'] ?? 0));
        $params = [$post,$channel,$link['group_id']];
        $where = $before ? ' AND m.id < ?' : '';
        if ($before) $params[] = $before;
        // Select bounded IDs before loading potentially large encrypted message bodies.
        $length = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'OCTET_LENGTH(m.body)' : 'LENGTH(CAST(m.body AS BLOB))';
        $meta = query('SELECT m.id,'.$length.' AS bytes FROM channel_comments t JOIN messages m ON m.id=t.message_id WHERE t.post_id=? AND t.channel_id=? AND m.chat_id=?'.$where.' ORDER BY m.id DESC LIMIT 31',$params)->fetchAll();
        $ids=[]; $bytes=0;
        foreach ($meta as $item) { if (count($ids)>=30 || ($ids && $bytes+(int)$item['bytes']>MESSAGE_TEXT_MAX_BYTES)) break; $ids[]=(int)$item['id']; $bytes+=(int)$item['bytes']; }
        $rows = $ids ? query('SELECT m.id,m.user_id,m.body,m.created_at,m.edited_at,u.name,u.username FROM messages m JOIN users u ON u.id=m.user_id WHERE m.id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY m.id',$ids)->fetchAll() : [];
        $groupMember=one('SELECT c.*,m.role FROM chats c JOIN members m ON m.chat_id=c.id WHERE c.id=? AND m.user_id=?',[$link['group_id'],$uid]);
        $moderator = ($groupMember && group_can($groupMember,$uid,'delete')) || $chat['role'] === 'owner' || (one('SELECT role FROM members WHERE chat_id=? AND user_id=?',[$link['group_id'],$uid])['role'] ?? '') === 'owner';
        foreach ($rows as &$row) { $row['body']=message_read($row['body']); $row['can_delete']=$moderator || (int)$row['user_id']===$uid; } unset($row);
        result(['messages'=>$rows,'discussion'=>$link,'has_more'=>count($ids)<count($meta),'total'=>(int)one('SELECT COUNT(*) AS n FROM channel_comments t JOIN messages m ON m.id=t.message_id WHERE t.post_id=? AND t.channel_id=? AND m.chat_id=?',[$post,$channel,$link['group_id']])['n']]);
    }
    if ($action === 'discussion.delete') {
        $id=(int)($data['message_id'] ?? 0);
        $m=one('SELECT m.user_id FROM channel_comments t JOIN messages m ON m.id=t.message_id WHERE t.message_id=? AND t.post_id=? AND t.channel_id=? AND m.chat_id=?',[$id,$post,$channel,$link['group_id']]);
        if (!$m) fail(404,'Комментарий не найден.');
        $groupOwner=(one('SELECT role FROM members WHERE chat_id=? AND user_id=?',[$link['group_id'],$uid])['role'] ?? '')==='owner';
        $groupMember=one('SELECT c.*,m.role FROM chats c JOIN members m ON m.chat_id=c.id WHERE c.id=? AND m.user_id=?',[$link['group_id'],$uid]);
        if ((int)$m['user_id']!==$uid && $chat['role']!=='owner' && !$groupOwner && !($groupMember && group_can($groupMember,$uid,'delete'))) fail(403,'Недостаточно прав.');
        delete_message_row($id,(int)$link['group_id']); group_log((int)$link['group_id'],$uid,'message.delete',$id); result(['ok'=>true]);
    }
    if ($action === 'discussion.send') {
        if(one('SELECT until_at FROM group_limits WHERE chat_id=? AND user_id=? AND until_at>?',[$link['group_id'],$uid,stamp()]))fail(403,'Вы временно ограничены в группе обсуждений.');
        rate('message:'.$uid,80,60);
        $body=message_text($data); $nonce=field($data,'nonce',16,64);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D',$nonce)) fail(422,'Некорректный идентификатор сообщения.');
        $id=transaction(function() use ($channel,$uid,$post,$link,$body,$nonce): int {
            query('UPDATE chats SET title=title WHERE id=?',[$channel]);
            $current=discussion_link($channel);
            if (!$current || (int)$current['group_id']!==(int)$link['group_id']) fail(409,'Группа обсуждений изменилась. Откройте комментарии заново.');
            if (!one('SELECT id FROM messages WHERE id=? AND chat_id=?',[$post,$channel])) fail(404,'Публикация удалена.');
            $existing=one('SELECT m.id,t.post_id,t.channel_id FROM messages m LEFT JOIN channel_comments t ON t.message_id=m.id WHERE m.user_id=? AND m.nonce=?',[$uid,$nonce]);
            if ($existing) { if ((int)$existing['post_id']!==$post || (int)$existing['channel_id']!==$channel) fail(409,'Идентификатор уже использован.'); return (int)$existing['id']; }
            if (!one('SELECT user_id FROM members WHERE chat_id=? AND user_id=?',[$link['group_id'],$uid])) query('INSERT INTO members(chat_id,user_id,role,joined_at) VALUES (?,?,?,?)',[$link['group_id'],$uid,'member',stamp()]);
            query('INSERT INTO messages(chat_id,user_id,body,nonce,created_at) VALUES (?,?,?,?,?)',[$link['group_id'],$uid,message_store($body),$nonce,stamp()]);
            $id=(int)db()->lastInsertId();
            query('INSERT INTO channel_comments(message_id,post_id,channel_id) VALUES (?,?,?)',[$id,$post,$channel]); return $id;
        });
        bot_platform_publish_message($id);
        result(['id'=>$id,'group_id'=>$link['group_id']]);
    }
    fail(404,'Действие не найдено.');
}
