<?php
// Ядро краулера. Используется демоном (crawler/run.php) и админкой (Fetch now / Start / добавление источника).
const CRAWL_UA = 'Mozilla/5.0 (compatible; MediaMonitoringBot/1.0; +https://beta.mediamonitoring.am)';
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

function crawl_make_handle(array $src, bool $force, stdClass $st)
{
    $h = ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.8', 'Accept-Language: hy,en;q=0.8'];
    if (!$force) {
        if (!empty($src['etag'])) $h[] = 'If-None-Match: ' . $src['etag'];
        if (!empty($src['last_modified'])) $h[] = 'If-Modified-Since: ' . $src['last_modified'];
    }
    $ch = curl_init($src['feed_url']);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => CRAWL_UA,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use ($st) {
            if (stripos($line, 'HTTP/') === 0) {
                $st->etag = $st->lastmod = null;
            } elseif (stripos($line, 'etag:') === 0) {
                $st->etag = trim(substr($line, 5));
            } elseif (stripos($line, 'last-modified:') === 0) {
                $st->lastmod = trim(substr($line, 14));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $d) use ($st) {
            $st->body .= $d;
            return strlen($st->body) > CRAWL_MAX_BODY ? 0 : strlen($d);
        },
    ]);
    return $ch;
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

// Обработка завершённого запроса: разбор, дедуп, вставка, обновление состояния и журнала
function crawl_process(mysqli $db, array $src, $ch, stdClass $st, int $errno): array
{
    $sid = (int)$src['id'];
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $res = ['ok' => false, 'http' => $http, 'new' => 0, 'feed' => 0, 'old' => 0, 'dup' => 0, 'error' => '', 'ms' => $ms];
    $details = 'URL: ' . $src['feed_url'] . "\nFinal URL: " . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)
        . "\nHTTP: " . $http . "\nContent-Type: " . curl_getinfo($ch, CURLINFO_CONTENT_TYPE) . "\nDuration: " . $ms . ' ms';

    if ($errno) {
        $event = $errno === 28 ? 'timeout' : (in_array($errno, [6, 7, 52, 56], true) ? 'connection' : (in_array($errno, [35, 51, 58, 59, 60, 77], true) ? 'ssl' : 'curl_error'));
        $msg = ($event === 'timeout' ? 'Timeout: the site did not answer in 20 s. ' : '') . curl_error($ch);
        return crawl_fail($db, $src, $res, $event, trim($msg), $details);
    }
    if ($http === 304) {
        $res['ok'] = true;
        $res['not_modified'] = true;
    } elseif ($http >= 400 || $http === 0) {
        $hint = [403 => ' (blocked by the site)', 429 => ' (too many requests)', 404 => ' (feed URL not found)', 503 => ' (site unavailable)'][$http] ?? '';
        $details .= "\n\nResponse start:\n" . mb_substr(crawl_clean($st->body), 0, 300);
        return crawl_fail($db, $src, $res, 'http_error', 'HTTP ' . $http . $hint, $details);
    }

    $new = 0;
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

        $ins = $db->prepare('INSERT IGNORE INTO articles (source_id, dedup_hash, url, title, content, image_url, published_at, content_hash)
            VALUES (?, UNHEX(?), ?, ?, ?, ?, ?, UNHEX(?))');
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
            $ins->bind_param('isssssss', $sid, $dh, $url, $title, $content, $image, $pub, $ch2);
            $ins->execute();
            if ($ins->affected_rows === 1) {
                $new++;
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
        articles_count = articles_count + ?, last_article_at = IF(? > 0, NOW(), last_article_at) WHERE id=?');
    $q->bind_param('iissiiii', $http, $ms, $etag, $lm, $delay, $new, $new, $sid);
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

// Один источник синхронно (Fetch now / Start / первый забег). Принудительно, без ETag.
function crawl_source_sync(mysqli $db, array $src): array
{
    if ($src['type'] !== 'rss') {
        return ['ok' => false, 'http' => 0, 'new' => 0, 'feed' => 0, 'old' => 0, 'dup' => 0, 'ms' => 0,
                'error' => 'Only RSS sources are supported by the crawler for now.'];
    }
    set_time_limit(60);
    $db->query('UPDATE sources SET next_check_at = NOW() + INTERVAL 90 SECOND WHERE id = ' . (int)$src['id']);   // занять, чтобы демон не дублировал
    $st = new stdClass();
    $st->body = '';
    $st->etag = $st->lastmod = null;
    $ch = crawl_make_handle($src, true, $st);
    curl_exec($ch);
    $res = crawl_process($db, $src, $ch, $st, curl_errno($ch));
    return $res;
}

function crawl_flash(array $r, string $name): array
{
    if ($r['ok']) {
        return ['ok', sprintf('%s: %d new articles. Feed had %d items, %d already stored, %d older than the start date. (%d ms)',
            $name, $r['new'], $r['feed'], $r['dup'], $r['old'], $r['ms'])];
    }
    return ['err', $name . ': fetch failed. ' . $r['error']];
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
    do {
        $db->query("DELETE FROM crawl_log WHERE created_at < NOW() - INTERVAL $days DAY LIMIT 5000");
    } while ($db->affected_rows > 0);
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
