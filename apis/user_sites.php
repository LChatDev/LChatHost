<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }
require_once __DIR__ . '/bots/pages/lib.php';

const USER_SITE_MAX_FILES = 20;
const USER_SITE_MAX_BYTES = 1048576;

function user_sites_root(): string { return dirname(__DIR__) . '/data/user-sites'; }
function user_site_root(int $uid): string { return user_sites_root() . '/' . $uid; }
function user_site_remove_tree(string $path): void {
    $base=realpath(user_sites_root());$target=realpath($path);
    if($base===false||$target===false||!str_starts_with($target,$base.DIRECTORY_SEPARATOR))return;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry){if($entry->isLink()||$entry->isFile())@unlink($entry->getPathname());elseif($entry->isDir())@rmdir($entry->getPathname());}
    @rmdir($target);
}

function user_site_permission(int $uid, ?int $botId = null): string {
    $row = $botId === null
        ? one("SELECT status FROM site_permissions WHERE user_id=? AND status='approved' ORDER BY decided_at DESC LIMIT 1", [$uid])
        : one('SELECT status FROM site_permissions WHERE user_id=? AND requested_by_bot=?', [$uid, $botId]);
    return (string)($row['status'] ?? 'not_requested');
}

function user_site_info(int $uid): ?array {
    $site = one('SELECT s.user_id,s.slug,s.name,s.created_at,s.updated_at,u.username FROM user_sites s JOIN users u ON u.id=s.user_id WHERE s.user_id=?', [$uid]);
    if (!$site) return null;
    $site['url'] = rtrim((string)(config()['public_url'] ?? 'https://chat.lbs.su/'), '/') . '/sites/' . rawurlencode($site['slug']) . '/';
    return $site;
}

function user_site_files(int $uid): array {
    $root = user_site_root($uid) . '/files';
    if (!is_dir($root)) return [];
    $result = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (temp_site_valid_path($path)) $result[$path] = file_get_contents($file->getPathname()) ?: '';
    }
    return $result;
}

function user_site_publish(int $uid, string $name, array $files): array {
    if (user_site_permission($uid) !== 'approved') throw new DomainException('Сначала подтвердите разрешение у @service.');
    if (!$files || count($files) > USER_SITE_MAX_FILES) throw new DomainException('На сайте должно быть от 1 до 20 файлов.');
    $clean = []; $bytes = 0; $hasIndex = false;
    foreach ($files as $path => $content) {
        if (!is_string($path) || !is_string($content) || !temp_site_valid_path($path) || !mb_check_encoding($content, 'UTF-8')) throw new DomainException('Некорректный путь или содержимое файла.');
        $bytes += strlen($content);
        if ($bytes > USER_SITE_MAX_BYTES) throw new DomainException('Сайт не должен превышать 1 МиБ.');
        $clean[$path] = $content;
        if (in_array($path, ['index.html', 'index.htm', 'index.php'], true)) $hasIndex = true;
    }
    if (!$hasIndex) throw new DomainException('Добавьте index.html или index.php.');
    $user = one('SELECT username FROM users WHERE id=?', [$uid]);
    if (!$user) throw new DomainException('Пользователь не найден.');
    $slug = strtolower((string)$user['username']);
    $base = user_sites_root();
    if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) throw new RuntimeException('Не удалось создать хранилище сайтов.');
    $target = user_site_root($uid); $temporary = $target . '.creating-' . bin2hex(random_bytes(6));
    if (!mkdir($temporary . '/files', 0755, true)) throw new RuntimeException('Не удалось создать папку сайта.');
    try {
        foreach ($clean as $path => $content) {
            $destination = $temporary . '/files/' . $path;
            $directory = dirname($destination);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Не удалось создать папку проекта.');
            if (file_put_contents($destination, $content, LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить файл.');
        }
        if (is_dir($target)) user_site_remove_tree($target);
        if (!rename($temporary, $target)) throw new RuntimeException('Не удалось активировать сайт.');
    } catch (Throwable $e) { if (is_dir($temporary)) user_site_remove_tree($temporary); throw $e; }
    $name = trim($name); if ($name === '') $name = 'Сайт @' . $slug;
    $name = mb_substr($name, 0, 80, 'UTF-8');
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') query('INSERT INTO user_sites (user_id,slug,name,created_at,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE slug=VALUES(slug),name=VALUES(name),updated_at=VALUES(updated_at)', [$uid,$slug,$name,stamp(),stamp()]);
    else query('INSERT INTO user_sites (user_id,slug,name,created_at,updated_at) VALUES (?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET slug=excluded.slug,name=excluded.name,updated_at=excluded.updated_at', [$uid,$slug,$name,stamp(),stamp()]);
    return user_site_info($uid) ?? [];
}

function user_site_delete(int $uid): void {
    $root = user_site_root($uid);
    if (is_dir($root)) user_site_remove_tree($root);
    query('DELETE FROM user_sites WHERE user_id=?', [$uid]);
}
