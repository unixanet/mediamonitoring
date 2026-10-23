<?php
// Проверка извлечения текста со страницы статьи (кнопка Preview в форме источника), JSON
require __DIR__ . '/lib/bootstrap.php';
require_perm('source.add');
header('Content-Type: application/json; charset=utf-8');

$out = fn($a) => print(json_encode($a, JSON_UNESCAPED_UNICODE));
$url = trim((string)($_POST['url'] ?? ''));
if (!csrf_ok() || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
    $out(['ok' => false, 'error' => 'Enter a valid article URL']);
    exit;
}
$tz = (string)($_POST['feed_timezone'] ?? 'Asia/Yerevan');
$sel = ['sel_title' => trim((string)($_POST['sel_title'] ?? '')), 'sel_body' => trim((string)($_POST['sel_body'] ?? '')), 'tz' => $tz];
$src = ['fetch_profile' => ($_POST['fetch_profile'] ?? '') === 'bot' ? 'bot' : 'browser', 'feed_url' => $url, 'etag' => null, 'last_modified' => null];
$st = new stdClass();
$st->body = '';
$st->etag = $st->lastmod = null;
$st->cf = false;
set_time_limit(40);
$ch = crawl_make_handle($src, true, $st, $url, 'page');
curl_exec($ch);
if ($f = crawl_failure($src, $url, $ch, $st, curl_errno($ch))) {
    $out(['ok' => false, 'error' => htmlspecialchars($f[1], ENT_QUOTES, 'UTF-8')]);
    exit;
}
$ex = ft_extract(ft_decode($st->body, (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)), $sel);
$text = $ex['text'];
$out(['ok' => $text !== '', 'error' => 'No article text found on the page',
      'title' => htmlspecialchars($ex['title'] ?: '(no title found)', ENT_QUOTES, 'UTF-8'),
      'date' => $ex['ts'] ? fmt_dt(gmdate('Y-m-d H:i:s', $ex['ts'])) : 'date not found (download time is used)',
      'length' => mb_strlen($text),
      'text' => htmlspecialchars(mb_substr($text, 0, 900) . (mb_strlen($text) > 900 ? '…' : ''), ENT_QUOTES, 'UTF-8')]);
