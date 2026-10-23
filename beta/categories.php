<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('categories.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $name = trim((string)($_POST['name'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['add']) || isset($_POST['rename'])) {
        if ($name === '' || mb_strlen($name) > 120) {
            flash_set('err', 'Category name is required (up to 120 characters).');
        } else {
            $isAdd = isset($_POST['add']);
            $q = $isAdd ? cdb()->prepare('INSERT INTO categories (name) VALUES (?)') : cdb()->prepare('UPDATE categories SET name=? WHERE id=?');
            $isAdd ? $q->bind_param('s', $name) : $q->bind_param('si', $name, $id);
            if ($q->execute()) {
                flash_set('ok', $isAdd ? 'Category added.' : 'Category renamed.');
            } else {
                flash_set('err', $q->errno === 1062 ? 'This category already exists.' : 'Database error: ' . $q->errno . ' ' . $q->error);
            }
        }
    } elseif (isset($_POST['delete'])) {
        $id = (int)$_POST['delete'];
        cdb()->query("UPDATE sources SET category_id = NULL WHERE category_id = $id");
        cdb()->query("DELETE FROM categories WHERE id = $id");
        flash_set('ok', 'Category deleted. Its sources are now uncategorised.');
    }
    header('Location: /categories.php');
    exit;
}

$st = sort_state(['name' => 'c.name', 'sources' => 'sources', 'articles' => 'articles', 'created' => 'c.created_at'], 'name', 'asc');
$rows = [];
$r = cdb()->query('SELECT c.id, c.name, c.created_at, COUNT(s.id) AS sources, COALESCE(SUM(s.articles_count),0) AS articles
    FROM categories c LEFT JOIN sources s ON s.category_id = c.id GROUP BY c.id, c.name, c.created_at ORDER BY ' . $st['sql'] . ' LIMIT 1000');
while ($r && ($x = $r->fetch_assoc())) {
    $rows[] = $x;
}

app_start(t('Categories'), 'categories', $user);
page_title(t('Categories')); ?>

<form method="post" class="<?= ui('card') ?> p-4 sm:p-5 mb-6 flex flex-col sm:flex-row gap-3 max-w-2xl">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
  <input name="name" required maxlength="120" placeholder="<?= e(t('New category name')) ?>" class="<?= ui('inp') ?>">
  <button name="add" value="1" class="<?= ui('btn') ?> sm:w-44">+ <?= e(t('Add category')) ?></button>
</form>

<?php if (!$rows): ?>
  <div class="<?= ui('card') ?> p-8 text-center font-semibold"><?= e(t('No categories yet.')) ?></div>
<?php else:
  mobile_sort(['name' => 'Name', 'sources' => 'Sources', 'articles' => 'Articles'], $st); ?>
  <div class="<?= ui('card') ?> overflow-hidden">
    <div class="hidden md:grid grid-cols-[1fr_7rem_8rem_11rem_16rem] bg-ms-light border-b border-black">
      <div class="<?= ui('th') ?>"><?= sort_th('Name', 'name', $st) ?></div>
      <div class="<?= ui('th') ?>"><?= sort_th('Sources', 'sources', $st) ?></div>
      <div class="<?= ui('th') ?>"><?= sort_th('Articles', 'articles', $st) ?></div>
      <div class="<?= ui('th') ?>"><?= sort_th('Created', 'created', $st) ?></div>
      <div class="<?= ui('th') ?>"></div>
    </div>
    <div class="divide-y divide-black/10">
    <?php foreach ($rows as $c): ?>
      <div class="md:grid md:grid-cols-[1fr_7rem_8rem_11rem_16rem] md:items-center p-4 md:p-0 hover:bg-ms-light/60">
        <div class="md:px-4 md:py-3 min-w-0">
          <a href="/articles.php?category=<?= (int)$c['id'] ?>" class="font-bold text-ms hover:text-ms-dark hover:underline" id="name-<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a>
          <form method="post" class="hidden" id="rn-<?= (int)$c['id'] ?>"><div class="flex gap-2">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input name="name" value="<?= e($c['name']) ?>" maxlength="120" class="<?= ui('inp') ?> !py-1.5">
            <button name="rename" value="1" class="<?= ui('btn') ?> !py-1.5"><?= e(t('Save')) ?></button>
          </div></form>
        </div>
        <div class="<?= ui('td') ?> font-medium"><span class="md:hidden"><?= e(t('Sources')) ?>: </span><?= (int)$c['sources'] ?></div>
        <div class="<?= ui('td') ?> font-bold"><span class="md:hidden font-medium"><?= e(t('Articles')) ?>: </span><?= number_format((int)$c['articles']) ?></div>
        <div class="<?= ui('td') ?> font-medium"><?= e(fmt_dt($c['created_at'])) ?></div>
        <div class="<?= ui('td') ?> flex flex-wrap gap-2">
          <a href="/articles.php?category=<?= (int)$c['id'] ?>" class="<?= ui('btn2') ?> !px-3 !py-1.5"><?= e(t('Articles')) ?></a>
          <button type="button" onclick="document.getElementById('name-<?= (int)$c['id'] ?>').classList.toggle('hidden');document.getElementById('rn-<?= (int)$c['id'] ?>').classList.toggle('hidden')" class="<?= ui('btn2') ?> !px-3 !py-1.5"><?= e(t('Rename')) ?></button>
          <form method="post" onsubmit="return confirm('<?= e(t('Delete this category? Its sources will become uncategorised.')) ?>')">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <button name="delete" value="<?= (int)$c['id'] ?>" class="<?= ui('btnd') ?> !px-3 !py-1.5"><?= e(t('Delete')) ?></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
<?php endif;
app_end();
