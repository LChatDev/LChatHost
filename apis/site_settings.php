<?php
declare(strict_types=1);
if (!defined('LCHAT_INTERNAL')) { http_response_code(404); exit; }

// Runtime overrides live in the existing settings table; credentials remain server-side.
function site_settings(): array {
    static $settings;
    if ($settings !== null) return $settings;
    $c = config();
    $turn = $c['turn'] ?? [];
    $settings = [
        'site_name' => $c['site_name'] ?? 'LChat',
        'registration_open' => (bool)($c['registration_open'] ?? true),
        'max_call_participants' => (int)($c['max_call_participants'] ?? 8),
        'call_transport' => ($c['call_transport'] ?? 'all') === 'relay' ? 'relay' : 'all',
        'turn' => ['urls' => $turn['urls'] ?? '', 'secret' => $turn['secret'] ?? '', 'ttl' => (int)($turn['ttl'] ?? 3600)],
    ];
    $keys = ['site_name', 'registration_open', 'max_call_participants', 'call_transport', 'turn_urls', 'turn_secret', 'turn_ttl'];
    $rows = query('SELECT name, value FROM settings WHERE name IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', array_map(static fn($k) => 'site_' . $k, $keys))->fetchAll();
    foreach ($rows as $row) {
        $key = substr($row['name'], 5); $value = $row['value'];
        if ($key === 'site_name' && trim($value) !== '' && mb_strlen($value, 'UTF-8') <= 60) $settings['site_name'] = $value;
        elseif ($key === 'registration_open' && in_array($value, ['0', '1'], true)) $settings[$key] = $value === '1';
        elseif ($key === 'max_call_participants' && ctype_digit($value) && (int)$value >= 2 && (int)$value <= 16) $settings[$key] = (int)$value;
        elseif ($key === 'call_transport' && in_array($value, ['all', 'relay'], true)) $settings[$key] = $value;
        elseif ($key === 'turn_urls') $settings['turn']['urls'] = $value;
        elseif ($key === 'turn_secret') $settings['turn']['secret'] = $value;
        elseif ($key === 'turn_ttl' && ctype_digit($value) && (int)$value >= 300 && (int)$value <= 86400) $settings['turn']['ttl'] = (int)$value;
    }
    return $settings;
}

function site_settings_validate(array $data): array {
    $name = trim(is_string($data['site_name'] ?? null) ? $data['site_name'] : '');
    if ($name === '' || mb_strlen($name, 'UTF-8') > 60 || strlen($name) > 240 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new DomainException('Название должно содержать 1–60 символов без управляющих знаков.');
    if (!is_bool($data['registration_open'] ?? null)) throw new DomainException('Неверное значение регистрации.');
    $limit = filter_var($data['max_call_participants'] ?? null, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 2 || $limit > 16) throw new DomainException('Лимит участников должен быть от 2 до 16.');
    $transport = $data['call_transport'] ?? '';
    if (!in_array($transport, ['all', 'relay'], true)) throw new DomainException('Неверный маршрут звонков.');
    $urls = trim(is_string($data['turn_urls'] ?? null) ? $data['turn_urls'] : '');
    if (strlen($urls) > 240 || ($urls !== '' && !preg_match('/^turns?:[^\s,]+$/iD', $urls))) throw new DomainException('Укажите один адрес TURN, например turn:turn.example.com:3478?transport=udp.');
    $ttl = filter_var($data['turn_ttl'] ?? null, FILTER_VALIDATE_INT);
    if ($ttl === false || $ttl < 300 || $ttl > 86400) throw new DomainException('Срок действия TURN должен составлять от 300 до 86400 секунд.');
    $secret = $data['turn_secret'] ?? '';
    if (!is_string($secret) || strlen($secret) > 240 || preg_match('/[\x00-\x1f\x7f]/', $secret)) throw new DomainException('Неверный секрет TURN.');
    $existingSecret = site_settings()['turn']['secret'];
    if ($transport === 'relay' && ($urls === '' || ($secret === '' && $existingSecret === ''))) throw new DomainException('Для режима только TURN нужны адрес и секрет TURN.');
    $values = ['site_name' => $name, 'registration_open' => $data['registration_open'] ? '1' : '0', 'max_call_participants' => (string)$limit, 'call_transport' => $transport, 'turn_urls' => $urls, 'turn_ttl' => (string)$ttl];
    if ($secret !== '') $values['turn_secret'] = $secret;
    return $values;
}
