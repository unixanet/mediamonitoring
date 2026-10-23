<?php
// Воркер полного текста: открывает страницы статей. Запуск: php crawler/fulltext.php <номер> (systemd: mm-fulltext@1, @2)
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require __DIR__ . '/../lib/crawler.php';
$cfg = require __DIR__ . '/../config.php';

$n = max(1, min(9, (int)($argv[1] ?? 1)));
const BATCH = 30;
const MAX_MEM = 256 * 1024 * 1024;

function say(string $m): void
{
    echo '[' . gmdate('Y-m-d H:i:s') . "] $m" . PHP_EOL;
}

$lock = engine_lock('fulltext_' . $n);
$db = crawler_connect($cfg['crawler_db']);
$wid = 10 + $n;
engine_register($db, $wid, 'fulltext');
say("fulltext worker $n started, pid " . getmypid());
$beat = 0;

while (true) {
    try {
        if (!@$db->query('SELECT 1')) {
            $db = crawler_connect($cfg['crawler_db']);
        }
        if (time() - $beat >= 5) {
            engine_heartbeat($db, $wid, 'fulltext');
            $beat = time();
        }
        if ($why = engine_overloaded($db)) {
            say("paused: $why");
            sleep(5);
            continue;
        }
        $jobs = ft_collect_jobs($db, BATCH);
        if (!$jobs) {
            sleep(2);
            continue;
        }
        $t = microtime(true);
        $f = ft_run_jobs($db, $jobs, 8, 2);
        $ms = (int)((microtime(true) - $t) * 1000);
        engine_heartbeat($db, $wid, 'fulltext', count($jobs), $ms);
        $beat = time();
        say("{$f['full']} complete, {$f['failed']} failed, {$f['partial']} gave up, {$f['inserted']} from sitemap, $ms ms");
        if (memory_get_usage(true) > MAX_MEM) {
            say('memory limit, exiting for restart');
            exit(0);
        }
    } catch (Throwable $e) {
        say('ERROR ' . $e->getMessage());
        sleep(5);
    }
}
