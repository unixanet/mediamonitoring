<?php
// robots.txt по RFC 9309: кэш по хосту, группы по User-agent, самое длинное совпадение, * и $.
const ROBOTS_TOKEN = 'mediamonitoringbot';

// Правила для нашего агента: список [allow(bool), шаблон]
function robots_rules(string $body): array
{
    $groups = [];
    $cur = null;
    $lastWasUa = false;
    foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line === '' || !str_contains($line, ':')) continue;
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        $k = strtolower($k);
        if ($k === 'user-agent') {
            if (!$lastWasUa) $cur = count($groups);
            $groups[$cur]['agents'][] = strtolower($v);
            $groups[$cur]['rules'] ??= [];
            $lastWasUa = true;
        } elseif (($k === 'allow' || $k === 'disallow') && $cur !== null) {
            $lastWasUa = false;
            if ($v !== '') $groups[$cur]['rules'][] = [$k === 'allow', $v];
            elseif ($k === 'disallow') { /* пустой Disallow: всё разрешено */ }
        } else {
            $lastWasUa = false;
        }
    }
    $pick = null;
    $best = -1;
    foreach ($groups as $g) {
        foreach ($g['agents'] as $a) {
            $score = $a === '*' ? 0 : (str_contains(ROBOTS_TOKEN, $a) || str_contains($a, ROBOTS_TOKEN) ? strlen($a) : -1);
            if ($score > $best) { $best = $score; $pick = $g['rules']; }
        }
    }
    return $pick ?? [];
}

function robots_match(string $pattern, string $path): bool
{
    $re = '';
    foreach (preg_split('/(\*|\$$)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        $re .= $part === '*' ? '.*' : ($part === '$' ? '$' : preg_quote($part, '~'));
    }
    return (bool)preg_match('~^' . $re . '~', $path);
}

// Разрешён ли путь правилами: самое длинное совпадение, при равенстве выигрывает Allow
function robots_path_allowed(array $rules, string $path): bool
{
    $bestLen = -1;
    $allowed = true;
    foreach ($rules as [$allow, $pat]) {
        if (!robots_match($pat, $path)) continue;
        $len = strlen($pat);
        if ($len > $bestLen || ($len === $bestLen && $allow)) {
            $bestLen = $len;
            $allowed = $allow;
        }
    }
    return $allowed;
}

// [разрешено?, причина]. Скачивает и кэширует robots.txt (способом источника: при блокировке через браузер)
function robots_allowed(mysqli $db, array $src, string $url): array
{
    if (empty($src['respect_robots']) && array_key_exists('respect_robots', $src)) {
        return [true, ''];
    }
    $p = parse_url($url);
    $host = strtolower((string)($p['host'] ?? ''));
    if ($host === '') return [true, ''];
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $h = $db->real_escape_string($host);
    $r = $db->query("SELECT status, body, expires_at < NOW() AS stale FROM robots_cache WHERE host = '$h'");
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row || $row['stale']) {
        $origin = ($p['scheme'] ?? 'https') . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '');
        $st = new stdClass();
        $st->body = '';
        $st->etag = $st->lastmod = null;
        $st->cf = false;
        $ch = crawl_make_handle($src, true, $st, $origin . '/robots.txt', 'page');
        curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($errno || $http >= 500) {
            $status = 'error'; $body = null; $ttl = 900;           // RFC 9309: при ошибке сервера считаем всё закрытым, пробуем снова через 15 минут
        } elseif ($http >= 400 || $http === 0) {
            $status = 'none'; $body = null; $ttl = 86400;          // 4xx: файла нет, ограничений нет
        } else {
            $status = 'ok'; $body = mb_substr($st->body, 0, 500000); $ttl = 86400;
        }
        $q = $db->prepare('INSERT INTO robots_cache (host, status, body, expires_at) VALUES (?,?,?, NOW() + INTERVAL ? SECOND)
            ON DUPLICATE KEY UPDATE status = VALUES(status), body = VALUES(body), fetched_at = NOW(), expires_at = VALUES(expires_at)');
        $q->bind_param('sssi', $host, $status, $body, $ttl);
        $q->execute();
        $q->close();
        $row = ['status' => $status, 'body' => $body];
    }
    if ($row['status'] === 'error') return [false, 'robots.txt of ' . $host . ' could not be read (server error); retrying later'];
    if ($row['status'] === 'none') return [true, ''];
    return robots_path_allowed(robots_rules((string)$row['body']), $path) ? [true, ''] : [false, 'Disallowed by robots.txt of ' . $host];
}
