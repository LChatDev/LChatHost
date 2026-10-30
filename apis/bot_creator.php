<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

function bot_creator_install(): void {
    if (one('SELECT user_id FROM system_bots WHERE code = ?', ['botcreator'])) return;
    $occupied = one('SELECT id FROM users WHERE username = ?', ['BotCreator']);
    if ($occupied) {
        error_log('LChat BotCreator: username BotCreator is occupied; reserve or rename it before provisioning.');
        return;
    }
    try {
        transaction(static function (): void {
            query('INSERT INTO users (username, email, name, password_hash, created_at, last_seen) VALUES (?, ?, ?, ?, ?, ?)', ['BotCreator', 'system-botcreator@lchat.invalid', 'BotCreator', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), stamp(), stamp()]);
            query('INSERT INTO system_bots (user_id, code) VALUES (?, ?)', [(int)db()->lastInsertId(), 'botcreator']);
        });
    } catch (PDOException $e) {
        if (!str_starts_with((string)$e->getCode(), '23')) throw $e;
        if (!one('SELECT user_id FROM system_bots WHERE code = ?', ['botcreator'])) error_log('LChat BotCreator: account creation collided with another user.');
    }
}

function bot_creator_id(): int {
    return (int)(one('SELECT user_id FROM system_bots WHERE code = ?', ['botcreator'])['user_id'] ?? 0);
}

function bot_creator_state(int $uid, string $stage = '', string $draftName = ''): void {
    query('DELETE FROM bot_creator_sessions WHERE user_id = ?', [$uid]);
    if ($stage !== '') query('INSERT INTO bot_creator_sessions (user_id, stage, draft_name, updated_at) VALUES (?, ?, ?, ?)', [$uid, $stage, $draftName, stamp()]);
}

function bot_creator_help(): string {
    return "Привет! Я @BotCreator. Создам бота LChat и выдам ему API-токен.\n\n/newbot — создать бота\n/mybots — список ваших ботов\n/token @имябота — заменить API-токен\n/setname @имябота Новое имя — изменить имя\n/cancel — отменить создание\n/help — эта подсказка\n\nДокументация: https://chat.lbs.su/docs.php";
}

function bot_creator_answer(int $uid, string $body): string {
    $body = trim($body);
    $command = mb_strtolower($body, 'UTF-8');
    if (preg_match('~^/(?:start|help)(?:@botcreator)?$~iD', $body)) return bot_creator_help();
    if (preg_match('~^/cancel(?:@botcreator)?$~iD', $body)) {
        bot_creator_state($uid);
        return 'Создание отменено. Напишите /newbot, чтобы начать заново.';
    }
    if (preg_match('~^/newbot(?:@botcreator)?$~iD', $body)) {
        bot_creator_state($uid, 'name');
        return 'Как назвать нового бота? Отправьте его отображаемое имя (2–60 символов).';
    }
    if (preg_match('~^/mybots(?:@botcreator)?$~iD', $body)) {
        $bots = query('SELECT u.username, u.name FROM bot_accounts b JOIN users u ON u.id = b.bot_user_id WHERE b.owner_user_id = ? ORDER BY b.bot_user_id DESC', [$uid])->fetchAll();
        if (!$bots) return 'У вас пока нет ботов. Напишите /newbot.';
        $lines = array_map(static fn(array $bot): string => $bot['name'] . ' · @' . $bot['username'], $bots);
        return "Ваши боты:\n" . implode("\n", $lines) . "\n\nНовый токен: /token @имябота";
    }
    if (preg_match('~^/(?:token|newtoken)(?:@botcreator)?\s+@?([a-z][a-z0-9_]{2,31})$~iD', $body, $match)) {
        $bot = one('SELECT b.bot_user_id, u.username FROM bot_accounts b JOIN users u ON u.id = b.bot_user_id WHERE b.owner_user_id = ? AND u.username = ?', [$uid, strtolower($match[1])]);
        if (!$bot) return 'Ваш бот не найден. Посмотрите /mybots.';
        $token = bot_account_rotate($uid, (int)$bot['bot_user_id']);
        return "Новый API-токен @{$bot['username']}:\n{$token}\n\nСтарый токен уже отключён. Храните новый как пароль и не публикуйте его.";
    }
    if (preg_match('~^/setname(?:@botcreator)?\s+@?([a-z][a-z0-9_]{2,31})\s+(.+)$~isD', $body, $match)) {
        $name = trim($match[2]);
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 60) return 'Новое имя: от 2 до 60 символов.';
        $bot = one('SELECT b.bot_user_id FROM bot_accounts b JOIN users u ON u.id = b.bot_user_id WHERE b.owner_user_id = ? AND u.username = ?', [$uid, strtolower($match[1])]);
        if (!$bot) return 'Ваш бот не найден. Посмотрите /mybots.';
        query('UPDATE users SET name = ? WHERE id = ?', [$name, $bot['bot_user_id']]);
        return 'Имя бота @' . strtolower($match[1]) . ' изменено на «' . $name . '».';
    }
    $state = one('SELECT stage, draft_name FROM bot_creator_sessions WHERE user_id = ?', [$uid]);
    if ($state && str_starts_with($body, '/')) return 'Сейчас создаётся бот. Отправьте запрошенное значение или /cancel.';
    if (($state['stage'] ?? '') === 'name') {
        if (mb_strlen($body, 'UTF-8') < 2 || mb_strlen($body, 'UTF-8') > 60) return 'Имя должно содержать от 2 до 60 символов. Попробуйте ещё раз.';
        bot_creator_state($uid, 'username', $body);
        return 'Теперь придумайте логин, заканчивающийся на bot. Например, helperbot. Разрешены латинские буквы, цифры и _.';
    }
    if (($state['stage'] ?? '') === 'username') {
        try { $bot = bot_account_create($uid, $state['draft_name'], $body); }
        catch (DomainException $e) { return $e->getMessage() . ' Попробуйте другой логин или /cancel.'; }
        bot_creator_state($uid);
        return "Бот @{$bot['username']} создан. API-токен:\n{$bot['token']}\n\nСкопируйте его сейчас. Используйте только на своём сервере; документация: https://chat.lbs.su/docs.php";
    }
    if ($command === '') return bot_creator_help();
    return 'Неизвестная команда. Напишите /help или /newbot.';
}

function bot_creator_process(int $chatId, int $senderId, int $messageId, string $body): void {
    $creatorId = bot_creator_id();
    if (!$creatorId || $senderId === $creatorId) return;
    $chat = one('SELECT c.kind FROM chats c JOIN members m ON m.chat_id = c.id AND m.user_id = ? WHERE c.id = ?', [$creatorId, $chatId]);
    if (($chat['kind'] ?? '') !== 'direct') return;
    if (one('SELECT bot_user_id FROM bot_accounts WHERE bot_user_id = ?', [$senderId])) return;
    transaction(static function () use ($chatId, $senderId, $messageId, $body, $creatorId): void {
        query('UPDATE chats SET title = title WHERE id = ?', [$chatId]);
        if (one('SELECT message_id FROM bot_creator_handled WHERE message_id = ?', [$messageId])) return;
        $answer = bot_creator_answer($senderId, $body);
        query('INSERT INTO messages (chat_id, user_id, body, nonce, created_at) VALUES (?, ?, ?, ?, ?)', [$chatId, $creatorId, $answer, 'botcreator_' . bin2hex(random_bytes(20)), stamp()]);
        query('INSERT INTO bot_creator_handled (message_id) VALUES (?)', [$messageId]);
    });
}
