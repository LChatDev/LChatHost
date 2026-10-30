<?php
declare(strict_types=1);
define('LCHAT_INTERNAL', true);
require __DIR__ . '/app.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $ctx = context();
    ensure_features();
    $owner = require_user($ctx);
    $uid = (int)$owner['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $bots = query('SELECT b.bot_user_id AS id, u.username, u.name, b.created_at FROM bot_accounts b JOIN users u ON u.id = b.bot_user_id WHERE b.owner_user_id = ? ORDER BY b.bot_user_id DESC', [$uid])->fetchAll();
        result(['bots' => $bots, 'creator_id' => bot_creator_id()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Используйте GET или POST.');
    if (!hash_equals($ctx['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Обновите страницу и повторите действие.');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) fail(413, 'Слишком большой запрос.');
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) fail(413, 'Слишком большой запрос.');
    $data = json_decode($raw, true);
    if (!is_array($data)) fail(400, 'Ожидается JSON.');
    $action = (string)($data['action'] ?? '');
    if ($action === 'create') {
        rate('bot-create:' . $uid, 10, 86400);
        $name = field($data, 'name', 2, 60);
        $username = strtolower(field($data, 'username', 6, 32));
        result(bot_account_create($uid, $name, $username));
    }
    $botId = (int)($data['bot_id'] ?? 0);
    $bot = $botId > 0 ? one('SELECT bot_user_id FROM bot_accounts WHERE bot_user_id = ? AND owner_user_id = ?', [$botId, $uid]) : null;
    if (!$bot) fail(404, 'Бот не найден.');
    if ($action === 'rotate') {
        rate('bot-rotate:' . $uid, 20, 86400);
        result(['token' => bot_account_rotate($uid, $botId)]);
    }
    if ($action === 'add') {
        rate('bot-add:' . $uid, 40, 600);
        $chatId = (int)($data['chat_id'] ?? 0);
        $chat = $chatId > 0 ? one('SELECT c.kind, m.role FROM chats c JOIN members m ON m.chat_id = c.id WHERE c.id = ? AND m.user_id = ?', [$chatId, $uid]) : null;
        if (!$chat || !in_array($chat['kind'], ['group', 'channel'], true) || $chat['role'] !== 'owner') fail(403, 'Добавить бота может владелец группы или канала.');
        try {
            query('INSERT INTO members (chat_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$chatId, $botId, $chat['kind'] === 'channel' ? 'admin' : 'member', stamp()]);
        } catch (PDOException $e) {
            if (!str_starts_with((string)$e->getCode(), '23')) throw $e;
        }
        result(['ok' => true]);
    }
    fail(404, 'Действие не найдено.');
} catch (DomainException $e) {
    fail(422, $e->getMessage());
} catch (Throwable $e) {
    error_log('LChat bot manage: ' . get_class($e) . ' ' . $e->getMessage());
    fail(500, 'Не удалось выполнить действие с ботом.');
}
