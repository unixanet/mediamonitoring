<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_login();

$total = $active = $articles = 0;
$r = cdb()->query('SELECT COUNT(*), COALESCE(SUM(is_active),0), COALESCE(SUM(articles_count),0) FROM sources');
if ($r) {
    [$total, $active, $articles] = array_map('intval', $r->fetch_row());
}

app_start(t('Dashboard'), 'dashboard', $user); ?>
<h1 class="text-3xl sm:text-4xl font-extrabold">Welcome, <span class="text-ms"><?= e($user['username']) ?></span></h1>
<div class="mt-2 h-1.5 w-16 rounded-full bg-accent"></div>

<div class="mt-8 grid gap-4 sm:grid-cols-3">
  <?php foreach ([[t('Sources'), $total, 'bg-ms-light border-ms'], [t('Active'), $active, 'bg-ms-light border-ms'], [t('Articles collected'), $articles, 'bg-accent-light border-accent']] as [$l, $n, $c]): ?>
    <div class="rounded-2xl border-2 <?= $c ?> p-5">
      <div class="font-semibold text-black"><?= e($l) ?></div>
      <div class="mt-1 text-4xl font-extrabold text-black"><?= number_format($n) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php app_end();
