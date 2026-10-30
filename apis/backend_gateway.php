<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

// The public website proxies only LChat PHP endpoints, never an arbitrary URL.
function backend_gateway(): void {
    if (PHP_SAPI === 'cli') return;
    // The website shell is served locally without accessing the backend configuration.
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'index.php') return;
    $settings = config()['backend'] ?? ['mode' => 'host'];
    if (($settings['mode'] ?? 'host') === 'host') return;
    if (($settings['mode'] ?? '') !== 'pc') fail(503, 'Неверный режим сервера.');
    if (!empty($_SERVER['HTTP_X_LCHAT_GATEWAY'])) fail(508, 'Обнаружен цикл подключения серверов.');
    $url = trim((string)($settings['url'] ?? ''));
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) fail(503, 'Для ПК укажите HTTPS-адрес сервера в config.php.');
    if (!extension_loaded('curl')) fail(503, 'Для подключения к ПК требуется PHP curl.');
    $file = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowed = ['api.php','admin.php','bot-api.php','bot-file.php','bot-manage.php','avatar.php','channel.php','download.php','upload.php','install.php','settings.php','profile.php'];
    if (!in_array($file, $allowed, true)) fail(404, 'Страница не найдена.');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET','POST','HEAD'], true)) fail(405, 'Метод не поддерживается.');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 10 * 1024 * 1024) fail(413, 'Слишком большой запрос.');
    $destination = rtrim($url, '/') . '/' . $file;
    if (!empty($_SERVER['QUERY_STRING'])) $destination .= '?' . $_SERVER['QUERY_STRING'];
    $headers = ['X-LChat-Gateway: 1', 'Accept-Encoding: identity'];
    foreach (['HTTP_COOKIE' => 'Cookie', 'HTTP_AUTHORIZATION' => 'Authorization', 'REDIRECT_HTTP_AUTHORIZATION' => 'Authorization', 'HTTP_X_LCHAT_BOT_TOKEN' => 'X-LChat-Bot-Token', 'HTTP_X_CSRF_TOKEN' => 'X-CSRF-Token', 'HTTP_RANGE' => 'Range', 'HTTP_IF_NONE_MATCH' => 'If-None-Match'] as $key => $name) {
        if (!empty($_SERVER[$key]) && !str_contains($_SERVER[$key], "\r") && !str_contains($_SERVER[$key], "\n")) $headers[$name] = $name . ': ' . $_SERVER[$key];
    }
    $body = null;
    if ($method === 'POST') {
        $type = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_starts_with(strtolower($type), 'multipart/form-data')) {
            $body = $_POST;
            foreach ($_FILES as $name => $fileInfo) {
                if (!is_array($fileInfo) || !is_string($fileInfo['tmp_name'] ?? null) || ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($fileInfo['tmp_name'])) fail(422, 'Не удалось загрузить файл.');
                $body[$name] = new CURLFile($fileInfo['tmp_name'], (string)($fileInfo['type'] ?? 'application/octet-stream'), (string)($fileInfo['name'] ?? 'file'));
            }
        } else {
            $body = file_get_contents('php://input', false, null, 0, 10 * 1024 * 1024 + 1);
            if ($body === false || strlen($body) > 10 * 1024 * 1024) fail(413, 'Слишком большой запрос.');
            if ($type !== '' && !str_contains($type, "\r") && !str_contains($type, "\n")) $headers[] = 'Content-Type: ' . $type;
        }
    }
    $received = []; $size = 0; $temp = fopen('php://temp/maxmemory:2097152', 'w+b');
    $ch = curl_init($destination);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => array_values($headers), CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
            if (str_starts_with($line, 'HTTP/')) $received = [];
            elseif (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $received[] = [strtolower(trim($name)), trim($value)]; }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$size, $temp): int {
            $size += strlen($chunk); if ($size > 12 * 1024 * 1024) return 0;
            return fwrite($temp, $chunk);
        },
    ]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $errorCode = curl_errno($ch); $errorText = curl_error($ch); curl_close($ch);
    if (!$ok || $status < 100) { error_log('LChat gateway: cURL error ' . $errorCode . ' ' . $errorText); fclose($temp); fail(502, 'Не удалось подключиться к серверу на ПК. Проверьте HTTPS и доступность сервера.'); }
    http_response_code($status);
    foreach ($received as [$name, $value]) {
        if (str_contains($value, "\r") || str_contains($value, "\n")) continue;
        if ($name === 'set-cookie') {
            $value = preg_replace('/;\s*domain=[^;]*/i', '', $value);
            $value = preg_replace('/;\s*path=[^;]*/i', '; Path=/', $value);
            if (request_is_https() && !preg_match('/;\s*secure(?:;|$)/i', $value)) $value .= '; Secure';
            header('Set-Cookie: ' . $value, false);
        } elseif (in_array($name, ['content-type','content-disposition','cache-control','etag','last-modified','content-range','accept-ranges','retry-after'], true)) {
            header($name . ': ' . $value, false);
        } elseif ($name === 'location' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $value)) header('Location: ' . $value, true, $status);
    }
    rewind($temp); fpassthru($temp); fclose($temp); exit;
}
