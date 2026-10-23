<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('articles.view');

$srcId = (int)($_GET['source'] ?? 0);
$catId = (int)($_GET['category'] ?? 0);
$heading = t('All articles');
$back = '';
$where = '1=1';
$args = [];
$types = '';

if ($srcId) {
    $r = cdb()->query("SELECT name FROM sources WHERE id = $srcId");
    $heading = ($r && ($x = $r->fetch_row())) ? $x[0] : t('Source not found.');
    $where = 'a.source_id = ' . $srcId;
    $back = '<a href="/sources.php" class="' . ui('link') . '">← ' . e(t('Sources')) . '</a>';
} elseif ($catId) {
    $r = cdb()->query("SELECT name FROM categories WHERE id = $catId");
    $heading = ($r && ($x = $r->fetch_row())) ? $x[0] : t('Category not found.');
    $where = 's.category_id = ' . $catId;
    $back = '<a href="/categories.php" class="' . ui('link') . '">← ' . e(t('Categories')) . '</a>';
}

$unfinished = ($_GET['status'] ?? '') === 'unfinished';
if ($unfinished) $where .= " AND a.content_status <> 'full'";
$st = sort_state(['date' => 'a.published_at', 'title' => 'a.title', 'source' => 's.name'], 'date', 'desc');
$page = page_no();
$per = 50;
$rows = [];
$r = cdb()->query("SELECT a.id, a.title, a.published_at, a.source_id, a.content_status, s.name AS source_name
    FROM articles a JOIN sources s ON s.id = a.source_id WHERE $where
    ORDER BY {$st['sql']}, a.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per));
while ($r && ($x = $r->fetch_assoc())) {
    $rows[] = $x;
}
$hasNext = count($rows) > $per;
$rows = array_slice($rows, 0, $per);

app_start($heading, $catId ? 'categories' : 'sources', $user);
if ($back) echo '<div class="mb-2">' . $back . '</div>';
page_title($heading, '<a class="' . ui($unfinished ? 'btn' : 'btn2') . '" href="' . e(qs(['status' => $unfinished ? null : 'unfinished', 'page' => null])) . '">' . e(t($unfinished ? 'Showing unfinished only' : 'Show unfinished only')) . '</a>');
render_articles($rows, $st, $hasNext, $page);
app_end();
