<?php
// Демон краулера: php crawler/run.php (запускается через systemd, сервис mm-crawler)
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require __DIR__ . '/../lib/crawler.php';
$cfg = require __DIR__ . '/../config.php';

const MAX_PARALLEL = 15;   // одновременных соединений
const BATCH = 60;          // источников за цикл
const MAX_LOAD = 5.0;      // при loadavg выше пауза (сервер 6 vCore)
const MAX_MEM = 256 * 1024 * 1024;

function say(string $m): void
{
    echo '[' . gmdate('Y-m-d H:i:s') . '] ' . $m . PHP_EOL;
}

$db = crawler_connect($cfg['crawler_db']);
$db->query('INSERT INTO crawler_status (id, heartbeat_at, started_at, pid) VALUES (1, NOW(), NOW(), ' . getmypid() . ')
    ON DUPLICATE KEY UPDATE heartbeat_at = NOW(), started_at = NOW(), pid = ' . getmypid());
say('crawler started, pid ' . getmypid());
$lastPurge = 0;
$lastBeat = 0;

while (true) {
    try {
        if (!@$db->query('SELECT 1')) {
            $db = crawler_connect($cfg['crawler_db']);
        }
        if (time() - $lastBeat >= 5) {
            $db->query('UPDATE crawler_status SET heartbeat_at = NOW() WHERE id = 1');
            $lastBeat = time();
        }
        $load = sys_getloadavg();
        if ($load && $load[0] > MAX_LOAD) {
            say('high load ' . $load[0] . ', pausing');
            sleep(5);
            continue;
        }

        $due = [];
        $r = $db->query("SELECT * FROM sources WHERE is_active = 1 AND type = 'rss' AND (next_check_at IS NULL OR next_check_at <= NOW())
            ORDER BY next_check_at LIMIT " . BATCH);
        while ($r && ($x = $r->fetch_assoc())) {
            $due[] = $x;
        }
        if (!$due) {
            sleep(1);
        } else {
            $ids = implode(',', array_map(fn($s) => (int)$s['id'], $due));
            $db->query("UPDATE sources SET next_check_at = NOW() + INTERVAL 90 SECOND WHERE id IN ($ids)");   // занять
            $t = microtime(true);
            $s = crawl_run_batch($db, $due, MAX_PARALLEL);
            $ms = (int)((microtime(true) - $t) * 1000);
            $db->query('UPDATE crawler_status SET heartbeat_at = NOW(), last_cycle_ms = ' . $ms . ' WHERE id = 1');
            $lastBeat = time();
            say("cycle: {$s['sources']} sources, {$s['new']} new articles, {$s['errors']} errors, {$ms} ms");
        }

        if (time() - $lastPurge > 3600) {
            crawl_purge_logs($db, 30);
            $lastPurge = time();
        }
        if (memory_get_usage(true) > MAX_MEM) {
            say('memory limit, exiting for restart');
            exit(0);
        }
    } catch (Throwable $e) {
        say('ERROR ' . $e->getMessage());
        sleep(5);
    }
}
