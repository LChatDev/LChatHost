<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function productivity_schema(): void {
    if (one('SELECT value FROM settings WHERE name=?',['productivity_v1'])) return;
    $mysql=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $body=$mysql?'MEDIUMTEXT':'TEXT';
    $tables=[
        'chat_polls'=>'message_id INTEGER PRIMARY KEY, creator_id INTEGER NOT NULL, multiple INTEGER NOT NULL DEFAULT 0, closes_at DATETIME NULL, closed INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE',
        'poll_options'=>"id $id, poll_id INTEGER NOT NULL, position INTEGER NOT NULL, label TEXT NOT NULL, FOREIGN KEY(poll_id) REFERENCES chat_polls(message_id) ON DELETE CASCADE",
        'poll_votes'=>'poll_id INTEGER NOT NULL, user_id INTEGER NOT NULL, option_id INTEGER NOT NULL, PRIMARY KEY(poll_id,user_id,option_id), FOREIGN KEY(poll_id) REFERENCES chat_polls(message_id) ON DELETE CASCADE, FOREIGN KEY(option_id) REFERENCES poll_options(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE',
        'message_bookmarks'=>'user_id INTEGER NOT NULL, message_id INTEGER NOT NULL, remind_at DATETIME NULL, reminded_at DATETIME NULL, created_at DATETIME NOT NULL, PRIMARY KEY(user_id,message_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE',
        'chat_folders'=>"id $id, user_id INTEGER NOT NULL, title VARCHAR(40) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE",
        'chat_organizer'=>'user_id INTEGER NOT NULL, chat_id INTEGER NOT NULL, pinned INTEGER NOT NULL DEFAULT 0, folder_id INTEGER NULL, PRIMARY KEY(user_id,chat_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE, FOREIGN KEY(folder_id) REFERENCES chat_folders(id) ON DELETE SET NULL',
        'group_grants'=>'chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, can_delete INTEGER NOT NULL DEFAULT 0, can_info INTEGER NOT NULL DEFAULT 0, can_members INTEGER NOT NULL DEFAULT 0, can_restrict INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(chat_id,user_id), FOREIGN KEY(chat_id,user_id) REFERENCES members(chat_id,user_id) ON DELETE CASCADE',
        'group_limits'=>'chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, until_at DATETIME NOT NULL, reason VARCHAR(200) NOT NULL, PRIMARY KEY(chat_id,user_id), FOREIGN KEY(chat_id,user_id) REFERENCES members(chat_id,user_id) ON DELETE CASCADE',
        'group_action_log'=>"id $id, chat_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, action VARCHAR(40) NOT NULL, target_id INTEGER NULL, details VARCHAR(200) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE",
        'scheduled_messages'=>"id $id, user_id INTEGER NOT NULL, chat_id INTEGER NOT NULL, body $body NOT NULL, deliver_at DATETIME NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'pending', nonce VARCHAR(64) NOT NULL, message_id INTEGER NULL, error VARCHAR(200) NULL, created_at DATETIME NOT NULL, UNIQUE(user_id,nonce), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(chat_id) REFERENCES chats(id) ON DELETE CASCADE",
    ];
    foreach($tables as $name=>$definition)db()->exec("CREATE TABLE IF NOT EXISTS $name ($definition)$suffix");
    foreach(['poll_options_order'=>'poll_options(poll_id,position)','bookmark_reminders'=>'message_bookmarks(user_id,reminded_at,remind_at)','group_log_order'=>'group_action_log(chat_id,id)','schedule_due'=>'scheduled_messages(status,deliver_at,id)'] as $name=>$on){
        try{db()->exec("CREATE INDEX $name ON $on");}catch(PDOException $e){if(($e->errorInfo[1]??0)!==1061&&!str_contains($e->getMessage(),'already exists'))throw $e;}
    }
    try{query('INSERT INTO settings(name,value) VALUES (?,?)',['productivity_v1','1']);}catch(PDOException $e){if(!str_starts_with((string)$e->getCode(),'23'))throw $e;}
}
function group_can(array $chat,int $uid,string $permission): bool {
    if($chat['kind']!=='group')return false;
    if($chat['role']==='owner')return true;
    if(!in_array($permission,['delete','info','members','restrict'],true))return false;
    $row=one('SELECT can_'.$permission.' AS allowed FROM group_grants WHERE chat_id=? AND user_id=?',[$chat['id'],$uid]);
    return (int)($row['allowed']??0)===1;
}
function group_log(int $chat,int $actor,string $action,?int $target=null,string $details=''): void {
    query('INSERT INTO group_action_log(chat_id,actor_id,action,target_id,details,created_at) VALUES (?,?,?,?,?,?)',[$chat,$actor,$action,$target,mb_substr($details,0,200),stamp()]);
}
function tools_write_error(int $chat,int $uid): ?string {
    $m=one('SELECT c.kind,m.role FROM chats c JOIN members m ON m.chat_id=c.id WHERE c.id=? AND m.user_id=?',[$chat,$uid]);
    if(!$m)return 'Нет доступа к чату.';
    if(service_ban($uid))return 'Аккаунт заблокирован.';
    if($m['kind']==='channel'&&$m['role']!=='owner')return 'Публиковать в канале может только владелец.';
    if($m['kind']==='group'&&$m['role']!=='owner'){
        $limit=one('SELECT until_at FROM group_limits WHERE chat_id=? AND user_id=? AND until_at>?',[$chat,$uid,stamp()]);
        if($limit)return 'Отправка сообщений ограничена до '.$limit['until_at'].' UTC.';
    }
    return null;
}
function tools_assert_write(int $chat,int $uid): void {if($error=tools_write_error($chat,$uid))fail(403,$error);}
function tools_time(array $data,string $key,bool $optional=false): ?string {
    $v=$data[$key]??null;if($optional&&($v===null||$v===''))return null;
    if(!is_string($v)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,3})?Z$/D',$v))fail(422,'Выберите дату и время.');
    try{$date=new DateTimeImmutable($v);$errors=DateTimeImmutable::getLastErrors();}catch(Throwable $e){fail(422,'Некорректная дата.');}
    if($errors&&($errors['warning_count']||$errors['error_count']))fail(422,'Некорректная дата.');
    $at=$date->getTimestamp();if($at<time()+5||$at>time()+366*86400)fail(422,'Выберите время от 5 секунд до года в будущем.');
    return gmdate('Y-m-d H:i:s',$at);
}
function tools_nonce(array $data): string {
    $v=field($data,'nonce',16,48);if(!preg_match('/^[a-zA-Z0-9_-]+$/D',$v))fail(422,'Некорректный идентификатор.');return $v;
}
function tools_poll_states(array $ids,int $chat,int $uid): array {
    if(!$ids)return [];
    $ids=array_slice(array_unique(array_map('intval',$ids)),0,300);
    $rows=query('SELECT p.* FROM chat_polls p JOIN messages m ON m.id=p.message_id WHERE m.chat_id=? AND p.message_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_merge([$chat],$ids))->fetchAll();
    $states=[];
    foreach($rows as $p){
        $options=query('SELECT o.id,o.label,COUNT(v.user_id) AS votes FROM poll_options o LEFT JOIN poll_votes v ON v.option_id=o.id WHERE o.poll_id=? GROUP BY o.id,o.label,o.position ORDER BY o.position',[$p['message_id']])->fetchAll();
        foreach($options as &$option)$option['label']=message_read($option['label']);unset($option);
        $mine=array_map('intval',array_column(query('SELECT option_id FROM poll_votes WHERE poll_id=? AND user_id=?',[$p['message_id'],$uid])->fetchAll(),'option_id'));
        $states[]=['message_id'=>(int)$p['message_id'],'multiple'=>(bool)$p['multiple'],'closes_at'=>$p['closes_at'],'closed'=>(bool)$p['closed']||($p['closes_at']!==null&&$p['closes_at']<=stamp()),'creator_id'=>(int)$p['creator_id'],'options'=>$options,'mine'=>$mine,'voters'=>(int)one('SELECT COUNT(DISTINCT user_id) AS n FROM poll_votes WHERE poll_id=?',[$p['message_id']])['n']];
    }
    return $states;
}
function tools_reminders(int $uid): array {
    $rows=query('SELECT b.message_id,b.remind_at,m.chat_id,SUBSTR(m.body,1,2800) AS body FROM message_bookmarks b JOIN messages m ON m.id=b.message_id JOIN members mem ON mem.chat_id=m.chat_id AND mem.user_id=b.user_id WHERE b.user_id=? AND b.remind_at<=? AND b.reminded_at IS NULL ORDER BY b.remind_at LIMIT 20',[$uid,stamp()])->fetchAll();
    foreach($rows as &$row)$row['body']=message_read($row['body'],true);unset($row);return $rows;
}
/** Atomic due-message dispatch; CLI cron is required when nobody is using the site. */
function tools_dispatch(int $limit=5): array {
    $ids=query('SELECT id FROM scheduled_messages WHERE status=? AND deliver_at<=? ORDER BY deliver_at,id LIMIT '.max(1,min(100,$limit)),['pending',stamp()])->fetchAll();$sent=0;$failed=0;
    foreach($ids as $item){
        $out=transaction(function()use($item){
            if(!query('UPDATE scheduled_messages SET status=? WHERE id=? AND status=?',['processing',$item['id'],'pending'])->rowCount())return null;
            $s=one('SELECT * FROM scheduled_messages WHERE id=?',[$item['id']]);
            if($error=tools_write_error((int)$s['chat_id'],(int)$s['user_id'])){query('UPDATE scheduled_messages SET status=?,error=? WHERE id=?',['failed',$error,$s['id']]);return ['failed'=>true];}
            $nonce='scheduled_'.$s['id'];$m=one('SELECT id FROM messages WHERE user_id=? AND nonce=?',[$s['user_id'],$nonce]);
            if(!$m){query('INSERT INTO messages(chat_id,user_id,body,nonce,created_at) VALUES (?,?,?,?,?)',[$s['chat_id'],$s['user_id'],$s['body'],$nonce,stamp()]);$m=['id'=>(int)db()->lastInsertId()];}
            query('UPDATE scheduled_messages SET status=?,message_id=?,error=NULL WHERE id=?',['sent',$m['id'],$s['id']]);return ['id'=>(int)$m['id']];
        });
        if(isset($out['id'])){$sent++;$message=one('SELECT chat_id,user_id,body FROM messages WHERE id=?',[$out['id']]);if($message){$text=message_read($message['body']);if((int)$message['user_id']!==bot_creator_id())bot_creator_process((int)$message['chat_id'],(int)$message['user_id'],$out['id'],$text);if((int)$message['user_id']!==service_bot_id())service_bot_process((int)$message['chat_id'],(int)$message['user_id'],$out['id'],$text);}bot_platform_publish_message($out['id']);}elseif(isset($out['failed']))$failed++;
    }
    return ['sent'=>$sent,'failed'=>$failed];
}
function tools_search(array $data,int $uid): array {
    rate('tools-search:'.$uid,40,60);
    $q=field(['q'=>$data['q']??''],'q',0,120);$chat=max(0,(int)($data['chat_id']??0));if($chat)member($chat,$uid);
    $author=max(0,(int)($data['author_id']??0));$before=max(0,(int)($data['before']??0));
    $where=' WHERE mem.user_id=?';$params=[$uid];
    foreach(['chat_id'=>$chat,'user_id'=>$author] as $key=>$value)if($value){$where.=' AND m.'.$key.'=?';$params[]=$value;}
    if($before){$where.=' AND m.id<?';$params[]=$before;}
    foreach(['from'=>' >= ','to'=>' < '] as $key=>$op){
        if(empty($data[$key]))continue;$date=$data[$key];
        if(!is_string($date)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$date))fail(422,'Некорректная дата поиска.');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)fail(422,'Некорректная дата поиска.');
        $where.=' AND m.created_at'.$op.'?';$params[]=($key==='to'?$d->modify('+1 day'):$d)->format('Y-m-d H:i:s');
    }
    $type=$data['type']??'all';
    $types=['all'=>'','text'=>' AND f.message_id IS NULL','image'=>" AND f.mime LIKE 'image/%'",'video'=>" AND f.mime LIKE 'video/%' AND v.message_id IS NULL",'audio'=>" AND f.mime LIKE 'audio/%'",'voice'=>' AND v.message_id IS NOT NULL','file'=>' AND f.message_id IS NOT NULL'];
    if(!is_string($type)||!isset($types[$type]))fail(422,'Некорректный тип вложения.');$where.=$types[$type];
    $length=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'OCTET_LENGTH(m.body)':'LENGTH(CAST(m.body AS BLOB))';
    $meta=query('SELECT m.id,'.$length.' AS bytes FROM messages m JOIN members mem ON mem.chat_id=m.chat_id LEFT JOIN message_files f ON f.message_id=m.id LEFT JOIN message_voice v ON v.message_id=m.id'.$where.' ORDER BY m.id DESC LIMIT 101',$params)->fetchAll();
    $rows=[];$bytes=0;$cursor=0;$processed=0;
    foreach(array_slice($meta,0,100) as $item){
        if($processed&&$bytes+(int)$item['bytes']>MESSAGE_TEXT_MAX_BYTES)break;
        $m=one('SELECT m.id,m.chat_id,m.user_id,m.body,m.created_at,u.name,u.username,c.title,c.kind,f.original_name AS file_name FROM messages m JOIN users u ON u.id=m.user_id JOIN chats c ON c.id=m.chat_id LEFT JOIN message_files f ON f.message_id=m.id WHERE m.id=?',[$item['id']]);
        $cursor=(int)$item['id'];$processed++;$bytes+=(int)$item['bytes'];if(!$m)continue;
        $text=message_read($m['body']);if($q!==''&&mb_stripos($text,$q)===false&&mb_stripos($m['file_name']??'',$q)===false)continue;
        $m['body']=mb_substr($text,0,500);$rows[]=$m;if(count($rows)>=30)break;
    }
    return ['messages'=>$rows,'next'=>$cursor,'has_more'=>$processed<count($meta),'scanned'=>$processed];
}
function productivity_api(string $action,array $data,int $uid): void {
    if($action==='tools.search')result(tools_search($data,$uid));
    if($action==='tools.folders')result(['folders'=>query('SELECT id,title FROM chat_folders WHERE user_id=? ORDER BY id',[$uid])->fetchAll()]);
    if($action==='tools.folder.create'){
        rate('folder-create:'.$uid,30,60);$title=field($data,'title',1,40);
        $id=transaction(function()use($uid,$title){query('UPDATE users SET name=name WHERE id=?',[$uid]);if((int)one('SELECT COUNT(*) AS n FROM chat_folders WHERE user_id=?',[$uid])['n']>=20)fail(422,'Максимум 20 папок.');query('INSERT INTO chat_folders(user_id,title) VALUES (?,?)',[$uid,$title]);return (int)db()->lastInsertId();});result(['id'=>$id]);
    }
    if($action==='tools.folder.delete'){query('DELETE FROM chat_folders WHERE id=? AND user_id=?',[(int)($data['folder_id']??0),$uid]);result(['ok'=>true]);}
    if($action==='tools.bookmarks'){
        $before=max(0,(int)($data['before']??0));$params=[$uid];$extra=$before?' AND m.id<?':'';if($before)$params[]=$before;
        $rows=query('SELECT m.id,m.chat_id,m.user_id,SUBSTR(m.body,1,2800) AS body,m.created_at,u.name,c.title,b.remind_at,b.reminded_at FROM message_bookmarks b JOIN messages m ON m.id=b.message_id JOIN members mem ON mem.chat_id=m.chat_id AND mem.user_id=b.user_id JOIN users u ON u.id=m.user_id JOIN chats c ON c.id=m.chat_id WHERE b.user_id=?'.$extra.' ORDER BY m.id DESC LIMIT 51',$params)->fetchAll();
        $more=count($rows)>50;$rows=array_slice($rows,0,50);foreach($rows as &$row)$row['body']=message_read($row['body'],true);unset($row);result(['messages'=>$rows,'has_more'=>$more,'next'=>$rows?(int)end($rows)['id']:0]);
    }
    if($action==='tools.reminder.ack'){query('UPDATE message_bookmarks SET reminded_at=? WHERE user_id=? AND message_id=? AND remind_at<=?',[stamp(),$uid,(int)($data['message_id']??0),stamp()]);result(['ok'=>true]);}
    if($action==='tools.scheduled'){
        $before=max(0,(int)($data['before']??0));$params=[$uid];$extra=$before?' AND id<?':'';if($before)$params[]=$before;
        $rows=query('SELECT id,chat_id,SUBSTR(body,1,2800) AS body,deliver_at,status,message_id,error FROM scheduled_messages WHERE user_id=?'.$extra.' ORDER BY id DESC LIMIT 51',$params)->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
        foreach($rows as &$row)$row['body']=message_read($row['body'],true);unset($row);result(['messages'=>$rows,'has_more'=>$more,'next'=>$rows?(int)end($rows)['id']:0]);
    }
    if($action==='tools.schedule.cancel'){
        $n=query('UPDATE scheduled_messages SET status=? WHERE id=? AND user_id=? AND status=?',['cancelled',(int)($data['id']??0),$uid,'pending'])->rowCount();if(!$n)fail(409,'Сообщение уже отправлено или отменено.');result(['ok'=>true]);
    }
    $chatId=(int)($data['chat_id']??0);$chat=member($chatId,$uid);
    if($action==='tools.organize'){
        if(!is_bool($data['pinned']??null))fail(422,'Укажите закрепление.');$folder=max(0,(int)($data['folder_id']??0));
        if($folder&&!one('SELECT id FROM chat_folders WHERE id=? AND user_id=?',[$folder,$uid]))fail(403,'Нет доступа к папке.');
        transaction(function()use($uid,$chatId,$folder,$data){query('UPDATE users SET name=name WHERE id=?',[$uid]);query('DELETE FROM chat_organizer WHERE user_id=? AND chat_id=?',[$uid,$chatId]);query('INSERT INTO chat_organizer(user_id,chat_id,pinned,folder_id) VALUES (?,?,?,?)',[$uid,$chatId,$data['pinned']?1:0,$folder?:null]);});result(['ok'=>true]);
    }
    if($action==='tools.bookmark.set'||$action==='tools.bookmark.remove'){
        $id=(int)($data['message_id']??0);if(!one('SELECT id FROM messages WHERE id=? AND chat_id=?',[$id,$chatId]))fail(404,'Сообщение удалено.');
        if($action==='tools.bookmark.remove'){query('DELETE FROM message_bookmarks WHERE user_id=? AND message_id=?',[$uid,$id]);result(['ok'=>true]);}
        $at=tools_time($data,'remind_at',true);
        transaction(function()use($uid,$id,$at,$data){query('UPDATE users SET name=name WHERE id=?',[$uid]);$old=one('SELECT remind_at,reminded_at,created_at FROM message_bookmarks WHERE user_id=? AND message_id=?',[$uid,$id]);$keep=!array_key_exists('remind_at',$data);query('DELETE FROM message_bookmarks WHERE user_id=? AND message_id=?',[$uid,$id]);query('INSERT INTO message_bookmarks(user_id,message_id,remind_at,reminded_at,created_at) VALUES (?,?,?,?,?)',[$uid,$id,$keep?($old['remind_at']??null):$at,$keep?($old['reminded_at']??null):null,$old['created_at']??stamp()]);});result(['ok'=>true]);
    }
    if($action==='tools.schedule.create'){
        tools_assert_write($chatId,$uid);rate('schedule-create:'.$uid,30,60);$at=tools_time($data,'deliver_at');$body=message_text($data);$nonce=tools_nonce($data);
        $id=transaction(function()use($uid,$chatId,$at,$body,$nonce){query('UPDATE users SET name=name WHERE id=?',[$uid]);$s=one('SELECT id,chat_id FROM scheduled_messages WHERE user_id=? AND nonce=?',[$uid,$nonce]);if($s){if((int)$s['chat_id']!==$chatId)fail(409,'Идентификатор уже использован.');return (int)$s['id'];}
            if((int)one('SELECT COUNT(*) AS n FROM scheduled_messages WHERE user_id=? AND status=?',[$uid,'pending'])['n']>=100)fail(422,'Максимум 100 ожидающих сообщений.');
            query('INSERT INTO scheduled_messages(user_id,chat_id,body,deliver_at,nonce,created_at) VALUES (?,?,?,?,?,?)',[$uid,$chatId,message_store($body),$at,$nonce,stamp()]);return (int)db()->lastInsertId();});result(['id'=>$id]);
    }
    if($action==='tools.poll.create'){
        tools_assert_write($chatId,$uid);rate('poll-create:'.$uid,20,60);$question=field($data,'question',1,500);$options=$data['options']??null;
        if(!is_array($options)||!array_is_list($options)||count($options)<2||count($options)>10)fail(422,'Опрос: от 2 до 10 вариантов.');
        $labels=[];foreach($options as $v)$labels[]=field(['label'=>$v],'label',1,100);if(count(array_unique($labels))!==count($labels))fail(422,'Варианты не должны повторяться.');
        if(!is_bool($data['multiple']??null))fail(422,'Укажите режим выбора.');$at=tools_time($data,'closes_at',true);$nonce='poll_'.tools_nonce($data);
        $id=transaction(function()use($uid,$chatId,$question,$labels,$data,$at,$nonce){query('UPDATE users SET name=name WHERE id=?',[$uid]);$old=one('SELECT m.id,m.chat_id,p.message_id FROM messages m LEFT JOIN chat_polls p ON p.message_id=m.id WHERE m.user_id=? AND m.nonce=?',[$uid,$nonce]);if($old){if((int)$old['chat_id']!==$chatId||!$old['message_id'])fail(409,'Идентификатор уже использован.');return (int)$old['id'];}
            query('INSERT INTO messages(chat_id,user_id,body,nonce,created_at) VALUES (?,?,?,?,?)',[$chatId,$uid,message_store($question),$nonce,stamp()]);$id=(int)db()->lastInsertId();query('INSERT INTO chat_polls(message_id,creator_id,multiple,closes_at) VALUES (?,?,?,?)',[$id,$uid,$data['multiple']?1:0,$at]);foreach($labels as $i=>$label)query('INSERT INTO poll_options(poll_id,position,label) VALUES (?,?,?)',[$id,$i,message_store($label)]);return $id;});bot_platform_publish_message($id);result(['id'=>$id]);
    }
    if($action==='tools.poll.vote'||$action==='tools.poll.close'){
        $id=(int)($data['message_id']??0);
        transaction(function()use($id,$chatId,$chat,$uid,$action,$data){
            query('UPDATE chat_polls SET closed=closed WHERE message_id=?',[$id]);$p=one('SELECT p.* FROM chat_polls p JOIN messages m ON m.id=p.message_id WHERE p.message_id=? AND m.chat_id=?',[$id,$chatId]);if(!$p)fail(404,'Опрос удалён.');
            if($action==='tools.poll.close'){if((int)$p['creator_id']!==$uid&&$chat['role']!=='owner'&&!group_can($chat,$uid,'delete'))fail(403,'Нет права закрыть опрос.');query('UPDATE chat_polls SET closed=1 WHERE message_id=?',[$id]);if($chat['kind']==='group')group_log($chatId,$uid,'poll.close',$id);return;}
            if($chat['kind']==='group')tools_assert_write($chatId,$uid);if($p['closed']||($p['closes_at']&&$p['closes_at']<=stamp()))fail(409,'Опрос уже закрыт.');
            $choices=$data['option_ids']??null;if(!is_array($choices)||!array_is_list($choices)||count($choices)>10)fail(422,'Выберите варианты.');
            foreach($choices as $v)if(!is_int($v)||$v<1)fail(422,'Некорректный вариант.');$choices=array_values(array_unique($choices));if(!$p['multiple']&&count($choices)>1)fail(422,'Разрешён один вариант.');
            foreach($choices as $v)if(!one('SELECT id FROM poll_options WHERE id=? AND poll_id=?',[$v,$id]))fail(422,'Вариант из другого опроса.');
            query('DELETE FROM poll_votes WHERE poll_id=? AND user_id=?',[$id,$uid]);foreach($choices as $v)query('INSERT INTO poll_votes(poll_id,user_id,option_id) VALUES (?,?,?)',[$id,$uid,$v]);
        });result(['poll'=>tools_poll_states([$id],$chatId,$uid)[0]]);
    }
    if(str_starts_with($action,'tools.group.')){
        if($chat['kind']!=='group')fail(422,'Выберите группу.');
        $rights=[];foreach(['delete','info','members','restrict'] as $right)$rights[$right]=group_can($chat,$uid,$right);
        if($action==='tools.group.info'){
            $people=query('SELECT u.id,u.name,u.username,m.role,g.can_delete,g.can_info,g.can_members,g.can_restrict,l.until_at,l.reason FROM members m JOIN users u ON u.id=m.user_id LEFT JOIN group_grants g ON g.chat_id=m.chat_id AND g.user_id=m.user_id LEFT JOIN group_limits l ON l.chat_id=m.chat_id AND l.user_id=m.user_id WHERE m.chat_id=? ORDER BY u.name',[$chatId])->fetchAll();result(['members'=>$people,'rights'=>$rights,'owner'=>$chat['role']==='owner']);
        }
        if($action==='tools.group.log'){
            if(!in_array(true,$rights,true))fail(403,'Журнал доступен администраторам группы.');$before=max(0,(int)($data['before']??0));$params=['member.%','rights.update',$chatId];$extra=$before?' AND l.id<?':'';if($before)$params[]=$before;
            $rows=query('SELECT l.*,u.name AS actor_name,t.name AS target_name FROM group_action_log l JOIN users u ON u.id=l.actor_id LEFT JOIN users t ON t.id=l.target_id AND (l.action LIKE ? OR l.action=?) WHERE l.chat_id=?'.$extra.' ORDER BY l.id DESC LIMIT 51',$params)->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);result(['events'=>$rows,'has_more'=>$more,'next'=>$rows?(int)end($rows)['id']:0]);
        }
        $target=(int)($data['user_id']??0);$person=member($chatId,$target);if($person['role']==='owner'||$target===$uid)fail(403,'Нельзя изменять владельца или самого себя.');
        if($action==='tools.group.grant'){
            if($chat['role']!=='owner')fail(403,'Администраторов назначает только владелец.');$values=[];foreach(['delete','info','members','restrict'] as $right){if(!is_bool($data[$right]??null))fail(422,'Укажите все права.');$values[]=$data[$right]?1:0;}
            transaction(function()use($chatId,$target,$uid,$values){query('UPDATE chats SET title=title WHERE id=?',[$chatId]);query('DELETE FROM group_grants WHERE chat_id=? AND user_id=?',[$chatId,$target]);query('INSERT INTO group_grants(chat_id,user_id,can_delete,can_info,can_members,can_restrict) VALUES (?,?,?,?,?,?)',array_merge([$chatId,$target],$values));group_log($chatId,$uid,'rights.update',$target,json_encode($values));});result(['ok'=>true]);
        }
        if($action==='tools.group.restrict'){
            if(!$rights['restrict'])fail(403,'Нет права ограничивать участников.');$grant=one('SELECT can_delete+can_info+can_members+can_restrict AS n FROM group_grants WHERE chat_id=? AND user_id=?',[$chatId,$target]);if($chat['role']!=='owner'&&(int)($grant['n']??0)>0)fail(403,'Ограничивать администратора может только владелец.');
            $until=tools_time($data,'until_at',true);$reason=field(['reason'=>$data['reason']??''],'reason',0,200);
            transaction(function()use($chatId,$uid,$target,$until,$reason){query('UPDATE chats SET title=title WHERE id=?',[$chatId]);query('DELETE FROM group_limits WHERE chat_id=? AND user_id=?',[$chatId,$target]);if($until)query('INSERT INTO group_limits(chat_id,user_id,until_at,reason) VALUES (?,?,?,?)',[$chatId,$target,$until,$reason]);group_log($chatId,$uid,$until?'member.restrict':'member.unrestrict',$target,$until?:'');});result(['ok'=>true]);
        }
    }
    fail(404,'Действие не найдено.');
}
