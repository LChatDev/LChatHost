<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

const MESSAGE_ENVELOPE = 'lchat:encrypted:v1:';
const MESSAGE_PREVIEW_CHARS = 500;
// The SQL prefix includes the complete encrypted preview even for 500 four-byte characters.
const MESSAGE_PREVIEW_SQL_CHARS = 2800;

function message_key(): ?string {
    static $loaded = false, $key = null;
    if ($loaded) return $key;
    $path = config()['message_key_file'] ?? getenv('LCHAT_MESSAGE_KEY_FILE');
    if (!$path) { $loaded = true; return null; }
    $hex = is_file($path) ? trim((string) file_get_contents($path)) : '';
    if (!preg_match('/^[a-f0-9]{64}$/Di', $hex)) {
        throw new RuntimeException('Message encryption key is missing or invalid');
    }
    $key = hex2bin($hex);
    $loaded = true;
    return $key;
}

function message_seal(string $text, string $key, string $purpose): string {
    $iv = random_bytes(12);
    $ciphertext = openssl_encrypt($text, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, MESSAGE_ENVELOPE . $purpose, 16);
    if ($ciphertext === false) throw new RuntimeException('Message encryption failed');
    return base64_encode($iv . $tag . $ciphertext);
}

function message_unseal(string $encoded, string $purpose): string {
    $key = message_key();
    if ($key === null) throw new RuntimeException('Message encryption key is required');
    $bytes = base64_decode($encoded, true);
    if ($bytes === false || strlen($bytes) < 28) throw new RuntimeException('Invalid encrypted message');
    $text = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), MESSAGE_ENVELOPE . $purpose);
    if ($text === false) throw new RuntimeException('Encrypted message authentication failed');
    return $text;
}

function message_store(string $text): string {
    $key = message_key();
    if ($key === null) {
        if (str_starts_with($text, MESSAGE_ENVELOPE)) throw new DomainException('Reserved message prefix');
        return $text;
    }
    $preview = mb_substr($text, 0, MESSAGE_PREVIEW_CHARS, 'UTF-8');
    return MESSAGE_ENVELOPE . message_seal($preview, $key, 'preview') . ':' . message_seal($text, $key, 'body');
}

function message_read(?string $stored, bool $preview = false): ?string {
    if ($stored === null) return null;
    if (!str_starts_with($stored, MESSAGE_ENVELOPE)) {
        return $preview ? mb_substr($stored, 0, MESSAGE_PREVIEW_CHARS, 'UTF-8') : $stored;
    }
    $parts = explode(':', substr($stored, strlen(MESSAGE_ENVELOPE)), 2);
    if (count($parts) !== 2) throw new RuntimeException('Invalid message envelope');
    return $preview ? message_unseal($parts[0], 'preview') : message_unseal($parts[1], 'body');
}
