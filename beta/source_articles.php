<?php
// Подгрузка статей источника для кнопки «Load more» (JSON)
require __DIR__ . '/lib/bootstrap.php';
require_perm('sources.view');
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
$off = max(0, (int)($_GET['offset'] ?? 0));
$per = 20;
$html = '';
$n = 0;
$r = cdb()->query("SELECT id, title, published_at FROM articles WHERE source_id = $id ORDER BY published_at DESC, id DESC LIMIT " . ($per + 1) . " OFFSET $off");
while ($r && ($a = $r->fetch_assoc())) {
    if (++$n <= $per) {
        $html .= source_article_row($a);
    }
}
echo json_encode(['html' => $html, 'more' => $n > $per]);
