<?php
// Определение способа скачивания: sitemap (через robots.txt), RSS, список ссылок на странице.
// Используется в форме источника: пользователь вставляет адрес, видит что нашлось, выбирает.

// Скачать адрес как краулер (в режиме auto при блокировке повтор через браузер). Возвращает [body, failure|null, http, ctype, viaBrowser, ms]
function detect_fetch(array &$src, string $url, string $kind = 'feed'): array
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
            $src['state'] = json_encode(['browser_until' => time() + 3600]);      // дальше в этом определении идём через браузер
            continue;
        }
        return [$st->body, $f, (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), !empty($st->via_browser), (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000)];
    }
}

function detect_abs(string $href, string $base): string
{
    $href = trim(html_entity_decode($href));
    if ($href === '' || $href[0] === '#' || preg_match('#^(mailto:|javascript:|tel:)#i', $href)) {
        return '';
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $p = parse_url($base);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($href, '//')) return ($p['scheme'] ?? 'https') . ':' . $href;
    if ($href[0] === '/') return $origin . $href;
    $dir = rtrim(dirname($p['path'] ?? '/'), '/');
    return $origin . $dir . '/' . $href;
}

function detect_host(string $u): string
{
    return preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST)));
}

// Текст ссылки без вложенных <time> и скриптов
function detect_text(DOMNode $n): string
{
    $out = '';
    foreach ($n->childNodes as $c) {
        if ($c instanceof DOMElement && in_array(strtolower($c->nodeName), ['time', 'script', 'style', 'svg'], true)) continue;
        $out .= $c instanceof DOMElement ? ' ' . detect_text($c) . ' ' : $c->textContent;
    }
    return trim(preg_replace('/\s+/u', ' ', $out));
}

// Тип сегмента пути: N число, S слаг (длинный с дефисами), W обычное слово
function detect_seg(string $s): string
{
    if (preg_match('/\d{3,}/', $s)) return 'N';
    if (strlen($s) > 22 || substr_count($s, '-') >= 2 || preg_match('/%[0-9A-F]{2}/i', $s)) return 'S';
    return 'W';
}

function detect_shape(string $url): string
{
    $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
    if ($path === '') return '';
    return implode('/', array_map('detect_seg', explode('/', $path)));
}

function detect_shape_regex(string $shape): string
{
    $map = ['N' => '\d[\w.-]*', 'S' => '[^/]+', 'W' => '[^/]+'];
    return '^/' . implode('/', array_map(fn($t) => $map[$t], explode('/', $shape))) . '/?$';
}

// Время из списка в unix: «Այսօր - 23:38» (сегодня), «Երեկ - 10:05» (вчера) или обычная дата
function detect_time_hint(string $text, string $tz = 'Asia/Yerevan'): ?int
{
    $text = trim($text);
    if ($text === '') return null;
    $now = new DateTime('now', new DateTimeZone($tz));
    if (preg_match('/(\d{1,2}):(\d{2})/', $text, $m)) {
        if (str_contains($text, 'Այսօր') || stripos($text, 'today') !== false || str_contains($text, 'Сегодня')) {
            $now->setTime((int)$m[1], (int)$m[2]);
            return min($now->getTimestamp(), time());
        }
        if (str_contains($text, 'Երեկ') || stripos($text, 'yesterday') !== false || str_contains($text, 'Вчера')) {
            $now->modify('-1 day')->setTime((int)$m[1], (int)$m[2]);
            return $now->getTimestamp();
        }
    }
    return crawl_parse_date($text, $tz);
}

// CSS-подобный селектор для узла (до 2 классов или id)
function detect_selector(DOMElement $n): string
{
    $id = $n->getAttribute('id');
    if ($id !== '' && preg_match('/^[\w-]+$/', $id) && !preg_match('/\d{4,}/', $id)) return strtolower($n->nodeName) . '#' . $id;
    $cls = array_values(array_filter(preg_split('/\s+/', $n->getAttribute('class')), fn($c) => preg_match('/^[A-Za-z][\w-]*$/', $c) && !preg_match('/\d{3,}/', $c)));
    return strtolower($n->nodeName) . ($cls ? '.' . implode('.', array_slice($cls, 0, 2)) : '');
}

// Достать ссылки списка по правилу {container, pattern} со страницы. Возвращает [[url,title,time]]
function listing_extract(string $html, string $pageUrl, array $rule): array
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    $x = new DOMXPath($d);
    $cx = !empty($rule['container']) ? ft_css_to_xpath((string)$rule['container']) : null;
    $anchors = $x->query(($cx ?: '') . '//a[@href]');
    if ($cx && (!$anchors || !$anchors->length)) {
        $anchors = $x->query('//a[@href]');           // контейнер не найден: ищем по всей странице
    }
    $re = '~' . str_replace('~', '\~', (string)($rule['pattern'] ?? '.')) . '~u';
    $host = detect_host($pageUrl);
    $seen = [];
    $out = [];
    foreach ($anchors as $a) {
        $u = detect_abs($a->getAttribute('href'), $pageUrl);
        if ($u === '' || detect_host($u) !== $host) continue;
        $path = (string)parse_url($u, PHP_URL_PATH);
        if (!preg_match($re, $path)) continue;
        $key = $host . $path;
        if (isset($seen[$key])) continue;
        $title = detect_text($a);
        if (mb_strlen($title) < 8) continue;
        $seen[$key] = 1;
        $t = $x->query('.//time', $a);
        $out[] = ['url' => $u, 'title' => mb_substr($title, 0, 500), 'time' => $t->length ? trim($t->item(0)->textContent) : ''];
    }
    return $out;
}

// Лежит ли узел внутри предка (DOMNode::contains есть только в PHP 8.3+, а на сервере 8.1)
function detect_inside(DOMNode $n, DOMNode $anc): bool
{
    for ($p = $n->parentNode; $p; $p = $p->parentNode) {
        if ($p->isSameNode($anc)) return true;
    }
    return false;
}

// Найти на странице повторяющиеся ссылки на статьи. Возвращает правило и ссылки либо null
function detect_listing(string $html, string $pageUrl): ?array
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    $x = new DOMXPath($d);
    foreach (iterator_to_array($x->query('//script|//style|//noscript|//nav|//footer|//header[not(.//a[string-length(normalize-space(.))>25])]')) as $n) {
        if ($n->parentNode) $n->parentNode->removeChild($n);
    }
    $host = detect_host($pageUrl);
    $groups = [];
    foreach ($x->query('//a[@href]') as $a) {
        $u = detect_abs($a->getAttribute('href'), $pageUrl);
        if ($u === '' || detect_host($u) !== $host) continue;
        $title = detect_text($a);
        $len = mb_strlen($title);
        if ($len < 15 || $len > 400) continue;
        $shape = detect_shape($u);
        if ($shape === '' || $shape === 'W') continue;         // главная и разделы
        $key = $host . (string)parse_url($u, PHP_URL_PATH);
        $groups[$shape]['links'][$key] = ['node' => $a, 'url' => $u, 'title' => $title];
    }
    $best = null;
    $bestScore = 0;
    foreach ($groups as $shape => $g) {
        $n = count($g['links']);
        if ($n < 6) continue;
        $avg = array_sum(array_map(fn($l) => mb_strlen($l['title']), $g['links'])) / $n;
        $score = $n * min($avg, 80) * (str_contains($shape, 'N') ? 1.4 : 1.0);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [$shape, $g['links']];
        }
    }
    if (!$best) return null;
    [$shape, $links] = $best;

    // контейнер: самый глубокий предок, внутри которого лежит не меньше 75% найденных ссылок
    $nodes = array_values(array_map(fn($l) => $l['node'], $links));
    $need = (int)ceil(0.75 * count($nodes));
    $container = '';
    for ($e = $nodes[(int)(count($nodes) / 2)]->parentNode; $e instanceof DOMElement; $e = $e->parentNode) {
        if (in_array(strtolower($e->nodeName), ['body', 'html'], true)) break;
        $in = 0;
        foreach ($nodes as $nn) if (detect_inside($nn, $e)) $in++;
        if ($in < $need) continue;
        $sel = detect_selector($e);
        if (strpos($sel, '.') !== false || strpos($sel, '#') !== false) { $container = $sel; break; }
    }
    $rule = ['container' => $container, 'pattern' => detect_shape_regex($shape)];
    $items = listing_extract($html, $pageUrl, $rule);
    if (count($items) < 6) {
        $rule['container'] = '';
        $items = listing_extract($html, $pageUrl, $rule);
    }
    return count($items) >= 6 ? ['rule' => $rule, 'items' => $items] : null;
}

function detect_candidate_items_feed(array $parsed, int $limit = 12): array
{
    usort($parsed, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $out = [];
    foreach (array_slice($parsed, 0, $limit) as $i) {
        $out[] = ['title' => $i['title'], 'url' => $i['url'], 'date' => fmt_dt(gmdate('Y-m-d H:i:s', $i['ts'])), 'chars' => mb_strlen($i['content'])];
    }
    return $out;
}

// Прочитать sitemap (индекс -> самая свежая карта). Возвращает [entries, note] или null
function detect_read_sitemap(array &$src, string $url, string $body): ?array
{
    [$kind, $entries] = sm_parse($body);
    if (!$kind) return null;
    $note = '';
    if ($kind === 'index') {
        usort($entries, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
        $note = count($entries) . ' sitemaps in the index; reading the newest: ' . ($entries[0]['loc'] ?? '');
        if (empty($entries[0]['loc'])) return null;
        [$b2, $f2] = detect_fetch($src, $entries[0]['loc']);
        if ($f2) return null;
        [$k2, $e2] = sm_parse($b2);
        if ($k2 !== 'urlset') return null;
        $entries = $e2;
    }
    usort($entries, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
    return [$entries, $note];
}

// Главная функция: возвращает ['candidates' => [...], 'notes' => [...], 'via' => 'direct'|'browser']
function detect_run(string $input, string $method, string $profile, string $tz): array
{
    $t0 = microtime(true);
    $left = fn() => 80 - (microtime(true) - $t0);
    $src = ['id' => 0, 'type' => 'rss', 'fetch_method' => $method, 'fetch_profile' => $profile, 'feed_timezone' => $tz, 'state' => null,
            'etag' => null, 'last_modified' => null, 'min_published_at' => null, 'articles_count' => 1, 'extra' => '{}'];
    $cands = [];
    $notes = [];

    // Telegram
    if (tg_username($input) && (preg_match('#t\.me/#i', $input) || $input[0] === '@')) {
        $u = 'https://t.me/s/' . tg_username($input);
        $src['type'] = 'telegram';
        [$body, $f] = detect_fetch($src, $u);
        if ($f) return ['candidates' => [], 'notes' => [$f[1]], 'via' => !empty($src['state']) ? 'browser' : 'direct', 'error' => $f[1], 'event' => $f[0]];
        [$items] = tg_parse_page($body, $u);
        if (!$items) return ['candidates' => [], 'notes' => ['No posts found. The channel must be public with web preview enabled.'], 'error' => 'No posts found. The channel must be public with web preview enabled: https://t.me/s/' . tg_username($input) . ' must show posts in a browser.'];
        usort($items, fn($a, $b) => $b['ts'] <=> $a['ts']);
        return ['candidates' => [['kind' => 'telegram', 'label' => 'Telegram channel @' . tg_username($input), 'url' => $u, 'count' => count($items), 'items' => detect_candidate_items_feed($items), 'config' => [], 'recommended' => true]], 'notes' => [], 'via' => 'direct'];
    }
    if (preg_match('#(facebook|fb)\.com#i', $input)) {
        return ['candidates' => [], 'notes' => [], 'error' => 'Facebook cannot be read directly. Create an RSS feed for the page in rss.app and paste that address (it will be detected as a feed).'];
    }

    $url = preg_match('#^https?://#i', $input) ? $input : 'https://' . $input;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return ['candidates' => [], 'notes' => [], 'error' => 'Enter a valid address, for example news.am'];
    $p = parse_url($url);
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

    [$body, $f, $http, $ctype, $viaB, $ms] = detect_fetch($src, $url);
    if ($f) {
        return ['candidates' => [], 'notes' => [], 'error' => $f[1], 'event' => $f[0], 'can_try_browser' => $method !== 'browser' && crawl_is_block($f, 0), 'via' => $viaB ? 'browser' : 'direct'];
    }
    $isXml = preg_match('/^\s*(<\?xml|<rss|<feed|<rdf|<urlset|<sitemapindex)/i', $body) === 1;
    $feedItems = null;
    $smEntries = null;

    if ($isXml) {
        [$parsed, $perr] = crawl_parse_feed($body, $tz);
        if ($parsed) {
            $cands[] = ['kind' => 'rss', 'label' => 'RSS feed', 'url' => $url, 'count' => count($parsed), 'items' => detect_candidate_items_feed($parsed), 'config' => [], 'avgchars' => (int)(array_sum(array_map(fn($i) => mb_strlen($i['content']), $parsed)) / max(1, count($parsed)))];
        }
        if ($sm = detect_read_sitemap($src, $url, $body)) {
            $cands[] = ['kind' => 'sitemap', 'label' => 'Sitemap', 'url' => $url, 'count' => count($sm[0]), 'items' => array_map(fn($e) => ['title' => '', 'url' => $e['loc'], 'date' => $e['ts'] ? fmt_dt(gmdate('Y-m-d H:i:s', $e['ts'])) : '', 'chars' => null], array_slice($sm[0], 0, 8)), 'config' => [], 'note' => $sm[1]];
        }
    } else {
        // 1) список ссылок на самой странице
        if ($l = detect_listing($body, $url)) {
            $items = array_map(fn($i) => ['title' => $i['title'], 'url' => $i['url'], 'date' => $i['time'], 'chars' => null], array_slice($l['items'], 0, 12));
            $cands[] = ['kind' => 'listing', 'label' => 'Page list', 'url' => $url, 'count' => count($l['items']), 'items' => $items, 'config' => ['list_container' => $l['rule']['container'], 'list_pattern' => $l['rule']['pattern']],
                        'note' => 'Links found on this page' . ($l['rule']['container'] ? ' inside ' . $l['rule']['container'] : '') . '. Each article page is opened for the full text.'];
        }
        // 2) RSS: ссылки в коде страницы, затем типичные адреса
        $tryFeeds = [];
        if (preg_match_all('#<link[^>]+type=["\']application/(?:rss|atom)\+xml["\'][^>]*>#i', $body, $m)) {
            foreach ($m[0] as $tag) if (preg_match('#href=["\']([^"\']+)#i', $tag, $h)) $tryFeeds[] = detect_abs($h[1], $url);
        }
        foreach (['/rss', '/rss.xml', '/feed', '/feed/', '/atom.xml'] as $path) $tryFeeds[] = $origin . $path;
        $seenF = [];
        foreach ($tryFeeds as $fu) {
            if ($fu === '' || isset($seenF[$fu]) || $left() < 25 || count($seenF) >= 6) continue;
            $seenF[$fu] = 1;
            [$b2, $f2] = detect_fetch($src, $fu);
            if ($f2) continue;
            [$parsed] = crawl_parse_feed($b2, $tz);
            if ($parsed) {
                $cands[] = ['kind' => 'rss', 'label' => 'RSS feed', 'url' => $fu, 'count' => count($parsed), 'items' => detect_candidate_items_feed($parsed), 'config' => [], 'avgchars' => (int)(array_sum(array_map(fn($i) => mb_strlen($i['content']), $parsed)) / max(1, count($parsed)))];
                break;
            }
        }
        // 3) sitemap: robots.txt, затем типичные адреса
        $tryMaps = [];
        [$rb, $rf] = detect_fetch($src, $origin . '/robots.txt');
        if (!$rf && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $rb, $m)) {
            foreach ($m[1] as $mu) $tryMaps[] = $mu;
            $notes[] = 'robots.txt lists ' . count($m[1]) . ' sitemap(s).';
        } else {
            $notes[] = $rf ? 'robots.txt is not available (' . $rf[1] . ').' : 'robots.txt has no sitemap lines.';
        }
        foreach (['/sitemap_index.xml', '/sitemap.xml', '/news-sitemap.xml', '/sitemap-news.xml'] as $path) $tryMaps[] = $origin . $path;
        $seenM = [];
        foreach ($tryMaps as $mu) {
            if (isset($seenM[$mu]) || $left() < 20 || count($seenM) >= 4) continue;
            $seenM[$mu] = 1;
            [$b3, $f3] = detect_fetch($src, $mu);
            if ($f3) continue;
            if ($sm = detect_read_sitemap($src, $mu, $b3)) {
                $items = [];
                foreach (array_slice($sm[0], 0, 8) as $i => $e) {
                    $row = ['title' => '', 'url' => $e['loc'], 'date' => $e['ts'] ? fmt_dt(gmdate('Y-m-d H:i:s', $e['ts'])) : '', 'chars' => null];
                    if ($i < 2 && $left() > 12) {           // у первых двух открываем страницу: показать, что заголовок и текст извлекаются
                        [$b4, $f4, , $ct4] = detect_fetch($src, $e['loc'], 'page');
                        if (!$f4) {
                            $ex = ft_extract(ft_decode($b4, $ct4), ['tz' => $tz]);
                            $row['title'] = $ex['title'];
                            $row['chars'] = mb_strlen($ex['text']);
                        } else {
                            $row['title'] = '(page not available: ' . $f4[1] . ')';
                        }
                    }
                    $items[] = $row;
                }
                $cands[] = ['kind' => 'sitemap', 'label' => 'Sitemap', 'url' => $mu, 'count' => count($sm[0]), 'items' => $items, 'config' => [], 'note' => $sm[1]];
                break;
            }
        }
    }

    // рекомендация: RSS с живым текстом, затем RSS, затем sitemap, затем список
    $rank = ['rss' => 3, 'sitemap' => 2, 'listing' => 1, 'telegram' => 4];
    usort($cands, fn($a, $b) => ($rank[$b['kind']] <=> $rank[$a['kind']]) ?: ($b['count'] <=> $a['count']));
    if ($cands) $cands[0]['recommended'] = true;
    $res = ['candidates' => $cands, 'notes' => $notes, 'via' => !empty($src['state']) ? 'browser' : 'direct', 'ms' => (int)((microtime(true) - $t0) * 1000)];
    if (!$cands) {
        $res['error'] = 'Nothing usable was found at this address: no feed, no sitemap and no list of article links.';
    }
    return $res;
}
