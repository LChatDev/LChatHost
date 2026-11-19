<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }
function rewards_install(): void {
    $suffix = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    db()->exec('CREATE TABLE IF NOT EXISTS lchat_wallets (user_id INTEGER PRIMARY KEY, tickets INTEGER NOT NULL DEFAULT 0, verified INTEGER NOT NULL DEFAULT 0)' . $suffix);
    db()->exec('CREATE TABLE IF NOT EXISTS lchat_gift_orders (nonce VARCHAR(64) PRIMARY KEY, buyer_id INTEGER NOT NULL, gift VARCHAR(24) NOT NULL, price INTEGER NOT NULL, created_at DATETIME NOT NULL)' . $suffix);
    db()->exec('CREATE TABLE IF NOT EXISTS lchat_ticket_grants (nonce VARCHAR(64) PRIMARY KEY, actor_id INTEGER NOT NULL, user_id INTEGER NOT NULL, amount INTEGER NOT NULL, created_at DATETIME NOT NULL)' . $suffix);
    db()->exec('CREATE TABLE IF NOT EXISTS lchat_gift_recipients (nonce VARCHAR(64) PRIMARY KEY, recipient_id INTEGER NOT NULL)' . $suffix);
}
function rewards_catalog(): array {
    // Stable identifiers and prices: existing orders always retain their original item.
    $items=[
        'star'=>['⭐','Звезда',10,-1,'special','original'],
        'heart'=>['💜','Сердце',20,0,'love','original'],
        'bear'=>['🧸','Мишка',25,1,'love','original'],
        'box'=>['🎁','Сюрприз',25,2,'party','original'],
        'rose'=>['🌹','Роза',25,3,'love','original'],
        'cake'=>['🎂','Торт',50,4,'party','original'],
        'flower'=>['🌷','Цветок',30,5,'love','original'],
        'rocket'=>['🚀','Ракета',100,6,'special','original'],
        'cup'=>['🏆','Кубок',100,7,'special','original'],
        'diamond'=>['💎','Алмаз',100,8,'special','original'],
    ];
    $base=$items;
    foreach ($base as $key=>$item) {
        if ($item[3]<0) continue;
        foreach (['gold'=>['Золото',2], 'violet'=>['Аметист',3], 'ocean'=>['Океан',4], 'emerald'=>['Изумруд',5]] as $variant=>[$label,$multiplier]) {
            $items[$key.'_'.$variant]=[$item[0],$item[1].' · '.$label,$item[2]*$multiplier,$item[3],$item[4],$variant];
        }
    }
    $sheets=['one'=>['icecream','coffee','dragon','duck','lantern','noodles','lollipop','flamingo','backpack','catbag','snowman','cupcake','clover','swan','moon','giftblue'],'two'=>['medal','bouquet','book','loveletter','monkey','ufo','watch','wand','mushroom','ghost','tophat','pumpkin','snowglobe','potion','pinkbear','crystal']];
    foreach (require __DIR__.'/gift-extra.php' as [$id,$emoji,$ru,$en,$price,$category]) {
        if (isset($items[$id])) throw new LogicException('Duplicate gift identifier');
        $sprite=-1;$sheet='';foreach($sheets as $name=>$ids){$position=array_search($id,$ids,true);if($position!==false){$sprite=$position;$sheet=$name;break;}}
        $items[$id]=[$emoji,$ru,$price,$sprite,$category,'original',$sheet];
    }
    foreach (require __DIR__.'/gift-tgs-catalog.php' as $id=>$gift) { if (isset($items[$id])) throw new LogicException('Duplicate gift identifier'); $items[$id]=$gift; }
    return $items;
}
function rewards_wallet(int $uid): array {
    return one('SELECT tickets, verified FROM lchat_wallets WHERE user_id = ?',[$uid]) ?: ['tickets'=>0,'verified'=>0];
}
function rewards_purchase(array $user,string $gift,string $recipientName,string $nonce): array {
    $catalog=rewards_catalog();
    if(!isset($catalog[$gift]) || !preg_match('/^[a-f0-9]{48}$/D',$nonce)) throw new DomainException('Некорректный подарок.');
    $recipientName=strtolower(ltrim(trim($recipientName),'@'));
    if($recipientName!=='' && !preg_match('/^[a-z0-9_]{3,32}$/D',$recipientName)) throw new DomainException('Укажите правильный логин получателя.');
    $recipient=$recipientName===''?$user:one('SELECT id,username FROM users WHERE username=?',[$recipientName]);
    if(!$recipient) throw new DomainException('Получатель не найден.');
    $recipientId=(int)$recipient['id'];
    transaction(function() use ($user,$gift,$catalog,$nonce,$recipientId) {
        $uid=(int)$user['id'];query('UPDATE users SET name=name WHERE id=?',[$uid]);
        $existing=one('SELECT o.buyer_id,o.gift,COALESCE(r.recipient_id,o.buyer_id) AS recipient_id FROM lchat_gift_orders o LEFT JOIN lchat_gift_recipients r ON r.nonce=o.nonce WHERE o.nonce=?',[$nonce]);
        if($existing){if((int)$existing['buyer_id']!==$uid || $existing['gift']!==$gift || (int)$existing['recipient_id']!==$recipientId)throw new DomainException('Некорректный запрос.');return;}
        if(!one('SELECT id FROM users WHERE id=?',[$recipientId]))throw new DomainException('Получатель не найден.');
        $price=$catalog[$gift][2];
        if(query('UPDATE lchat_wallets SET tickets=tickets-? WHERE user_id=? AND tickets>=?',[$price,$uid,$price])->rowCount()!==1)throw new DomainException('Недостаточно тикетов.');
        query('INSERT INTO lchat_gift_orders (nonce,buyer_id,gift,price,created_at) VALUES (?,?,?,?,?)',[$nonce,$uid,$gift,$price,stamp()]);
        query('INSERT INTO lchat_gift_recipients (nonce,recipient_id) VALUES (?,?)',[$nonce,$recipientId]);
    });
    return $recipient;
}
