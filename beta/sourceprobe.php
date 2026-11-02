<?php
// Определение способа скачивания и живой предпросмотр: JSON для формы источника.
require __DIR__ . '/lib/bootstrap.php';
require_perm('source.add');
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

function probe_out(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (!csrf_ok()) probe_out(['ok' => false, 'error' => 'Session expired, reload the page.']);

$raw = trim((string)($_POST['feed_url'] ?? ''));
if ($raw === '') probe_out(['ok' => false, 'idle' => true]);
$method = in_array($_POST['fetch_method'] ?? '', ['auto', 'http', 'browser'], true) ? $_POST['fetch_method'] : 'auto';
$profile = ($_POST['fetch_profile'] ?? '') === 'bot' ? 'bot' : 'browser';
$tz = (string)($_POST['feed_timezone'] ?? 'Asia/Yerevan');

$r = detect_run($raw, $method, $profile, $tz);
$out = ['ok' => !empty($r['candidates']), 'candidates' => $r['candidates'] ?? [], 'notes' => $r['notes'] ?? [], 'via' => $r['via'] ?? 'direct', 'ms' => $r['ms'] ?? 0];
if (empty($r['candidates'])) {
    $out['error'] = $r['error'] ?? 'Nothing found at this address.';
    $out['event'] = $r['event'] ?? '';
    $out['can_try_browser'] = !empty($r['can_try_browser']);
}
probe_out($out);
