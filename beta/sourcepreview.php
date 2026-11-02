<?php
// Проверка извлечения текста со страницы статьи (кнопка Preview в форме источника), JSON. Делает Python-движок.
require __DIR__ . '/lib/bootstrap.php';
require_perm('source.add');
header('Content-Type: application/json; charset=utf-8');

$url = trim((string)($_POST['url'] ?? ''));
if (!csrf_ok() || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
    echo json_encode(['ok' => false, 'error' => 'Enter a valid article URL']);
    exit;
}
set_time_limit(60);
$r = engine_call('/extract-test', [
    'url' => $url, 'tz' => (string)($_POST['feed_timezone'] ?? 'Asia/Yerevan'),
    'sel_title' => trim((string)($_POST['sel_title'] ?? '')), 'sel_body' => trim((string)($_POST['sel_body'] ?? '')),
    'profile' => ($_POST['fetch_profile'] ?? '') === 'bot' ? 'bot' : 'browser', 'method' => (string)($_POST['fetch_method'] ?? 'auto'),
], 55);
foreach (['error', 'title', 'text', 'date', 'extractor', 'original_url'] as $k) {
    if (isset($r[$k])) $r[$k] = htmlspecialchars((string)$r[$k], ENT_QUOTES, 'UTF-8');
}
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
