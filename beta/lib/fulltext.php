<?php
// Полный текст статьи: загрузка страницы, извлечение заголовка/даты/текста/фото, повторы при неудаче.
const FT_MAX_ATTEMPTS = 5;
const FT_MIN_LEN = 30;    // для статей из sitemap (текста в ленте нет): короче этого страница считается пустой

function ft_delay(int $attempt): int
{
    return [1 => 120, 2 => 600, 3 => 3600, 4 => 21600][$attempt] ?? 86400;
}

function ft_decode(string $html, ?string $contentType): string
{
    $cs = null;
    if ($contentType && preg_match('/charset=([\w-]+)/i', $contentType, $m)) {
        $cs = $m[1];
    } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) {
        $cs = $m[1];
    }
    if ($cs && !preg_match('/^utf-?8$/i', $cs)) {
        $c = @mb_convert_encoding($html, 'UTF-8', $cs);
        if ($c !== false) {
            return $c;
        }
    }
    return $html;
}

// Простой CSS-селектор (tag#id.class потомки через пробел) в XPath
function ft_css_to_xpath(string $css): ?string
{
    $xp = '';
    foreach (preg_split('/\s+/', trim($css)) as $p) {
        if ($p === '' || !preg_match('/^([a-z][a-z0-9]*|\*)?(#[\w-]+)?((?:\.[\w-]+)*)$/i', $p, $m)) {
            return null;
        }
        $cond = [];
        if (!empty($m[2])) $cond[] = "@id='" . substr($m[2], 1) . "'";
        foreach (array_filter(explode('.', $m[3] ?? '')) as $c) {
            $cond[] = "contains(concat(' ',normalize-space(@class),' '),' $c ')";
        }
        $xp .= '//' . ($m[1] ?: '*') . ($cond ? '[' . implode(' and ', $cond) . ']' : '');
    }
    return $xp ?: null;
}

function ft_meta(DOMXPath $x, array $names): string
{
    foreach ($names as $n) {
        $r = $x->query("//meta[@property='$n' or @name='$n']/@content");
        if ($r && $r->length && trim($r->item(0)->nodeValue) !== '') {
            return trim($r->item(0)->nodeValue);
        }
    }
    return '';
}

function ft_jsonld(DOMXPath $x): array
{
    $types = ['NewsArticle', 'Article', 'BlogPosting', 'ReportageNewsArticle', 'AnalysisNewsArticle', 'OpinionNewsArticle', 'LiveBlogPosting'];
    $find = function ($n) use (&$find, $types) {
        if (!is_array($n)) return null;
        if (isset($n['@type']) && array_intersect((array)$n['@type'], $types)) return $n;
        foreach ($n as $v) {
            if (is_array($v) && ($f = $find($v))) return $f;
        }
        return null;
    };
    foreach ($x->query("//script[@type='application/ld+json']") as $s) {
        $j = json_decode(trim($s->nodeValue), true);
        if ($j && ($f = $find($j))) {
            return $f;
        }
    }
    return [];
}

function ft_noise_regex(): string
{
    return '/(?<![a-z])(comments?|share|sharing|social|related|sidebar|adverts?|ads?|promo|banner|menu|subscribe|newsletter|popup|modal|breadcrumbs?|widget|read-more|recommend\w*|tags?)(?![a-z])/i';
}

function ft_pick_node(DOMXPath $x, DOMDocument $d): ?DOMNode
{
    $plen = function (DOMNode $n) use ($x) {
        $l = 0;
        foreach ($x->query('.//p', $n) as $p) $l += mb_strlen(trim($p->textContent));
        return $l;
    };
    $best = null;
    $bestLen = 0;
    foreach ($x->query('//article') as $a) {
        $l = $plen($a);
        if ($l > $bestLen) { $best = $a; $bestLen = $l; }
    }
    if ($bestLen >= 200) {
        return $best;
    }
    $scores = [];
    $nodes = [];
    foreach ($x->query('//p') as $p) {
        $t = trim($p->textContent);
        $len = mb_strlen($t);
        if ($len < 25) continue;
        $ll = 0;
        foreach ($x->query('.//a', $p) as $a) $ll += mb_strlen(trim($a->textContent));
        if ($ll / $len > 0.5) continue;
        $score = $len + 10 * (substr_count($t, ',') + substr_count($t, '։'));
        $par = $p->parentNode;
        if ($par instanceof DOMElement) {
            $id = spl_object_id($par);
            $nodes[$id] = $par;
            $scores[$id] = ($scores[$id] ?? 0) + $score;
            $gp = $par->parentNode;
            if ($gp instanceof DOMElement) {
                $gid = spl_object_id($gp);
                $nodes[$gid] = $gp;
                $scores[$gid] = ($scores[$gid] ?? 0) + $score / 2;
            }
        }
    }
    if ($scores) {
        arsort($scores);
        return $nodes[array_key_first($scores)];
    }
    return $best;
}

function ft_collect(DOMXPath $x, DOMNode $node): string
{
    foreach (iterator_to_array($x->query('.//*[@class or @id]', $node)) as $n) {
        if ($n->parentNode && preg_match(ft_noise_regex(), $n->getAttribute('class') . ' ' . $n->getAttribute('id'))) {
            $n->parentNode->removeChild($n);
        }
    }
    $lines = [];
    $seen = [];
    foreach ($x->query('.//p | .//h2 | .//h3 | .//h4 | .//li[not(ancestor::p)]', $node) as $p) {
        $t = trim(preg_replace('/\s+/u', ' ', $p->textContent));
        if ($t === '' || isset($seen[$t])) continue;
        $ll = 0;
        foreach ($x->query('.//a', $p) as $a) $ll += mb_strlen(trim($a->textContent));
        if ($ll > 0 && $ll / mb_strlen($t) > 0.6 && mb_strlen($t) < 120) continue;
        $seen[$t] = 1;
        $lines[] = $t;
    }
    if (!$lines) {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }
    return implode("\n", $lines);
}

// Возвращает ['title','text','ts','image']
function ft_extract(string $html, array $cfg = []): array
{
    $out = ['title' => '', 'text' => '', 'ts' => null, 'image' => ''];
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    $x = new DOMXPath($d);
    $ld = ft_jsonld($x);

    $custom = function (string $css) use ($x) {
        $xp = $css !== '' ? ft_css_to_xpath($css) : null;
        $r = $xp ? $x->query($xp) : null;
        return $r && $r->length ? $r->item(0) : null;
    };

    // заголовок
    if ($n = $custom((string)($cfg['sel_title'] ?? ''))) {
        $out['title'] = trim(preg_replace('/\s+/u', ' ', $n->textContent));
    }
    if ($out['title'] === '') $out['title'] = trim((string)($ld['headline'] ?? ''));
    if ($out['title'] === '') $out['title'] = ft_meta($x, ['og:title', 'twitter:title']);
    if ($out['title'] === '' && ($h = $x->query('//h1')) && $h->length) $out['title'] = trim(preg_replace('/\s+/u', ' ', $h->item(0)->textContent));
    if ($out['title'] === '' && ($h = $x->query('//title')) && $h->length) $out['title'] = trim($h->item(0)->textContent);
    $out['title'] = trim(html_entity_decode(strip_tags($out['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    // дата публикации
    $d0 = (string)($ld['datePublished'] ?? '') ?: ft_meta($x, ['article:published_time', 'og:published_time', 'datePublished', 'pubdate', 'date']);
    if ($d0 === '' && ($t = $x->query('//time/@datetime')) && $t->length) $d0 = $t->item(0)->nodeValue;
    $ts = $d0 !== '' ? strtotime($d0) : false;
    $out['ts'] = ($ts && $ts <= time() + 86400) ? $ts : null;

    // фото
    $img = ft_meta($x, ['og:image', 'twitter:image']);
    if ($img === '' && isset($ld['image'])) {
        $i = $ld['image'];
        $img = is_array($i) ? (string)($i['url'] ?? ($i[0]['url'] ?? ($i[0] ?? ''))) : (string)$i;
    }
    $out['image'] = $img;

    // текст
    foreach (iterator_to_array($x->query('//script|//style|//noscript|//iframe|//form|//nav|//aside|//footer|//svg|//button|//select|//figure|//template')) as $n) {
        if ($n->parentNode) $n->parentNode->removeChild($n);
    }
    $node = $custom((string)($cfg['sel_body'] ?? '')) ?: ft_pick_node($x, $d);
    $text = $node ? ft_collect($x, $node) : '';
    if (mb_strlen($text) < FT_MIN_LEN && !empty($ld['articleBody'])) {
        $alt = trim(preg_replace('/[ \t]+/u', ' ', html_entity_decode(strip_tags((string)$ld['articleBody']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($alt) > mb_strlen($text)) $text = $alt;
    }
    $out['text'] = $text;
    return $out;
}

function ft_log(mysqli $db, array $src, string $level, string $event, string $msg, string $url, ?int $http = null): void
{
    crawl_log($db, (int)$src['id'], $level, $event, $http, 0, 0, $msg, 'URL: ' . $url);
}

function ft_handle(mysqli $db, array $job, $ch, stdClass $st, int $errno, array &$stats): void
{
    $src = $job['src'];
    $url = $job['url'];
    $sid = (int)$src['id'];
    $kind = $job['kind'];
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $fail = crawl_failure($src, $url, $ch, $st, $errno);
    $ex = null;
    $event = '';
    $reason = '';
    if ($fail) {
        [$event, $reason] = $fail;
    } else {
        $html = ft_decode($st->body, (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $ex = ft_extract($html, json_decode($src['extra'] ?? '', true) ?: []);
        $len = mb_strlen($ex['text']);
        if ($len === 0) {
            $event = 'fulltext_failed';
            $reason = 'No article text found on the page';
        } elseif ($kind === 'queue' && $len < FT_MIN_LEN) {
            $event = 'fulltext_failed';
            $reason = 'Extracted text too short (' . $len . ' chars)';
        }
    }
    if ($reason !== '') {
        $stats['last_error'] = $reason;
    }
    $photo = $src['download_mode'] === 'text_photo';

    if ($kind === 'article') {
        $aid = (int)$job['id'];
        $att = (int)$job['fetch_attempts'] + 1;
        if ($reason === '') {
            // со страницы взяли больше, чем было в ленте, значит заменяем; иначе оставляем то, что отдала лента
            $text = mb_strlen($ex['text']) > mb_strlen(trim((string)$job['content'])) ? $ex['text'] : (string)$job['content'];
            $ch2 = md5($job['title'] . "\n" . $text);
            $img = $photo && $ex['image'] !== '' ? mb_substr($ex['image'], 0, 2048) : null;
            $q = $db->prepare("UPDATE articles SET content=?, content_hash=UNHEX(?), content_status='full', fail_reason=NULL, next_retry_at=NULL,
                fetch_attempts=?, image_url=COALESCE(image_url, ?) WHERE id=?");
            $q->bind_param('ssisi', $text, $ch2, $att, $img, $aid);
            $q->execute();
            $q->close();
            $db->query("UPDATE sources SET unfinished_count = GREATEST(unfinished_count - 1, 0) WHERE id = $sid");
            $stats['full']++;
            return;
        }
        $short = mb_substr($reason, 0, 255);
        if ($att >= FT_MAX_ATTEMPTS) {
            $status = trim((string)$job['content']) === '' ? 'title_only' : 'partial';
            $q = $db->prepare('UPDATE articles SET content_status=?, fetch_attempts=?, next_retry_at=NULL, fail_reason=? WHERE id=?');
            $q->bind_param('sisi', $status, $att, $short, $aid);
            ft_log($db, $src, 'error', 'fulltext_gaveup', 'Gave up after ' . $att . ' attempts, saved as ' . str_replace('_', ' ', $status) . ': ' . $reason, $url, $http ?: null);
            $stats['partial']++;
        } else {
            $delay = ft_delay($att);
            $q = $db->prepare('UPDATE articles SET fetch_attempts=?, next_retry_at = NOW() + INTERVAL ? SECOND, fail_reason=? WHERE id=?');
            $q->bind_param('iisi', $att, $delay, $short, $aid);
            if ($att === 1) {
                ft_log($db, $src, $event === 'cloudflare_block' ? 'error' : 'warning', $event, $reason, $url, $http ?: null);
            }
            $stats['failed']++;
        }
        $q->execute();
        $q->close();
        return;
    }

    // kind = queue: страница из sitemap, статьи в БД ещё нет
    $qid = (int)$job['id'];
    $att = (int)$job['attempts'] + 1;
    if ($ex === null || $ex['title'] === '') {
        $reason = $reason ?: 'No title found on the page';
        $short = mb_substr($reason, 0, 255);
        if ($att >= FT_MAX_ATTEMPTS) {
            $q = $db->prepare("UPDATE url_queue SET status='failed', attempts=?, last_error=? WHERE id=?");
            $q->bind_param('isi', $att, $short, $qid);
            ft_log($db, $src, 'error', 'fulltext_gaveup', 'Gave up after ' . $att . ' attempts, article not saved: ' . $reason, $url, $http ?: null);
        } else {
            $delay = ft_delay($att);
            $q = $db->prepare('UPDATE url_queue SET attempts=?, last_error=?, next_try_at = NOW() + INTERVAL ? SECOND WHERE id=?');
            $q->bind_param('isii', $att, $short, $delay, $qid);
            if ($att === 1) ft_log($db, $src, $event === 'cloudflare_block' ? 'error' : 'warning', $event ?: 'fulltext_failed', $reason, $url, $http ?: null);
        }
        $q->execute();
        $q->close();
        $stats['failed']++;
        return;
    }

    $lm = !empty($job['lastmod']) ? strtotime($job['lastmod'] . ' UTC') : null;
    $ts = $ex['ts'] ?: ($lm ?: time());
    $minTs = $src['min_published_at'] ? strtotime($src['min_published_at'] . ' UTC') : null;
    if ($minTs !== null && $ts < $minTs) {
        $db->query("UPDATE url_queue SET status='skipped', attempts=$att WHERE id=$qid");
        return;
    }
    $item = ['url' => $url, 'guid' => $url, 'title' => $ex['title'], 'ts' => $ts, 'content' => $ex['text']];
    $key = crawl_dedup_key($src['dedup_by'], $item);
    $ok = $reason === '';
    $status = $ok ? 'full' : 'pending';
    $nr = $ok ? null : gmdate('Y-m-d H:i:s', time() + ft_delay(1));
    $fr = $ok ? null : mb_substr($reason, 0, 255);
    $dh = md5($key);
    $ch2 = md5($ex['title'] . "\n" . $ex['text']);
    $u = mb_substr($url, 0, 2048);
    $title = mb_substr($ex['title'], 0, 1000);
    $content = mb_substr($ex['text'], 0, 500000);
    $img = $photo && $ex['image'] !== '' ? mb_substr($ex['image'], 0, 2048) : null;
    $pub = gmdate('Y-m-d H:i:s', $ts);
    $one = 1;
    $q = $db->prepare('INSERT IGNORE INTO articles (source_id, dedup_hash, url, title, content, image_url, published_at, content_hash, content_status, fetch_attempts, next_retry_at, fail_reason)
        VALUES (?, UNHEX(?), ?, ?, ?, ?, ?, UNHEX(?), ?, ?, ?, ?)');
    $att1 = $ok ? 0 : 1;
    $q->bind_param('issssssssiss', $sid, $dh, $u, $title, $content, $img, $pub, $ch2, $status, $att1, $nr, $fr);
    $q->execute();
    $inserted = $q->affected_rows === 1;
    $q->close();
    $db->query("UPDATE url_queue SET status='" . ($inserted ? 'done' : 'skipped') . "', attempts=$att, last_error=NULL WHERE id=$qid");
    if ($inserted) {
        $unf = $ok ? 0 : 1;
        $db->query("UPDATE sources SET articles_count = articles_count + 1, unfinished_count = unfinished_count + $unf, last_article_at = NOW() WHERE id = $sid");
        $stats['inserted']++;
        $ok ? $stats['full']++ : $stats['failed']++;
        if (!$ok) {
            ft_log($db, $src, 'warning', $event, $reason, $url, $http ?: null);
        }
    }
}

// Параллельная загрузка страниц. job: kind=article|queue, id, url, src, [content, title, fetch_attempts | attempts, lastmod]
function ft_run_jobs(mysqli $db, array $jobs, int $par = 8, int $perHost = 2): array
{
    $stats = ['full' => 0, 'failed' => 0, 'partial' => 0, 'inserted' => 0, 'last_error' => ''];
    if (!$jobs) {
        return $stats;
    }
    $mh = curl_multi_init();
    $active = [];
    $hostCnt = [];
    $queue = $jobs;
    do {
        foreach ($queue as $k => $job) {
            if (count($active) >= $par) break;
            $host = (string)parse_url($job['url'], PHP_URL_HOST);
            if (($hostCnt[$host] ?? 0) >= $perHost) continue;
            unset($queue[$k]);
            $st = new stdClass();
            $st->body = '';
            $st->etag = $st->lastmod = null;
            $st->cf = false;
            $ch = crawl_make_handle($job['src'], true, $st, $job['url'], 'page');
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = [$job, $st, $host];
            $hostCnt[$host] = ($hostCnt[$host] ?? 0) + 1;
        }
        curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $oid = spl_object_id($ch);
            [$job, $st, $host] = $active[$oid];
            unset($active[$oid]);
            $hostCnt[$host]--;
            curl_multi_remove_handle($mh, $ch);
            try {
                ft_handle($db, $job, $ch, $st, (int)$info['result'], $stats);
            } catch (Throwable $e) {
                $stats['last_error'] = $e->getMessage();
            }
        }
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active || $queue);
    curl_multi_close($mh);
    return $stats;
}

function ft_sources_map(mysqli $db, array $ids): array
{
    $map = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids) {
        $r = $db->query('SELECT * FROM sources WHERE id IN (' . implode(',', $ids) . ')');
        while ($r && ($x = $r->fetch_assoc())) $map[(int)$x['id']] = $x;
    }
    return $map;
}

// Забрать задачи: $sourceId = только этот источник (ручной запуск, игнорируя расписание); иначе только «созревшие»
function ft_collect_jobs(mysqli $db, int $limit, int $sourceId = 0): array
{
    $rows = [];
    $wa = $sourceId ? "source_id = $sourceId" : 'next_retry_at <= NOW()';
    $r = $db->query("SELECT id, source_id, url, title, content, fetch_attempts FROM articles
        WHERE content_status = 'pending' AND $wa ORDER BY next_retry_at LIMIT $limit");
    while ($r && ($x = $r->fetch_assoc())) $rows[] = ['kind' => 'article'] + $x;
    $wq = $sourceId ? "source_id = $sourceId" : 'next_try_at <= NOW()';
    $r = $db->query("SELECT id, source_id, url, lastmod, attempts FROM url_queue
        WHERE status = 'pending' AND $wq ORDER BY next_try_at LIMIT $limit");
    while ($r && ($x = $r->fetch_assoc())) $rows[] = ['kind' => 'queue'] + $x;

    $map = ft_sources_map($db, array_column($rows, 'source_id'));
    $jobs = [];
    foreach ($rows as $row) {
        $src = $map[(int)$row['source_id']] ?? null;
        if ($src && $src['is_active'] && $src['fetch_full_text']) {
            $row['src'] = $src;
            $jobs[] = $row;
        }
    }
    // занять, чтобы параллельный воркер не взял те же
    foreach ($jobs as $j) {
        if ($j['kind'] === 'article') $db->query('UPDATE articles SET next_retry_at = NOW() + INTERVAL 120 SECOND WHERE id = ' . (int)$j['id']);
        else $db->query('UPDATE url_queue SET next_try_at = NOW() + INTERVAL 120 SECOND WHERE id = ' . (int)$j['id']);
    }
    return $jobs;
}

// Ручной запуск для одного источника (Fetch now / Retry)
function ft_process_source(mysqli $db, int $sourceId, int $limit = 20): array
{
    set_time_limit(120);
    return ft_run_jobs($db, ft_collect_jobs($db, $limit, $sourceId), 8, 3);
}

// Сбросить неудачные статьи источника для повторной попытки
function ft_reset_source(mysqli $db, int $sourceId): int
{
    $db->query("UPDATE articles SET content_status='pending', fetch_attempts=0, next_retry_at=NOW(), fail_reason=NULL
        WHERE source_id = $sourceId AND content_status IN ('partial','title_only') LIMIT 20000");
    $n = $db->affected_rows;
    $db->query("UPDATE url_queue SET status='pending', attempts=0, next_try_at=NOW(), last_error=NULL WHERE source_id = $sourceId AND status='failed'");
    return max(0, $n) + max(0, $db->affected_rows);
}
