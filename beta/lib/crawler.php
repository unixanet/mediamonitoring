<?php
// Ядро краулера. Используется демоном (crawler/run.php) и админкой (Fetch now / Start / добавление источника).
require_once __DIR__ . '/fulltext.php';

const CRAWL_UA_BOT = 'Mozilla/5.0 (compatible; MediaMonitoringBot/1.0; +https://beta.mediamonitoring.am)';
const CRAWL_UA_BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const CRAWL_MAX_BODY = 10485760;

function crawler_connect(array $c): mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli($c['host'], $c['user'], $c['pass'], $c['name']);
    if ($db->connect_errno) {
        throw new RuntimeException('DB connect: ' . $db->connect_error);
    }
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '+00:00'");
    return $db;
}

function crawl_clean(string $html): string
{
    $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html);
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
}

function crawl_norm_url(string $u): string
{
    $u = trim($u);
    $p = $u === '' ? false : parse_url($u);
    if (!$p || empty($p['host'])) {
        return mb_strtolower($u);
    }
    $q = [];
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        foreach (array_keys($q) as $k) {
            if (preg_match('/^(utm_|fbclid$|gclid$|yclid$|mc_|ref$|_ga)/i', (string)$k)) {
                unset($q[$k]);
            }
        }
        ksort($q);
    }
    $host = preg_replace('/^www\./', '', strtolower($p['host']));
    $path = rtrim($p['path'] ?? '/', '/');
    return $host . ($path === '' ? '/' : $path) . ($q ? '?' . http_build_query($q) : '');
}

function crawl_norm_text(string $s): string
{
    return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $s));
}

// Разбор RSS 2.0 / RSS 1.0 / Atom. Возвращает [items|null, error|null]
function crawl_parse_feed(string $body): array
{
    $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$xml || !in_array($xml->getName(), ['rss', 'feed', 'RDF'], true)) {
        return [null, 'Response is not a valid RSS/Atom feed'];
    }
    $nodes = isset($xml->channel->item) ? $xml->channel->item : (isset($xml->entry) ? $xml->entry : (isset($xml->item) ? $xml->item : []));

    $items = [];
    foreach ($nodes as $it) {
        $link = trim((string)$it->link);
        if ($link === '' && isset($it->link)) {
            foreach ($it->link as $l) {
                $rel = (string)$l['rel'];
                if ($rel === '' || $rel === 'alternate') {
                    $link = trim((string)$l['href']);
                    break;
                }
            }
        }
        $guid = trim((string)$it->guid) ?: trim((string)$it->id) ?: $link;
        $dc = $it->children('http://purl.org/dc/elements/1.1/');
        $d = (string)$it->pubDate ?: (string)$it->published ?: (string)$it->updated ?: (string)$dc->date;
        $ts = $d !== '' ? strtotime($d) : false;
        if (!$ts || $ts > time() + 86400) {
            $ts = time();
        }
        $ce = $it->children('http://purl.org/rss/1.0/modules/content/');
        $html = (string)$ce->encoded;
        foreach (['description', 'content', 'summary'] as $k) {
            if ($html === '') {
                $html = (string)$it->$k;
            }
        }

        $img = '';
        if (isset($it->enclosure)) {
            foreach ($it->enclosure as $en) {
                $u = (string)$en['url'];
                if ($u !== '' && (str_starts_with((string)$en['type'], 'image/') || preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $u))) {
                    $img = $u;
                    break;
                }
            }
        }
        if ($img === '') {
            $media = $it->children('http://search.yahoo.com/mrss/');
            foreach (['content', 'thumbnail'] as $k) {
                if ($img === '' && isset($media->$k)) {
                    $img = (string)$media->$k->attributes()->url;
                }
            }
        }
        if ($img === '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            $img = html_entity_decode($m[1]);
        }

        $items[] = [
            'title' => crawl_clean((string)$it->title),
            'url' => $link,
            'guid' => $guid,
            'ts' => $ts,
            'content' => crawl_clean($html),
            'image' => $img,
            'raw' => (string)$it->asXML(),
        ];
    }
    return [$items, null];
}

function crawl_dedup_key(string $mode, array $i): string
{
    switch ($mode) {
        case 'guid':
            return $i['guid'] !== '' ? $i['guid'] : crawl_norm_url($i['url']);
        case 'title':
            return crawl_norm_text($i['title']);
        case 'title_date':
            return crawl_norm_text($i['title']) . '|' . gmdate('Y-m-d', $i['ts']);
        case 'content':
            return crawl_norm_text($i['content']);
        default:
            return crawl_norm_url($i['url'] !== '' ? $i['url'] : $i['guid']);
    }
}

function crawl_log(mysqli $db, int $sid, string $level, string $event, ?int $http, int $ms, int $new, string $msg, string $details = ''): void
{
    $msg = mb_substr($msg, 0, 500);
    $details = mb_substr($details, 0, 4000);
    $q = $db->prepare('INSERT INTO crawl_log (source_id, level, event, http_code, duration_ms, new_articles, message, details) VALUES (?,?,?,?,?,?,?,?)');
    $q->bind_param('issiiiss', $sid, $level, $event, $http, $ms, $new, $msg, $details);
    $q->execute();
    $q->close();
}

function crawl_make_handle(array $src, bool $force, stdClass $st, ?string $url = null, string $kind = 'feed')
{
    $url = $url ?? $src['feed_url'];
    $browser = ($src['fetch_profile'] ?? 'browser') === 'browser';
    $accept = $kind === 'page'
        ? 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'
        : 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, text/html;q=0.8, */*;q=0.7';
    $h = ['Accept: ' . $accept, 'Accept-Language: hy-AM,hy;q=0.9,en-US;q=0.8,en;q=0.7'];
    if ($browser) {
        array_push($h,
            'Cache-Control: max-age=0',
            'sec-ch-ua: "Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1');
    }
    if (!$force) {
        if (!empty($src['etag'])) $h[] = 'If-None-Match: ' . $src['etag'];
        if (!empty($src['last_modified'])) $h[] = 'If-Modified-Since: ' . $src['last_modified'];
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => $browser ? CRAWL_UA_BROWSER : CRAWL_UA_BOT,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use ($st) {
            if (stripos($line, 'HTTP/') === 0) {
                $st->etag = $st->lastmod = null;
            } elseif (stripos($line, 'etag:') === 0) {
                $st->etag = trim(substr($line, 5));
            } elseif (stripos($line, 'last-modified:') === 0) {
                $st->lastmod = trim(substr($line, 14));
            } elseif (stripos($line, 'cf-mitigated:') === 0) {
                $st->cf = true;
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $d) use ($st) {
            $st->body .= $d;
            return strlen($st->body) > CRAWL_MAX_BODY ? 0 : strlen($d);
        },
    ];
    if ($browser) {
        $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        $jar = sys_get_temp_dir() . '/mm_cj_' . md5((string)parse_url($url, PHP_URL_HOST)) . '.txt';
        $opts[CURLOPT_COOKIEFILE] = $jar;
        $opts[CURLOPT_COOKIEJAR] = $jar;
    }
    curl_setopt_array($ch, $opts);
    return $ch;
}

// Классификация неудачи запроса. null = успех (или 304). Иначе [event, message, details]
function crawl_failure(array $src, string $url, $ch, stdClass $st, int $errno): ?array
{
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $details = 'URL: ' . $url . "\nFinal URL: " . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)
        . "\nHTTP: " . $http . "\nContent-Type: " . curl_getinfo($ch, CURLINFO_CONTENT_TYPE) . "\nDuration: " . $ms . ' ms';
    if ($errno) {
        $event = $errno === 28 ? 'timeout' : (in_array($errno, [6, 7, 52, 56], true) ? 'connection' : (in_array($errno, [35, 51, 58, 59, 60, 77], true) ? 'ssl' : 'curl_error'));
        $msg = ($event === 'timeout' ? 'Timeout: the site did not answer in 20 s. ' : '') . curl_error($ch);
        return [$event, trim($msg), $details];
    }
    if ($http === 304 || ($http > 0 && $http < 400)) {
        return null;
    }
    $snippet = mb_substr(crawl_clean($st->body), 0, 300);
    $details .= "\n\nResponse start:\n" . $snippet;
    if (in_array($http, [403, 429, 503], true) && ($st->cf || stripos($st->body, 'challenges.cloudflare.com') !== false || stripos($snippet, 'Just a moment') !== false)) {
        return ['cloudflare_block', 'HTTP ' . $http . ': blocked by a Cloudflare challenge. Request headers cannot pass it. Ask the site to allowlist the server IP, or use an RSS proxy service (e.g. rss.app) as the feed URL.', $details];
    }
    $hint = [403 => ' (blocked by the site)', 429 => ' (too many requests)', 404 => ' (not found)', 503 => ' (site unavailable)'][$http] ?? '';
    return ['http_error', 'HTTP ' . $http . $hint, $details];
}

function crawl_fail(mysqli $db, array $src, array $res, string $event, string $msg, string $details): array
{
    $errors = (int)$src['consecutive_errors'] + 1;
    $interval = (int)$src['check_interval_sec'];
    $delay = min(1800, $interval * (2 ** min($errors, 5)));
    $q = $db->prepare('UPDATE sources SET last_checked_at=NOW(), last_http_code=?, last_response_ms=?, last_error=?,
        consecutive_errors=consecutive_errors+1, next_check_at = NOW() + INTERVAL ? SECOND WHERE id=?');
    $http = $res['http'] ?: null;
    $short = mb_substr($msg, 0, 500);
    $q->bind_param('iisii', $http, $res['ms'], $short, $delay, $src['id']);
    $q->execute();
    $q->close();
    crawl_log($db, (int)$src['id'], 'error', $event, $http, $res['ms'], 0, $msg, $details);
    $res['error'] = $msg;
    return $res;
}

// Обработка завершённого запроса RSS: разбор, дедуп, вставка, обновление состояния и журнала
function crawl_process(mysqli $db, array $src, $ch, stdClass $st, int $errno): array
{
    $sid = (int)$src['id'];
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $res = ['ok' => false, 'http' => $http, 'new' => 0, 'feed' => 0, 'old' => 0, 'dup' => 0, 'error' => '', 'ms' => $ms];

    if ($f = crawl_failure($src, $src['feed_url'], $ch, $st, $errno)) {
        return crawl_fail($db, $src, $res, $f[0], $f[1], $f[2]);
    }
    $details = 'URL: ' . $src['feed_url'] . "\nHTTP: " . $http . "\nDuration: " . $ms . ' ms';
    if ($http === 304) {
        $res['ok'] = true;
        $res['not_modified'] = true;
    }

    $new = 0;
    $unfinished = 0;
    $warn = '';
    if (empty($res['not_modified'])) {
        [$items, $perr] = crawl_parse_feed($st->body);
        if ($items === null) {
            $details .= "\n\nResponse start:\n" . mb_substr(crawl_clean($st->body), 0, 300);
            return crawl_fail($db, $src, $res, 'parse_error', $perr, $details);
        }
        $res['feed'] = count($items);
        $minTs = $src['min_published_at'] ? strtotime($src['min_published_at'] . ' UTC') : null;
        $photo = $src['download_mode'] === 'text_photo';

        $ins = $db->prepare('INSERT IGNORE INTO articles (source_id, dedup_hash, url, title, content, image_url, published_at, content_hash, content_status, next_retry_at)
            VALUES (?, UNHEX(?), ?, ?, ?, ?, ?, UNHEX(?), ?, ?)');
        $raw = $db->prepare('INSERT IGNORE INTO article_raw (article_id, raw) VALUES (?, ?)');
        $db->begin_transaction();
        foreach ($items as $i) {
            $key = crawl_dedup_key($src['dedup_by'], $i);
            if ($key === '' || $i['title'] === '') {
                continue;
            }
            if ($minTs !== null && $i['ts'] < $minTs) {
                $res['old']++;
                continue;
            }
            $dh = md5($key);
            $ch2 = md5($i['title'] . "\n" . $i['content']);
            $url = mb_substr($i['url'], 0, 2048);
            $title = mb_substr($i['title'], 0, 1000);
            $content = mb_substr($i['content'], 0, 500000);
            $image = $photo && $i['image'] !== '' ? mb_substr($i['image'], 0, 2048) : null;
            $pub = gmdate('Y-m-d H:i:s', $i['ts']);
            // что отдала лента, то и сохраняем; страницу статьи открываем только если у источника включена эта опция
            $needFull = $src['fetch_full_text'] && $url !== '';
            $status = $needFull ? 'pending' : 'full';
            $nr = $needFull ? gmdate('Y-m-d H:i:s') : null;
            $ins->bind_param('isssssssss', $sid, $dh, $url, $title, $content, $image, $pub, $ch2, $status, $nr);
            $ins->execute();
            if ($ins->affected_rows === 1) {
                $new++;
                $unfinished += $needFull ? 1 : 0;
                $aid = (int)$db->insert_id;
                $gz = gzcompress($i['raw'], 6);
                $raw->bind_param('is', $aid, $gz);
                $raw->execute();
            } else {
                $res['dup']++;
            }
        }
        $db->commit();
        $ins->close();
        $raw->close();
        if ($res['feed'] === 0) {
            $warn = 'The feed is valid but contains no items.';
        }
    }

    $res['ok'] = true;
    $res['new'] = $new;
    $interval = (int)$src['check_interval_sec'];
    $delay = $interval + random_int(0, max(1, (int)($interval * 0.1)));
    $etag = $st->etag;
    $lm = $st->lastmod;
    $q = $db->prepare('UPDATE sources SET last_checked_at=NOW(), last_success_at=NOW(), last_http_code=?, last_response_ms=?, last_error=NULL,
        consecutive_errors=0, etag=COALESCE(?, etag), last_modified=COALESCE(?, last_modified), next_check_at = NOW() + INTERVAL ? SECOND,
        articles_count = articles_count + ?, unfinished_count = unfinished_count + ?, last_article_at = IF(? > 0, NOW(), last_article_at) WHERE id=?');
    $q->bind_param('iissiiiii', $http, $ms, $etag, $lm, $delay, $new, $unfinished, $new, $sid);
    $q->execute();
    $q->close();

    if ($warn) {
        crawl_log($db, $sid, 'warning', 'empty_feed', $http, $ms, 0, $warn, $details);
    } elseif ($new > 0) {
        crawl_log($db, $sid, 'info', 'fetched', $http, $ms, $new,
            sprintf('%d new articles (feed: %d items, %d already stored, %d older than start date)', $new, $res['feed'], $res['dup'], $res['old']), $details);
    }
    return $res;
}

// ---------------- Sitemap ----------------

function sm_ts(string $v): ?int
{
    $t = $v !== '' ? strtotime($v) : false;
    return $t ?: null;
}

// [type, entries] или [null, error]
function sm_parse(string $body): array
{
    if (strncmp($body, "\x1f\x8b", 2) === 0 && ($g = @gzdecode($body)) !== false) {
        $body = $g;
    }
    $xml = @simplexml_load_string(ltrim($body, "\xEF\xBB\xBF \t\r\n"), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
    if (!$xml) {
        return [null, 'Response is not a valid sitemap'];
    }
    $entries = [];
    if ($xml->getName() === 'sitemapindex') {
        foreach ($xml->sitemap as $s) $entries[] = ['loc' => trim((string)$s->loc), 'ts' => sm_ts((string)$s->lastmod)];
        return ['index', $entries];
    }
    if ($xml->getName() === 'urlset') {
        foreach ($xml->url as $u) $entries[] = ['loc' => trim((string)$u->loc), 'ts' => sm_ts((string)$u->lastmod)];
        return ['urlset', $entries];
    }
    return [null, 'Not a sitemap (root element: ' . $xml->getName() . ')'];
}

function sm_match(string $url, string $list): bool
{
    foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $needle) {
        if (stripos($url, $needle) !== false) return true;
    }
    return false;
}

// Загрузка одной карты. Возвращает [body, fail|null, http, ms, not_modified]
function sm_fetch(array $src, string $url, bool $force, array &$state): array
{
    $key = md5($url);
    $src['etag'] = $state['etag'][$key][0] ?? null;
    $src['last_modified'] = $state['etag'][$key][1] ?? null;
    $st = new stdClass();
    $st->body = '';
    $st->etag = $st->lastmod = null;
    $st->cf = false;
    $ch = crawl_make_handle($src, $force, $st, $url, 'feed');
    curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $fail = crawl_failure($src, $url, $ch, $st, $errno);
    if (!$fail && ($st->etag || $st->lastmod)) {
        $state['etag'][$key] = [$st->etag, $st->lastmod];
    }
    return [$st->body, $fail, $http, $ms, $http === 304];
}

// Разбор одной карты (индекс: самые свежие дочерние; urlset: новые ссылки в очередь). null = ок, иначе [event,msg,details]
function sm_scan(mysqli $db, array $src, array $cfg, string $url, bool $force, array &$ctx, int $depth = 0): ?array
{
    [$body, $fail, $http, $ms, $notMod] = sm_fetch($src, $url, $force, $ctx['state']);
    $ctx['ms'] += $ms;
    $ctx['http'] = $http;
    if ($fail) {
        return $fail;
    }
    if ($notMod) {
        $ctx['maps_ok']++;
        return null;
    }
    [$type, $entries] = sm_parse($body);
    if (!$type) {
        return ['parse_error', $entries, 'URL: ' . $url . "\nResponse start:\n" . mb_substr(crawl_clean($body), 0, 300)];
    }
    $minTs = $ctx['min_ts'];
    if ($type === 'index') {
        if ($depth >= 2) {
            return null;
        }
        usort($entries, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
        $take = 0;
        foreach ($entries as $e) {
            if (!empty($cfg['map_include']) && !sm_match($e['loc'], $cfg['map_include'])) continue;
            if ($minTs && $e['ts'] && $e['ts'] < $minTs) continue;
            if ($take++ >= (int)($cfg['maps_limit'] ?? 2)) break;
            if ($f = sm_scan($db, $src, $cfg, $e['loc'], $force, $ctx, $depth + 1)) {
                return $f;
            }
        }
        return null;
    }

    $ctx['maps_ok']++;
    $fresh = [];
    foreach ($entries as $e) {
        if ($e['loc'] === '') continue;
        if (!empty($cfg['url_include']) && !sm_match($e['loc'], $cfg['url_include'])) continue;
        if (!empty($cfg['url_exclude']) && sm_match($e['loc'], $cfg['url_exclude'])) continue;
        if ($minTs && $e['ts'] && $e['ts'] < $minTs) { $ctx['old']++; continue; }
        if (!$ctx['first'] && $e['ts'] && $e['ts'] < $ctx['max_ts'] - 86400) continue;
        $fresh[] = $e;
        if ($e['ts'] && $e['ts'] > $ctx['new_max']) $ctx['new_max'] = $e['ts'];
    }
    $ctx['feed'] += count($entries);
    if ($ctx['first']) {      // первый запуск: не заливаем архив, только самые свежие
        usort($fresh, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
        $fresh = array_slice($fresh, 0, 300);
    }
    $sid = (int)$src['id'];
    foreach (array_chunk($fresh, 200) as $chunk) {
        $vals = [];
        foreach ($chunk as $e) {
            $vals[] = "($sid, UNHEX('" . md5(crawl_norm_url($e['loc'])) . "'), '" . $db->real_escape_string(mb_substr($e['loc'], 0, 2048)) . "', "
                . ($e['ts'] ? "'" . gmdate('Y-m-d H:i:s', $e['ts']) . "'" : 'NULL') . ', NOW())';
        }
        $db->query('INSERT IGNORE INTO url_queue (source_id, url_hash, url, lastmod, next_try_at) VALUES ' . implode(',', $vals));
        $ctx['queued'] += max(0, $db->affected_rows);
    }
    $ctx['dup'] += count($fresh);
    return null;
}

// Опрос источника типа sitemap: найти новые ссылки и положить в url_queue
function crawl_sitemap_source(mysqli $db, array $src, bool $force): array
{
    $cfg = json_decode($src['extra'] ?? '', true) ?: [];
    $state = json_decode($src['state'] ?? '', true) ?: [];
    $ctx = ['state' => &$state, 'ms' => 0, 'http' => 0, 'maps_ok' => 0, 'queued' => 0, 'feed' => 0, 'old' => 0, 'dup' => 0,
            'first' => empty($state['max_ts']), 'max_ts' => (int)($state['max_ts'] ?? 0), 'new_max' => (int)($state['max_ts'] ?? 0),
            'min_ts' => $src['min_published_at'] ? strtotime($src['min_published_at'] . ' UTC') : null];
    $urls = array_merge([$src['feed_url']], array_values(array_filter((array)($cfg['urls_extra'] ?? []))));
    $failure = null;

    foreach ($urls as $i => $u) {
        $cur = $state['cur'][$i] ?? $u;
        if ($failure = sm_scan($db, $src, $cfg, $cur, $force, $ctx)) {
            break;
        }
        // прямая нумерованная карта: если новых нет, пробуем N+1 (post-sitemap89.xml -> 90)
        if (!empty($cfg['probe_next']) && preg_match('/(\d+)(\.xml(?:\.gz)?)$/i', $cur)
            && ($force || time() - (int)($state['last_probe'][$i] ?? 0) >= 600)) {
            $state['last_probe'][$i] = time();
            for ($hop = 0; $hop < 3; $hop++) {
                $next = preg_replace_callback('/(\d+)(\.xml(?:\.gz)?)$/i', fn($m) => ($m[1] + 1) . $m[2], $cur);
                $before = $ctx['maps_ok'];
                $savedHttp = $ctx['http'];
                if (sm_scan($db, $src, $cfg, $next, true, $ctx) === null && $ctx['maps_ok'] > $before) {
                    $state['cur'][$i] = $cur = $next;
                } else {
                    $ctx['http'] = $savedHttp;
                    break;
                }
            }
        }
    }

    $res = ['ok' => false, 'http' => $ctx['http'], 'new' => 0, 'feed' => $ctx['feed'], 'old' => $ctx['old'], 'dup' => 0,
            'error' => '', 'ms' => $ctx['ms'], 'queued' => $ctx['queued']];
    if ($failure) {
        $db->query("UPDATE sources SET state = '" . $db->real_escape_string(json_encode($state)) . "' WHERE id = " . (int)$src['id']);
        return crawl_fail($db, $src, $res, $failure[0], $failure[1], $failure[2]);
    }
    $state['max_ts'] = max($ctx['max_ts'], $ctx['new_max']);
    $interval = (int)$src['check_interval_sec'];
    $delay = $interval + random_int(0, max(1, (int)($interval * 0.1)));
    $json = json_encode($state);
    $q = $db->prepare('UPDATE sources SET last_checked_at=NOW(), last_success_at=NOW(), last_http_code=?, last_response_ms=?, last_error=NULL,
        consecutive_errors=0, state=?, next_check_at = NOW() + INTERVAL ? SECOND WHERE id=?');
    $q->bind_param('iisii', $res['http'], $res['ms'], $json, $delay, $src['id']);
    $q->execute();
    $q->close();
    $res['ok'] = true;
    if ($ctx['queued'] > 0) {
        crawl_log($db, (int)$src['id'], 'info', 'sitemap_queued', $res['http'], $res['ms'], 0,
            sprintf('%d new article URLs found in sitemap (%d entries checked, %d older than start date)', $ctx['queued'], $ctx['feed'], $ctx['old']));
    }
    return $res;
}

// Один источник синхронно (Fetch now / Start / первый забег). Принудительно, без ETag. Затем сразу тянем полный текст.
function crawl_source_sync(mysqli $db, array $src): array
{
    if (!in_array($src['type'], ['rss', 'sitemap'], true)) {
        return ['ok' => false, 'http' => 0, 'new' => 0, 'feed' => 0, 'old' => 0, 'dup' => 0, 'ms' => 0,
                'error' => 'Only RSS and Sitemap sources are supported by the crawler for now.'];
    }
    set_time_limit(120);
    $db->query('UPDATE sources SET next_check_at = NOW() + INTERVAL 90 SECOND WHERE id = ' . (int)$src['id']);   // занять, чтобы демон не дублировал
    if ($src['type'] === 'sitemap') {
        $res = crawl_sitemap_source($db, $src, true);
    } else {
        $st = new stdClass();
        $st->body = '';
        $st->etag = $st->lastmod = null;
        $st->cf = false;
        $ch = crawl_make_handle($src, true, $st);
        curl_exec($ch);
        $res = crawl_process($db, $src, $ch, $st, curl_errno($ch));
    }
    if ($res['ok'] && $src['fetch_full_text']) {
        $res['ft'] = ft_process_source($db, (int)$src['id'], 20);
    }
    return $res;
}

function crawl_flash(array $r, string $name): array
{
    if (!$r['ok']) {
        return ['err', $name . ': fetch failed. ' . $r['error']];
    }
    if (isset($r['queued'])) {
        $m = sprintf('%s: %d new article URLs found in the sitemap.', $name, $r['queued']);
    } else {
        $m = sprintf('%s: %d new articles. Feed had %d items, %d already stored, %d older than the start date.', $name, $r['new'], $r['feed'], $r['dup'], $r['old']);
    }
    if (!empty($r['ft'])) {
        $f = $r['ft'];
        $m .= sprintf(' Full text: %d complete, %d not yet complete%s.', $f['full'], $f['failed'] + $f['partial'], $f['last_error'] ? ' (' . $f['last_error'] . ')' : '');
    }
    return [!empty($r['ft']) && $r['ft']['failed'] + $r['ft']['partial'] > 0 && $r['ft']['full'] === 0 ? 'err' : 'ok', $m];
}

// Пачка источников параллельно через curl_multi (демон)
function crawl_run_batch(mysqli $db, array $sources, int $par = 15): array
{
    $stats = ['sources' => 0, 'new' => 0, 'errors' => 0];
    $mh = curl_multi_init();
    $active = [];
    $queue = $sources;
    do {
        while ($queue && count($active) < $par) {
            $src = array_shift($queue);
            $st = new stdClass();
            $st->body = '';
            $st->etag = $st->lastmod = null;
            $st->cf = false;
            $ch = crawl_make_handle($src, false, $st);
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = [$src, $st];
        }
        curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $oid = spl_object_id($ch);
            [$src, $st] = $active[$oid];
            unset($active[$oid]);
            curl_multi_remove_handle($mh, $ch);
            $r = crawl_process($db, $src, $ch, $st, (int)$info['result']);
            $stats['sources']++;
            $stats['new'] += $r['new'];
            $stats['errors'] += $r['ok'] ? 0 : 1;
        }
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active || $queue);
    curl_multi_close($mh);
    return $stats;
}

function crawl_purge_logs(mysqli $db, int $days = 30): void
{
    foreach (['crawl_log', 'url_queue'] as $t) {
        do {
            $db->query("DELETE FROM $t WHERE created_at < NOW() - INTERVAL $days DAY" . ($t === 'url_queue' ? " AND status <> 'pending'" : '') . ' LIMIT 5000');
        } while ($db->affected_rows > 0);
    }
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
