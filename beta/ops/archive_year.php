<?php
// Годовая архивация: php ops/archive_year.php 2026 [--run]
// Без --run только показывает план. Таблицы articles и article_raw уходят в БД crawler_archive (articles_ГГГГ),
// рабочие таблицы создаются пустыми (id продолжаются), последние 7 дней копируются обратно, чтобы дедуп не дал дублей.
if (PHP_SAPI !== 'cli') exit('CLI only');
require __DIR__ . '/../lib/crawler.php';
$cfg = require __DIR__ . '/../config.php';
$year = (int)($argv[1] ?? 0);
$run = in_array('--run', $argv, true);
if ($year < 2020 || $year > 2100) exit("Usage: php ops/archive_year.php YEAR [--run]\n");

$db = crawler_connect($cfg['crawler_db']);
$arch = 'crawler_archive';
$q = fn($sql) => ($r = $db->query($sql)) ? $r->fetch_row() : null;
if ($q("SHOW TABLES FROM $arch LIKE 'articles_$year'")) exit("articles_$year already exists in $arch. Nothing done.\n");

$maxId = (int)$q('SELECT COALESCE(MAX(id), 0) FROM articles')[0];
$rows = (int)$q('SELECT COUNT(*) FROM articles')[0];
$next = $maxId + 100000;
echo "Plan for $year:\n - move articles ($rows rows, max id $maxId) -> $arch.articles_$year\n - move article_raw -> $arch.article_raw_$year\n"
   . " - new empty tables, AUTO_INCREMENT = $next\n - copy last 7 days back into the new articles table (keeps dedup working)\n";
if (!$run) exit("Dry run. Add --run to execute.\n");

$steps = [
    'CREATE TABLE articles_new LIKE articles',
    'CREATE TABLE article_raw_new LIKE article_raw',
    "ALTER TABLE articles_new AUTO_INCREMENT = $next",
    "RENAME TABLE articles TO $arch.articles_$year, articles_new TO articles, article_raw TO $arch.article_raw_$year, article_raw_new TO article_raw",
    "UPDATE $arch.articles_$year SET content_status = 'partial', next_retry_at = NULL WHERE content_status = 'pending'",
    "INSERT IGNORE INTO articles SELECT * FROM $arch.articles_$year WHERE published_at >= NOW() - INTERVAL 7 DAY",
    "UPDATE sources s SET unfinished_count = (SELECT COUNT(*) FROM articles a WHERE a.source_id = s.id AND a.content_status <> 'full')",
];
foreach ($steps as $sql) {
    echo '> ' . substr($sql, 0, 110) . "\n";
    if (!$db->query($sql)) exit("FAILED: {$db->error}\n");
}
echo "Done. Archive: $arch.articles_$year. Searching old years needs an index over the archive tables.\n";
