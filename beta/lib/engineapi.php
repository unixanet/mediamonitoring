<?php
// Связь админки с Python-движком (engine/). Админка только показывает данные и дергает локальный API.
const ENGINE_API = 'http://127.0.0.1:8765';

function crawler_connect(array $c): mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli($c['host'], $c['user'], $c['pass'], $c['name']);
    if ($db->connect_errno) {
        throw new RuntimeException('DB connect: ' . $db->connect_error);
    }
    $db->set_charset('utf8mb4');
    return $db;
}

// Вызов API движка. Возвращает массив ответа или ['ok'=>false,'error'=>...]
function engine_call(string $path, array $data = [], int $timeout = 120, bool $reopen = true): array
{
    // Долгий вызов не должен держать блокировку сессии: иначе остальные страницы админки ждут (дашборд «не грузится»)
    $had = session_status() === PHP_SESSION_ACTIVE;
    if ($had) session_write_close();
    $ch = curl_init(ENGINE_API . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $timedOut = curl_errno($ch) === 28;
    curl_close($ch);
    if ($had && $reopen && !headers_sent()) @session_start();
    $j = $body !== false ? json_decode((string)$body, true) : null;
    if (!is_array($j)) {
        return ['ok' => false, 'error' => ($timedOut ? 'The engine is busy and did not answer in time.' : 'The engine is not running (' . ($err ?: 'bad response') . '). On the server: sudo systemctl start mmengine'), 'engine_down' => !$timedOut];
    }
    return $j;
}

// Проверка CSS-селектора (поддерживаем простые: тег, #id, .класс, через пробел)
function css_ok(string $css): bool
{
    foreach (preg_split('/\s+/', trim($css)) as $p) {
        if ($p === '' || !preg_match('/^([a-z][a-z0-9]*|\*)?(#[\w-]+)?((?:\.[\w-]+)*)$/i', $p)) return false;
    }
    return trim($css) !== '';
}

function crawl_flash(array $r, string $name): array
{
    if (empty($r['ok'])) {
        return ['err', $name . ': fetch failed. ' . ($r['error'] ?? 'unknown error')];
    }
    if (!empty($r['running'])) {
        return ['ok', $name . ': the first fetch is running in the background. The articles appear in a minute or two, refresh the page.'];
    }
    if (!empty($r['queued'])) {
        $m = sprintf('%s: %d new article URLs found.', $name, $r['queued']);
    } else {
        $m = sprintf('%s: %d new articles. Feed had %d items, %d already stored, %d older than the start date.', $name, $r['new'] ?? 0, $r['feed'] ?? 0, $r['dup'] ?? 0, $r['old'] ?? 0);
    }
    if (!empty($r['ft'])) {
        $f = $r['ft'];
        $m .= sprintf(' Full text: %d complete, %d not yet complete%s.', $f['full'], $f['failed'] + $f['partial'], !empty($f['last_error']) ? ' (' . $f['last_error'] . ')' : '');
    }
    return [!empty($r['ft']) && $f['failed'] + $f['partial'] > 0 && $f['full'] === 0 ? 'err' : 'ok', $m];
}

function journal_add(mysqli $db, array $ids, string $op = 'upsert'): void
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    foreach (array_chunk($ids, 500) as $chunk) {
        $db->query("INSERT INTO article_changes (article_id, op) VALUES " . implode(',', array_map(fn($i) => "($i, '$op')", $chunk)));
    }
}

function fmt_span(int $s): string
{
    if ($s < 3600) return max(1, intdiv($s, 60)) . ' min';
    if ($s < 172800) return round($s / 3600, 1) . ' h';
    return round($s / 86400, 1) . ' days';
}

function crawler_status(mysqli $db): array
{
    $r = @$db->query("SELECT heartbeat_at, started_at, pid, last_cycle_ms FROM crawler_status WHERE id = 1");
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        return ['online' => false, 'heartbeat_at' => null, 'started_at' => null];
    }
    $row['online'] = (time() - strtotime($row['heartbeat_at'] . ' UTC')) < 30;
    return $row;
}

// Метрики для страницы Engine
function engine_metrics(mysqli $db): array
{
    $m = [];
    $q = fn(string $sql) => ($r = $db->query($sql)) ? $r->fetch_row() : [null];
    $m['sources_active'] = (int)$q('SELECT COUNT(*) FROM sources WHERE is_active = 1')[0];
    $m['overdue_sec'] = (int)$q('SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND, next_check_at, NOW())), 0) FROM sources WHERE is_active = 1 AND next_check_at < NOW()')[0];
    $m['due_now'] = (int)$q('SELECT COUNT(*) FROM sources WHERE is_active = 1 AND next_check_at <= NOW()')[0];
    $m['pending_articles'] = (int)$q("SELECT COUNT(*) FROM articles WHERE content_status = 'pending'")[0];
    $m['oldest_pending_sec'] = (int)$q("SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND, fetched_at, NOW())), 0) FROM articles WHERE content_status = 'pending'")[0];
    $m['queue_pending'] = (int)$q("SELECT COUNT(*) FROM url_queue WHERE status = 'pending'")[0];
    $m['queue_failed'] = (int)$q("SELECT COUNT(*) FROM url_queue WHERE status = 'failed'")[0];
    $m['incomplete'] = (int)$q("SELECT COUNT(*) FROM articles WHERE content_status IN ('partial','title_only')")[0];
    $m['last_hour'] = (int)$q('SELECT COUNT(*) FROM articles WHERE fetched_at >= NOW() - INTERVAL 1 HOUR')[0];
    $m['last_24h'] = (int)$q('SELECT COUNT(*) FROM articles WHERE fetched_at >= NOW() - INTERVAL 24 HOUR')[0];
    $m['errors_24h'] = (int)$q("SELECT COUNT(*) FROM crawl_log WHERE level = 'error' AND created_at >= NOW() - INTERVAL 24 HOUR")[0];
    $m['changes_total'] = (int)$q('SELECT COUNT(*) FROM article_changes')[0];
    $m['hours'] = [];
    $r = $db->query("SELECT DATE_FORMAT(fetched_at, '%Y-%m-%d %H:00') h, COUNT(*) FROM articles WHERE fetched_at >= NOW() - INTERVAL 24 HOUR GROUP BY h ORDER BY h");
    while ($r && ($x = $r->fetch_row())) $m['hours'][$x[0]] = (int)$x[1];
    $m['workers'] = [];
    $r = $db->query('SELECT id, role, heartbeat_at, started_at, pid, last_cycle_ms, jobs_done FROM crawler_status ORDER BY id');
    while ($r && ($x = $r->fetch_assoc())) {
        $x['online'] = (time() - strtotime($x['heartbeat_at'] . ' UTC')) < 30;
        $m['workers'][] = $x;
    }
    $m['tables'] = [];
    $r = $db->query("SELECT table_name, table_rows, ROUND((data_length + index_length) / 1048576, 1) mb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT 10");
    while ($r && ($x = $r->fetch_assoc())) $m['tables'][] = $x;
    $r = $db->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_running','Threads_connected','Uptime')");
    $st = [];
    while ($r && ($x = $r->fetch_row())) $st[$x[0]] = (int)$x[1];
    $m['db'] = $st;
    $l = sys_getloadavg();
    $m['load'] = $l ? round($l[0], 2) : null;
    $m['cpus'] = max(1, (int)trim((string)@shell_exec('nproc 2>/dev/null')));
    $m['disk_free_gb'] = round(@disk_free_space('/') / 1073741824, 1);
    $m['disk_total_gb'] = round(@disk_total_space('/') / 1073741824, 1);
    $mem = @file_get_contents('/proc/meminfo');
    if ($mem && preg_match('/MemTotal:\s+(\d+)/', $mem, $a) && preg_match('/MemAvailable:\s+(\d+)/', $mem, $b)) {
        $m['mem_total_gb'] = round($a[1] / 1048576, 1);
        $m['mem_free_gb'] = round($b[1] / 1048576, 1);
    }
    $m['slow'] = [];
    $r = $db->query('SELECT id, name, last_response_ms FROM sources WHERE is_active = 1 AND last_response_ms IS NOT NULL ORDER BY last_response_ms DESC LIMIT 5');
    while ($r && ($x = $r->fetch_assoc())) $m['slow'][] = $x;
    $m['silent'] = [];
    $r = $db->query('SELECT id, name, silent_since, last_article_at FROM sources WHERE is_active = 1 AND silent_since IS NOT NULL ORDER BY silent_since LIMIT 20');
    while ($r && ($x = $r->fetch_assoc())) $m['silent'][] = $x;
    return $m;
}

function tg_username(string $v): ?string
{
    $v = trim($v);
    $v = preg_replace('#^(https?://)?(www\.)?t\.me/(s/)?#i', '', $v);
    $v = ltrim(preg_replace('#[/?].*$#', '', $v), '@');
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $v) ? $v : null;
}
