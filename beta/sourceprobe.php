<?php
// Живой предпросмотр источника: пробный забег без сохранения (JSON). Вызывается из формы источника.
require __DIR__ . '/lib/bootstrap.php';
require_perm('source.add');
header('Content-Type: application/json; charset=utf-8');
set_time_limit(90);

function probe_out(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Скачать адрес как это сделает краулер (с запасным путём через браузер для режима auto). Возвращает [ch, st, errno, usedBrowser]
function probe_fetch(array &$src, string $url, string $kind = 'feed'): array
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $st = new stdClass();
        $st->body = '';
        $st->etag = $st->lastmod = null;
        $st->cf = false;
        $ch = crawl_make_handle($src, true, $st, $url, $kind);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $f = crawl_failure($src, $url, $ch, $st, $errno);
        if ($f && $attempt === 0 && ($src['fetch_method'] ?? 'http') === 'auto' && empty($st->via_browser) && crawl_is_block($f, $errno)) {
            $src['state'] = json_encode(['browser_until' => time() + 3600]);      // только для этой пробы
            continue;
        }
        return [$ch, $st, $errno, $f, !empty($st->via_browser)];
    }
}

if (!csrf_ok()) probe_out(['ok' => false, 'error' => 'Session expired, reload the page.']);

$type = in_array($_POST['type'] ?? '', ['rss', 'sitemap', 'telegram', 'facebook'], true) ? $_POST['type'] : 'rss';
$raw = trim((string)($_POST['feed_url'] ?? ''));
$tzs = (string)($_POST['feed_timezone'] ?? 'Asia/Yerevan');
if ($raw === '') probe_out(['ok' => false, 'idle' => true]);

if ($type === 'telegram') {
    $u = tg_username($raw);
    if (!$u) probe_out(['ok' => false, 'error' => 'Enter the username of a public Telegram channel, for example durov or https://t.me/durov']);
    $url = 'https://t.me/s/' . $u;
} else {
    if ($type === 'facebook' && preg_match('#(facebook|fb)\.com#i', $raw)) {
        probe_out(['ok' => false, 'error' => 'This is the Facebook page itself. Facebook cannot be read directly: create an RSS feed for it in rss.app and paste that URL.']);
    }
    $url = preg_match('#^https?://#i', $raw) ? $raw : (preg_match('#^[\w.-]+\.[a-z]{2,}(/|$)#i', $raw) ? 'https://' . $raw : '');
    if (!filter_var($url, FILTER_VALIDATE_URL)) probe_out(['ok' => false, 'error' => 'Enter a valid http(s) address.']);
}

$method = in_array($_POST['fetch_method'] ?? '', ['auto', 'http', 'browser'], true) ? $_POST['fetch_method'] : 'auto';
$src = ['id' => 0, 'type' => $type, 'feed_url' => $url, 'fetch_method' => $method, 'fetch_profile' => ($_POST['fetch_profile'] ?? '') === 'bot' ? 'bot' : 'browser',
        'feed_timezone' => $tzs, 'state' => null, 'etag' => null, 'last_modified' => null, 'min_published_at' => null, 'articles_count' => 1, 'extra' => '{}'];

$t0 = microtime(true);
[$ch, $st, $errno, $f, $viaBrowser] = probe_fetch($src, $url);
$http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$base = ['via' => $viaBrowser ? 'browser' : 'direct', 'http' => $http, 'url' => $url, 'type' => $type];

if ($f) {
    $hints = [];
    if ($f[0] === 'cloudflare_block' || crawl_is_block($f, $errno)) {
        $hints[] = $method === 'http' ? 'Switch "How to fetch" to Auto or Real browser.' : 'The site blocks our server. Real browser mode is the strongest option; if that fails, ask the site to allowlist the server IP.';
    }
    probe_out($base + ['ok' => false, 'error' => $f[1], 'event' => $f[0], 'hints' => $hints, 'ms' => (int)((microtime(true) - $t0) * 1000), 'can_try_browser' => $method !== 'browser']);
}

$items = [];
$total = 0;
$suggest = [];
$note = '';
if ($type === 'sitemap') {
    [$kind, $entries] = sm_parse($st->body);
    if (!$kind) {
        probe_out($base + ['ok' => false, 'error' => 'This address is not a sitemap.', 'ms' => (int)((microtime(true) - $t0) * 1000)]);
    }
    if ($kind === 'index') {
        usort($entries, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
        $note = count($entries) . ' sitemaps in the index; reading the newest one: ' . ($entries[0]['loc'] ?? '');
        if (!empty($entries[0]['loc'])) {
            [$ch2, $st2, $e2, $f2] = probe_fetch($src, $entries[0]['loc']);
            if (!$f2) [$kind, $entries] = sm_parse($st2->body);
        }
    }
    usort($entries, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
    $total = count($entries);
    foreach (array_slice($entries, 0, 8) as $i => $e) {
        $row = ['title' => '', 'url' => $e['loc'], 'date' => $e['ts'] ? fmt_dt(gmdate('Y-m-d H:i:s', $e['ts'])) : '', 'chars' => null];
        if ($i < 3 && $e['loc'] !== '') {       // у первых трёх открываем страницу: показать, что заголовок и текст извлекаются
            [$ch3, $st3, $e3, $f3] = probe_fetch($src, $e['loc'], 'page');
            if (!$f3) {
                $ex = ft_extract(ft_decode($st3->body, (string)curl_getinfo($ch3, CURLINFO_CONTENT_TYPE)), ['tz' => $tzs]);
                $row['title'] = $ex['title'];
                $row['chars'] = mb_strlen($ex['text']);
                if ($ex['ts']) $row['date'] = fmt_dt(gmdate('Y-m-d H:i:s', $ex['ts']));
            } else {
                $row['title'] = '(page not available: ' . $f3[1] . ')';
            }
        }
        $items[] = $row;
    }
} else {
    if ($type === 'telegram') {
        [$parsed, $minId] = tg_parse_page($st->body, $url);
        $err = $parsed ? null : 'No posts found. The channel must be public with web preview enabled: https://t.me/s/' . tg_username($raw) . ' must show posts in a browser.';
    } else {
        [$parsed, $err] = crawl_parse_feed($st->body, $tzs);
    }
    if ($parsed === null || $err) {
        if (preg_match_all('#<link[^>]+type=["\']application/(?:rss|atom)\+xml["\'][^>]*>#i', $st->body, $m)) {
            foreach ($m[0] as $tag) {
                if (preg_match('#href=["\']([^"\']+)#i', $tag, $h)) {
                    $title = preg_match('#title=["\']([^"\']+)#i', $tag, $tt) ? html_entity_decode($tt[1]) : 'Feed';
                    $suggest[] = ['label' => $title, 'url' => (preg_match('#^https?://#', $h[1]) ? $h[1] : rtrim($url, '/') . '/' . ltrim($h[1], '/'))];
                }
            }
        }
        if (preg_match('#<sitemapindex|<urlset#i', $st->body)) {
            $suggest[] = ['label' => 'This is a sitemap: switch the type to Sitemap', 'url' => $url, 'type' => 'sitemap'];
        }
        probe_out($base + ['ok' => false, 'error' => $err ?: 'This address does not look like a feed.', 'suggest' => array_slice($suggest, 0, 5), 'ms' => (int)((microtime(true) - $t0) * 1000)]);
    }
    usort($parsed, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $total = count($parsed);
    foreach (array_slice($parsed, 0, 15) as $i) {
        $items[] = ['title' => $i['title'], 'url' => $i['url'], 'date' => fmt_dt(gmdate('Y-m-d H:i:s', $i['ts'])), 'chars' => mb_strlen($i['content'])];
    }
}
probe_out($base + ['ok' => true, 'total' => $total, 'items' => $items, 'note' => $note, 'ms' => (int)((microtime(true) - $t0) * 1000)]);
