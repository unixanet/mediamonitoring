<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('dashboard');

$total = $active = $articles = $cats = 0;
$r = cdb()->query('SELECT COUNT(*), COALESCE(SUM(is_active),0), COALESCE(SUM(articles_count),0) FROM sources');
if ($r) {
    [$total, $active, $articles] = array_map('intval', $r->fetch_row());
}
$r = cdb()->query('SELECT COUNT(*) FROM categories');
$cats = $r ? (int)$r->fetch_row()[0] : 0;

app_start(t('Dashboard'), 'dashboard', $user); ?>
<h1 class="<?= ui('h1') ?>">Welcome, <span class="text-ms"><?= e($user['username']) ?></span></h1>

<div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <?php foreach ([[t('Sources'), $total], [t('Active sources'), $active], [t('Categories'), $cats], [t('Articles collected'), $articles]] as [$l, $n]): ?>
    <div class="<?= ui('card') ?> p-5">
      <div class="text-sm font-semibold"><?= e($l) ?></div>
      <div class="mt-1 text-4xl font-bold text-ms"><?= number_format($n) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<form action="/search.php" method="get" class="mt-6 <?= ui('card') ?> p-5 sm:p-6">
  <label for="hq" class="<?= ui('lbl') ?> !text-base"><?= e(t('Search articles')) ?></label>
  <div class="flex flex-col sm:flex-row gap-3">
    <input id="hq" name="q" placeholder="<?= e(t('Enter a keyword…')) ?>" class="<?= ui('inp') ?>">
    <button class="<?= ui('btn') ?> sm:w-40"><?= e(t('Search')) ?></button>
  </div>
</form>
<?php app_end();
