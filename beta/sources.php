<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && isset($_POST['toggle'])) {
    $id = (int)$_POST['toggle'];
    cdb()->query("UPDATE sources SET is_active = 1 - is_active, next_check_at = NOW() WHERE id = $id");
    flash_set('ok', 'Source updated.');
    header('Location: /sources.php');
    exit;
}

$rows = [];
$r = cdb()->query('SELECT id, name, type, feed_url, download_mode, is_active, check_interval_sec,
    articles_count, last_article_at, last_checked_at, last_http_code, last_error, consecutive_errors
    FROM sources ORDER BY name LIMIT 1000');
while ($r && ($x = $r->fetch_assoc())) {
    $rows[] = $x;
}

app_start(t('Sources'), 'sources', $user); ?>
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div>
    <h1 class="text-3xl sm:text-4xl font-extrabold"><?= e(t('Sources')) ?></h1>
    <div class="mt-2 h-1.5 w-16 rounded-full bg-accent"></div>
  </div>
  <a href="/source_edit.php" class="inline-flex items-center justify-center gap-2 rounded-xl bg-accent hover:bg-accent-dark text-white font-bold px-5 py-3 transition">
    <span class="text-xl leading-none">+</span> <?= e(t('Add source')) ?>
  </a>
</div>

<?php if (!$rows): ?>
  <div class="mt-8 rounded-2xl border-2 border-ms bg-ms-light p-8 text-center font-semibold"><?= e(t('No sources yet. Add the first one.')) ?></div>
<?php else: ?>
<div class="mt-8 rounded-2xl border-2 border-ms-mid overflow-hidden">
  <?php foreach ($rows as $s):
    $err = $s['consecutive_errors'] > 0;
    if (!$s['is_active']) { $badge = ['Paused', 'bg-accent-light text-black border-accent']; }
    elseif ($err) { $badge = ['Error', 'bg-red-50 text-red-900 border-red-600']; }
    else { $badge = ['Active', 'bg-ms-light text-black border-ms']; }
  ?>
  <div class="p-4 sm:p-5 border-b-2 border-ms-mid last:border-b-0 bg-white hover:bg-ms-light/50 transition">
    <div class="flex flex-col lg:flex-row lg:items-center gap-4">
      <div class="min-w-0 lg:flex-1">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-lg font-extrabold"><?= e($s['name']) ?></span>
          <span class="rounded-full border-2 px-2.5 py-0.5 text-xs font-bold <?= $badge[1] ?>"><?= e(t($badge[0])) ?></span>
          <span class="rounded-full border-2 border-black px-2.5 py-0.5 text-xs font-bold uppercase"><?= e($s['type']) ?></span>
        </div>
        <div class="mt-1 truncate font-medium text-ms-dark"><?= e($s['feed_url']) ?></div>
        <?php if ($err && $s['last_error']): ?>
          <div class="mt-1 text-sm font-semibold text-red-900"><?= e(mb_strimwidth($s['last_error'], 0, 120, '…')) ?></div>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-3 gap-3 lg:w-[26rem] text-center">
        <div class="rounded-xl bg-ms-light px-2 py-2">
          <div class="text-2xl font-extrabold"><?= number_format((int)$s['articles_count']) ?></div>
          <div class="text-xs font-bold"><?= e(t('Articles')) ?></div>
        </div>
        <div class="rounded-xl bg-ms-light px-2 py-2" title="<?= e(fmt_dt($s['last_article_at'])) ?>">
          <div class="text-base font-extrabold leading-8"><?= e(ago($s['last_article_at'])) ?></div>
          <div class="text-xs font-bold"><?= e(t('Last article')) ?></div>
        </div>
        <div class="rounded-xl bg-ms-light px-2 py-2" title="<?= e(fmt_dt($s['last_checked_at'])) ?>">
          <div class="text-base font-extrabold leading-8"><?= e(ago($s['last_checked_at'])) ?></div>
          <div class="text-xs font-bold"><?= e(t('Last check')) ?></div>
        </div>
      </div>

      <div class="flex gap-2 lg:w-44">
        <a href="/source_edit.php?id=<?= (int)$s['id'] ?>" class="flex-1 text-center rounded-xl bg-ms hover:bg-ms-dark text-white font-bold px-4 py-2 transition"><?= e(t('Edit')) ?></a>
        <form method="post" class="flex-1">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <button name="toggle" value="<?= (int)$s['id'] ?>" class="w-full rounded-xl border-2 border-black text-black font-bold px-4 py-2 hover:bg-black hover:text-white transition">
            <?= e(t($s['is_active'] ? 'Pause' : 'Resume')) ?>
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif;
app_end();
