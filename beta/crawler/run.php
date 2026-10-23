<?php
// Опрос лент и sitemap + обслуживание. Запуск: php crawler/run.php (systemd: mm-crawler)
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require __DIR__ . '/../lib/crawler.php';
$cfg = require __DIR__ . '/../config.php';

const MAX_PARALLEL = 15;   // одновременных соединений
const BATCH = 60;          // источников за цикл
const MAX_MEM = 256 * 1024 * 1024;

function say(string $m): void
{
    echo '[' . gmdate('Y-m-d H:i:s') . '] ' . $m . PHP_EOL;
}

$lock = engine_lock('poller');
$db = crawler_connect($cfg['crawler_db']);
engine_register($db, 1, 'poller');
say('poller started, pid ' . getmypid());
$last = ['beat' => 0, 'stats' => 0, 'journal' => 0, 'purge' => 0];

while (true) {
    try {
        if (!@$db->query('SELECT 1')) {
            $db = crawler_connect($cfg['crawler_db']);
        }
        if (time() - $last['beat'] >= 5) {
            engine_heartbeat($db, 1, 'poller');
            $last['beat'] = time();
        }
        if ($why = engine_overloaded($db)) {
            say("paused: $why");
            sleep(5);
            continue;
        }

        $due = [];
        $r = $db->query("SELECT * FROM sources WHERE is_active = 1 AND type IN ('rss','sitemap') AND (next_check_at IS NULL OR next_check_at <= NOW())
            ORDER BY next_check_at LIMIT " . BATCH);
        while ($r && ($x = $r->fetch_assoc())) {
            $due[] = $x;
        }
        if ($due) {
            $ids = implode(',', array_map(fn($s) => (int)$s['id'], $due));
            $db->query("UPDATE sources SET next_check_at = NOW() + INTERVAL 90 SECOND WHERE id IN ($ids)");   // занять
            $t = microtime(true);
            $s = crawl_run_batch($db, array_values(array_filter($due, fn($d) => $d['type'] === 'rss')), MAX_PARALLEL);
            foreach ($due as $d) {
                if ($d['type'] === 'sitemap') {
                    $sr = crawl_sitemap_source($db, $d, false);
                    $s['sources']++;
                    $s['new'] += $sr['queued'] ?? 0;
                    $s['errors'] += $sr['ok'] ? 0 : 1;
                }
            }
            $ms = (int)((microtime(true) - $t) * 1000);
            engine_heartbeat($db, 1, 'poller', $s['sources'], $ms);
            $last['beat'] = time();
            say("cycle: {$s['sources']} sources, {$s['new']} new, {$s['errors']} errors, {$ms} ms");
        } else {
            sleep(1);
        }

        if (time() - $last['journal'] >= 60) {
            engine_journal_pending($db);
            $last['journal'] = time();
        }
        if (time() - $last['stats'] >= 600) {
            engine_source_stats($db);
            $last['stats'] = time();
        }
        if (time() - $last['purge'] >= 3600) {
            engine_purge($db);
            $last['purge'] = time();
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
