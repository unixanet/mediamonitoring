<?php
// Консольная проверка определения способа скачивания.
// Запуск: php crawler/detect.php <адрес или домен> [auto|http|browser] [--json]
// Пример: php crawler/detect.php lurer.com/timeline/am auto
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once __DIR__ . '/../lib/crawler.php';
require_once __DIR__ . '/../lib/detect.php';

if (!function_exists('fmt_dt')) {
    function fmt_dt(?string $utc): string
    {
        if (!$utc) return '-';
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('Asia/Yerevan'));
        return $d->format('d M Y, H:i');
    }
}
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--json'));
$json = in_array('--json', $argv, true);
$input = trim((string)($args[0] ?? ''));
$method = in_array($args[1] ?? '', ['auto', 'http', 'browser'], true) ? $args[1] : 'auto';
if ($input === '') exit("Usage: php crawler/detect.php <address> [auto|http|browser] [--json]\n");

$t = microtime(true);
$r = detect_run($input, $method, 'browser', 'Asia/Yerevan');
if ($json) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit;
}
printf("Address: %s   method: %s   fetched via: %s   %.1f s\n", $input, $method, $r['via'] ?? 'direct', microtime(true) - $t);
foreach ($r['notes'] ?? [] as $n) echo "  note: $n\n";
if (!empty($r['error'])) {
    echo "\nRESULT: nothing to use. " . $r['error'] . "\n";
    exit(1);
}
foreach ($r['candidates'] as $i => $c) {
    echo "\n" . ($i + 1) . ". [" . $c['kind'] . "] " . $c['label'] . ': ' . $c['count'] . ' found' . (!empty($c['recommended']) ? '   <= recommended' : '') . "\n";
    echo "   address: " . $c['url'] . "\n";
    if (!empty($c['config'])) echo "   rule:    " . json_encode($c['config'], JSON_UNESCAPED_UNICODE) . "\n";
    if (!empty($c['note'])) echo "   note:    " . $c['note'] . "\n";
    foreach (array_slice($c['items'], 0, 10) as $it) {
        echo '   - ' . str_pad(mb_strimwidth((string)($it['date'] ?? ''), 0, 18, ''), 18) . ' ' . mb_strimwidth($it['title'] !== '' ? $it['title'] : '(open the page to read the title)', 0, 90, '...')
           . '  ' . preg_replace('#^https?://(www\.)?#', '', $it['url']) . ($it['chars'] !== null ? '  [' . $it['chars'] . ' chars]' : '') . "\n";
    }
}
echo "\n";
