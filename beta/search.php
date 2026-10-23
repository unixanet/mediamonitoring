<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('search');

$q = trim((string)($_GET['q'] ?? ''));
$scope = ($_GET['in'] ?? 'all') === 'title' ? 'title' : 'all';
$catId = (int)($_GET['category'] ?? 0);
$st = sort_state(['date' => 'a.published_at', 'title' => 'a.title', 'source' => 's.name'], 'date', 'desc');
$page = page_no();
$per = 50;
$rows = [];
$hasNext = false;
$tooShort = $q !== '' && mb_strlen($q) < 2;

$cats = [];
$r = cdb()->query('SELECT id, name FROM categories ORDER BY name LIMIT 1000');
while ($r && ($x = $r->fetch_assoc())) {
    $cats[(int)$x['id']] = $x['name'];
}

// Поиск по подстроке (как LIKE '%слово%'). Позже заменим на Manticore, интерфейс останется прежним.
if ($q !== '' && !$tooShort) {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $cond = $scope === 'title' ? 'a.title LIKE ?' : '(a.title LIKE ? OR a.content LIKE ?)';
    $sql = "SELECT a.id, a.title, a.published_at, a.source_id, s.name AS source_name,
            SUBSTRING(a.content, GREATEST(LOCATE(?, a.content) - 80, 1), 240) AS snip
            FROM articles a JOIN sources s ON s.id = a.source_id
            WHERE $cond" . ($catId ? ' AND s.category_id = ' . $catId : '') . "
            ORDER BY {$st['sql']}, a.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per);
    $stm = cdb()->prepare($sql);
    if ($scope === 'title') {
        $stm->bind_param('ss', $q, $like);
    } else {
        $stm->bind_param('sss', $q, $like, $like);
    }
    $stm->execute();
    $res = $stm->get_result();
    while ($x = $res->fetch_assoc()) {
        $rows[] = $x;
    }
    $hasNext = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
}

app_start(t('Search'), 'search', $user);
page_title(t('Search')); ?>
<form method="get" class="<?= ui('card') ?> p-4 sm:p-5 mb-6 grid gap-3 md:grid-cols-[1fr_12rem_12rem_9rem]">
  <input name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Enter a keyword…')) ?>" class="<?= ui('inp') ?>" autofocus>
  <select name="in" class="<?= ui('inp') ?>">
    <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>><?= e(t('Title + text')) ?></option>
    <option value="title" <?= $scope === 'title' ? 'selected' : '' ?>><?= e(t('Title only')) ?></option>
  </select>
  <select name="category" class="<?= ui('inp') ?>">
    <option value="0"><?= e(t('All categories')) ?></option>
    <?php foreach ($cats as $cid => $cn): ?><option value="<?= $cid ?>" <?= $catId === $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?>
  </select>
  <button class="<?= ui('btn') ?>"><?= e(t('Search')) ?></button>
</form>
<?php
if ($q === '') {
    echo '<div class="' . ui('card') . ' p-8 text-center font-semibold">' . e(t('Type a keyword to search all downloaded articles.')) . '</div>';
} elseif ($tooShort) {
    echo '<div class="' . ui('card') . ' p-8 text-center font-semibold">' . e(t('Enter at least 2 characters.')) . '</div>';
} else {
    render_articles($rows, $st, $hasNext, $page, $q);
}
app_end();
