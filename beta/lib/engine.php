<?php
// Общие службы движка: слоты вежливости, журнал изменений, обслуживание, метрики здоровья.
const ENGINE_RAW_DAYS = 30;        // сырой RSS хранится 30 дней
const ENGINE_JOURNAL_DAYS = 30;
const ENGINE_PENDING_WAIT = 600;   // через 10 минут без полного текста статья всё равно уходит в журнал (в индекс)

// Время (unix, с долями), когда можно слать следующий запрос к хосту. Общий для всех процессов.
function host_slot(mysqli $db, string $kind, string $host, int $gapMs): float
{
    $key = $db->real_escape_string(mb_substr($kind . ':' . strtolower($host), 0, 190));
    $db->query("INSERT IGNORE INTO host_slots (slot_key, next_at) VALUES ('$key', NOW(3))");
    $db->query("UPDATE host_slots SET next_at = (@mm_slot := GREATEST(NOW(3), next_at)) + INTERVAL " . ($gapMs * 1000) . " MICROSECOND WHERE slot_key = '$key'");
    $r = $db->query('SELECT UNIX_TIMESTAMP(@mm_slot)');
    $v = $r ? $r->fetch_row()[0] : null;
    return $v !== null ? (float)$v : microtime(true);
}

function journal_add(mysqli $db, array $ids, string $op = 'upsert'): void
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    foreach (array_chunk($ids, 500) as $chunk) {
        $db->query("INSERT INTO article_changes (article_id, op) VALUES " . implode(',', array_map(fn($i) => "($i, '$op')", $chunk)));
    }
}

// Свежесть статьи в журнале: первая запись ставит journal_at
function journal_touch(mysqli $db, int $articleId): void
{
    $db->query("UPDATE articles SET journal_at = COALESCE(journal_at, NOW()) WHERE id = $articleId");
    journal_add($db, [$articleId]);
}

function engine_heartbeat(mysqli $db, int $id, string $role, int $jobs = 0, ?int $cycleMs = null): void
{
    $db->query("INSERT INTO crawler_status (id, role, heartbeat_at, started_at, pid, jobs_done) VALUES ($id, '$role', NOW(), NOW(), " . getmypid() . ", 0)
        ON DUPLICATE KEY UPDATE role = '$role', heartbeat_at = NOW(), jobs_done = jobs_done + $jobs, pid = " . getmypid()
        . ($cycleMs !== null ? ", last_cycle_ms = $cycleMs" : ''));
}

function engine_register(mysqli $db, int $id, string $role): void
{
    $db->query("INSERT INTO crawler_status (id, role, heartbeat_at, started_at, pid, jobs_done) VALUES ($id, '$role', NOW(), NOW(), " . getmypid() . ", 0)
        ON DUPLICATE KEY UPDATE role = '$role', heartbeat_at = NOW(), started_at = NOW(), pid = " . getmypid() . ', jobs_done = 0');
}

// Один экземпляр процесса: блокировка файла
function engine_lock(string $name)
{
    $f = fopen(sys_get_temp_dir() . '/mm_' . $name . '.lock', 'c');
    if (!$f || !flock($f, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another instance of $name is already running\n");
        exit(1);
    }
    return $f;
}

// Не перегружаем сервер и БД
function engine_overloaded(mysqli $db, float $maxLoad = 5.0, int $maxThreads = 25): ?string
{
    $l = sys_getloadavg();
    if ($l && $l[0] > $maxLoad) {
        return 'server load ' . round($l[0], 1);
    }
    $r = $db->query("SHOW GLOBAL STATUS LIKE 'Threads_running'");
    $t = $r ? (int)($r->fetch_row()[1] ?? 0) : 0;
    return $t > $maxThreads ? "database busy ($t running queries)" : null;
}

// Раз в 10 минут: частота публикаций, авто-интервал, «молчащие» источники
function engine_source_stats(mysqli $db): void
{
    $db->query('UPDATE sources s SET articles_7d = (SELECT COUNT(*) FROM articles a WHERE a.source_id = s.id AND a.published_at >= NOW() - INTERVAL 7 DAY) WHERE s.is_active = 1');
    $r = $db->query('SELECT id, check_interval_sec, interval_mode, articles_7d, last_article_at, silent_since FROM sources WHERE is_active = 1');
    while ($r && ($s = $r->fetch_assoc())) {
        $id = (int)$s['id'];
        $gap = (int)$s['articles_7d'] >= 5 ? (int)(7 * 86400 / (int)$s['articles_7d']) : null;   // типичная пауза между статьями, с
        $auto = ($s['interval_mode'] === 'auto' && $gap) ? max((int)$s['check_interval_sec'], min(900, (int)round($gap / 4))) : null;
        $db->query("UPDATE sources SET auto_interval_sec = " . ($auto ?? 'NULL') . " WHERE id = $id");

        $last = $s['last_article_at'] ? strtotime($s['last_article_at'] . ' UTC') : null;
        $silentFor = $last ? time() - $last : 0;
        $isSilent = $gap && $last && $silentFor > max(6 * $gap, 10800);
        if ($isSilent && !$s['silent_since']) {
            $db->query("UPDATE sources SET silent_since = NOW() WHERE id = $id");
            crawl_log($db, $id, 'warning', 'silent_source', null, 0, 0,
                sprintf('No new articles for %s. Usually about one every %s. The feed answers normally, so the site may have stopped publishing or changed its feed.', fmt_span($silentFor), fmt_span($gap)));
        } elseif (!$isSilent && $s['silent_since']) {
            $db->query("UPDATE sources SET silent_since = NULL WHERE id = $id");
            crawl_log($db, $id, 'info', 'source_recovered', null, 0, 0, 'New articles are arriving again.');
        }
    }
}

function fmt_span(int $s): string
{
    if ($s < 3600) return max(1, intdiv($s, 60)) . ' min';
    if ($s < 172800) return round($s / 3600, 1) . ' h';
    return round($s / 86400, 1) . ' days';
}

// Раз в минуту: статьи, которые слишком долго ждут полный текст, уходят в журнал как есть
function engine_journal_pending(mysqli $db): void
{
    $ids = [];
    $r = $db->query("SELECT id FROM articles WHERE content_status = 'pending' AND journal_at IS NULL AND fetched_at < NOW() - INTERVAL " . ENGINE_PENDING_WAIT . ' SECOND LIMIT 1000');
    while ($r && ($x = $r->fetch_row())) $ids[] = (int)$x[0];
    if ($ids) {
        $db->query('UPDATE articles SET journal_at = NOW() WHERE id IN (' . implode(',', $ids) . ')');
        journal_add($db, $ids);
    }
}

// Раз в час: очистка служебных таблиц
function engine_purge(mysqli $db): void
{
    crawl_purge_logs($db, 30);
    foreach (['article_changes' => ENGINE_JOURNAL_DAYS] as $t => $d) {
        do { $db->query("DELETE FROM $t WHERE created_at < NOW() - INTERVAL $d DAY LIMIT 5000"); } while ($db->affected_rows > 0);
    }
    $r = $db->query('SELECT MAX(id) FROM articles WHERE fetched_at < NOW() - INTERVAL ' . ENGINE_RAW_DAYS . ' DAY');
    $maxId = $r ? (int)($r->fetch_row()[0] ?? 0) : 0;
    if ($maxId > 0) {
        do { $db->query("DELETE FROM article_raw WHERE article_id <= $maxId LIMIT 5000"); } while ($db->affected_rows > 0);
    }
    $db->query("DELETE FROM host_slots WHERE next_at < NOW() - INTERVAL 1 DAY");
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
