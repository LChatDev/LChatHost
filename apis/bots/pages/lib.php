<?php
declare(strict_types=1);

const TEMP_SITE_TTL = 600;
const TEMP_SITE_MAX_FILES = 20;
const TEMP_SITE_MAX_BYTES = 1048576;

function temp_site_root(): string {
    $root = dirname(__DIR__, 3) . '/data/bot-temp-sites';
    if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) throw new RuntimeException('Не удалось создать хранилище временных сайтов.');
    return $root;
}

function temp_site_id(): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $id = '';
        for ($i = 0; $i < 30; $i++) $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    } while (file_exists(temp_site_root() . '/' . $id) || file_exists(temp_site_root() . '/' . $id . '.creating'));
    return $id;
}

function temp_site_valid_path(string $path): bool {
    if ($path === '' || strlen($path) > 180 || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) return false;
    $parts = explode('/', $path);
    foreach ($parts as $part) if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $part) || $part === '..') return false;
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, ['html', 'htm', 'css', 'js', 'json', 'txt', 'svg', 'php'], true);
}

function temp_site_remove(string $id): void {
    if (!preg_match('/^[a-z0-9]{30}$/D', $id)) return;
    $root = realpath(temp_site_root());
    $path = realpath(temp_site_root() . '/' . $id);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) @unlink($entry->getPathname());
        elseif ($entry->isDir()) @rmdir($entry->getPathname());
    }
    @rmdir($path);
}

function temp_site_cleanup(): int {
    $removed = 0;
    foreach (glob(temp_site_root() . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $id = basename($directory);
        if (!preg_match('/^[a-z0-9]{30}$/D', $id)) continue;
        $meta = json_decode((string)@file_get_contents($directory . '/meta.json'), true);
        if (!is_array($meta) || (int)($meta['expires_at'] ?? 0) <= time()) { temp_site_remove($id); $removed++; }
    }
    return $removed;
}

function temp_site_create(int $botId, int $ownerRef, string $name, array $files, string $publicUrl): array {
    temp_site_cleanup();
    $name = trim($name);
    if ($ownerRef < 1 || $name === '' || mb_strlen($name, 'UTF-8') > 80) throw new DomainException('Некорректное имя проекта.');
    if (!$files || count($files) > TEMP_SITE_MAX_FILES) throw new DomainException('В проекте должно быть от 1 до 20 файлов.');
    $normalized = []; $total = 0;
    foreach ($files as $path => $content) {
        if (!is_string($path) || !is_string($content) || !temp_site_valid_path($path) || !mb_check_encoding($content, 'UTF-8')) throw new DomainException('Некорректный файл проекта.');
        $total += strlen($content);
        if ($total > TEMP_SITE_MAX_BYTES) throw new DomainException('Размер проекта не должен превышать 1 МиБ.');
        $normalized[$path] = $content;
    }
    if (!isset($normalized['index.html']) && !isset($normalized['index.htm']) && !isset($normalized['index.php'])) throw new DomainException('Добавьте index.html или index.php.');
    $id = temp_site_id(); $root = temp_site_root(); $temporary = $root . '/' . $id . '.creating'; $target = $root . '/' . $id;
    if (!mkdir($temporary, 0755, true)) throw new RuntimeException('Не удалось создать папку проекта.');
    try {
        foreach ($normalized as $relative => $content) {
            $path = $temporary . '/files/' . $relative; $parent = dirname($path);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) throw new RuntimeException('Не удалось создать структуру проекта.');
            if (file_put_contents($path, $content, LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить файл проекта.');
        }
        $now = time(); $meta = ['id' => $id, 'bot_id' => $botId, 'owner_ref' => $ownerRef, 'name' => $name, 'created_at' => $now, 'expires_at' => $now + TEMP_SITE_TTL, 'files' => array_keys($normalized), 'bytes' => $total];
        if (file_put_contents($temporary . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($temporary, $target)) throw new RuntimeException('Не удалось опубликовать проект.');
    } catch (Throwable $e) {
        if (is_dir($temporary)) { rename($temporary, $target); temp_site_remove($id); }
        throw $e;
    }
    $base = rtrim($publicUrl, '/') . '/apis/bots/pages/temps/sites/' . $id;
    return $meta + ['url' => $base . '/', 'ttl' => TEMP_SITE_TTL];
}

function temp_site_list(int $botId, int $ownerRef, string $publicUrl): array {
    temp_site_cleanup(); $items = [];
    foreach (glob(temp_site_root() . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $meta = json_decode((string)@file_get_contents($directory . '/meta.json'), true);
        if (!is_array($meta) || (int)($meta['bot_id'] ?? 0) !== $botId || (int)($meta['owner_ref'] ?? 0) !== $ownerRef || (int)($meta['expires_at'] ?? 0) <= time()) continue;
        $meta['url'] = rtrim($publicUrl, '/') . '/apis/bots/pages/temps/sites/' . $meta['id'] . '/';
        $meta['ttl'] = max(0, (int)$meta['expires_at'] - time()); $items[] = $meta;
    }
    usort($items, static fn(array $a, array $b): int => (int)$b['created_at'] <=> (int)$a['created_at']);
    return $items;
}

function temp_site_get(string $id): ?array {
    if (!preg_match('/^[a-z0-9]{30}$/D', $id)) return null;
    $meta = json_decode((string)@file_get_contents(temp_site_root() . '/' . $id . '/meta.json'), true);
    return is_array($meta) ? $meta : null;
}

function temp_site_php(string $source): string {
    $value = static function (string $expression): string {
        $expression = trim($expression);
        if (preg_match('/^htmlspecialchars\(\s*\$_GET\[[\'\"]([A-Za-z0-9_-]{1,32})[\'\"]\]\s*\?\?\s*[\'\"]([^\'\"]{0,200})[\'\"]\s*\)$/D', $expression, $m) || preg_match('/^\$_GET\[[\'\"]([A-Za-z0-9_-]{1,32})[\'\"]\]\s*\?\?\s*[\'\"]([^\'\"]{0,200})[\'\"]$/D', $expression, $m)) return htmlspecialchars((string)($_GET[$m[1]] ?? $m[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (preg_match('/^date\([\'\"]([A-Za-z0-9 :._\/-]{1,40})[\'\"]\)$/D', $expression, $m)) return htmlspecialchars(date($m[1]), ENT_QUOTES, 'UTF-8');
        if (preg_match('/^[\'\"]([^\'\"]{0,500})[\'\"]$/D', $expression, $m)) return htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '';
    };
    $source = preg_replace_callback('/<\?=\s*(.*?)\s*\?>/s', static fn(array $m): string => $value($m[1]), $source) ?? '';
    $source = preg_replace_callback('/<\?php\s+echo\s+(.*?);?\s*\?>/s', static fn(array $m): string => $value(rtrim(trim($m[1]), ';')), $source) ?? '';
    return preg_replace('/<\?php.*?\?>/s', '', $source) ?? '';
}
