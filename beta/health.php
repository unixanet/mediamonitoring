<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('logs.view');
$m = engine_metrics(cdb());

function stat_card(string $label, string $value, string $sub = '', string $tone = 'ok'): void
{
    $c = $tone === 'bad' ? 'text-red-800' : 'text-ms';
    echo '<div class="' . ui('card') . ' p-5"><div class="text-sm font-semibold">' . e($label) . '</div><div class="mt-1 text-3xl font-bold ' . $c . '">' . e($value)
        . '</div>' . ($sub ? '<div class="mt-1 text-xs font-semibold">' . e($sub) . '</div>' : '') . '</div>';
}
function meter(string $label, float $pct, string $text): void
{
    $bad = $pct > 85;
    echo '<div class="mb-4"><div class="flex justify-between text-sm font-semibold"><span>' . e($label) . '</span><span>' . e($text) . '</span></div>'
        . '<div class="mt-1 h-2.5 rounded-full border border-black bg-white overflow-hidden"><div class="h-full ' . ($bad ? 'bg-red-700' : 'bg-ms') . '" style="width:' . min(100, max(2, round($pct))) . '%"></div></div></div>';
}

app_start(t('Engine'), 'health', $user);
crawler_banner();
page_title(t('Engine health'), '<a class="' . ui('btn2') . '" href="/health.php">↻ ' . e(t('Refresh')) . '</a>'); ?>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
  <?php
  stat_card(t('Articles, last hour'), number_format($m['last_hour']), number_format($m['last_24h']) . ' ' . t('in 24 h'));
  stat_card(t('Sources due now'), (string)$m['due_now'], $m['overdue_sec'] > 0 ? t('most overdue') . ': ' . fmt_span($m['overdue_sec']) : t('everything on schedule'), $m['overdue_sec'] > 300 ? 'bad' : 'ok');
  stat_card(t('Waiting for full text'), number_format($m['pending_articles'] + $m['queue_pending']),
      $m['oldest_pending_sec'] ? t('oldest') . ': ' . fmt_span($m['oldest_pending_sec']) : t('queue is empty'), $m['oldest_pending_sec'] > 1800 ? 'bad' : 'ok');
  stat_card(t('Errors, 24 h'), number_format($m['errors_24h']), number_format($m['incomplete']) . ' ' . t('incomplete articles'), $m['errors_24h'] > 50 ? 'bad' : 'ok');
  ?>
</div>

<div class="grid gap-6 xl:grid-cols-3 mb-6">
  <section class="<?= ui('card') ?> p-5 xl:col-span-2">
    <h2 class="font-bold mb-3"><?= e(t('Articles downloaded per hour (last 24 h)')) ?></h2>
    <?php if (!$m['hours']): ?>
      <div class="text-sm font-semibold"><?= e(t('No data yet.')) ?></div>
    <?php else: $max = max($m['hours']); ?>
      <div class="flex items-end gap-1 h-36">
        <?php foreach ($m['hours'] as $h => $n): ?>
          <div class="flex-1 min-w-0 flex flex-col justify-end h-full" title="<?= e($h . ' UTC: ' . $n) ?>">
            <div class="w-full rounded-t bg-ms border border-black" style="height:<?= max(3, round(100 * $n / $max)) ?>%"></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-1 flex justify-between text-xs font-semibold"><span><?= e(substr((string)array_key_first($m['hours']), 11)) ?> UTC</span><span><?= e(substr((string)array_key_last($m['hours']), 11)) ?> UTC</span></div>
    <?php endif; ?>
  </section>

  <section class="<?= ui('card') ?> p-5">
    <h2 class="font-bold mb-3"><?= e(t('Server')) ?></h2>
    <?php
    meter(t('CPU load'), 100 * ($m['load'] ?? 0) / $m['cpus'], ($m['load'] ?? '?') . ' / ' . $m['cpus'] . ' ' . t('cores'));
    if (isset($m['mem_total_gb'])) meter(t('Memory used'), 100 * (1 - $m['mem_free_gb'] / $m['mem_total_gb']), round($m['mem_total_gb'] - $m['mem_free_gb'], 1) . ' / ' . $m['mem_total_gb'] . ' GB');
    meter(t('Disk used'), 100 * (1 - $m['disk_free_gb'] / max(1, $m['disk_total_gb'])), round($m['disk_total_gb'] - $m['disk_free_gb'], 1) . ' / ' . $m['disk_total_gb'] . ' GB');
    ?>
    <div class="text-sm font-semibold">DB: <?= (int)($m['db']['Threads_running'] ?? 0) ?> <?= e(t('running')) ?> · <?= (int)($m['db']['Threads_connected'] ?? 0) ?> <?= e(t('connected')) ?> · <?= e(t('up')) ?> <?= e(fmt_span((int)($m['db']['Uptime'] ?? 0))) ?></div>
  </section>
</div>

<div class="grid gap-6 xl:grid-cols-2 mb-6">
  <section class="<?= ui('card') ?> overflow-hidden">
    <div class="px-5 py-3 bg-ms-light border-b border-black font-bold"><?= e(t('Processes')) ?></div>
    <?php if (!$m['workers']): ?><div class="p-5 text-sm font-semibold"><?= e(t('No processes have reported yet.')) ?></div><?php endif; ?>
    <div class="divide-y divide-black/10">
      <?php foreach ($m['workers'] as $w): ?>
        <div class="px-5 py-3 flex items-center justify-between gap-3">
          <div><div class="font-bold"><?= $w['role'] === 'poller' ? e(t('Feed poller')) : e(t('Full-text worker')) . ' #' . ((int)$w['id'] - 10) ?></div>
            <div class="text-xs font-semibold">PID <?= (int)$w['pid'] ?> · <?= e(t('since')) ?> <?= e(fmt_dt($w['started_at'])) ?> · <?= number_format((int)$w['jobs_done']) ?> <?= e(t('jobs')) ?><?= $w['last_cycle_ms'] !== null ? ' · ' . (int)$w['last_cycle_ms'] . ' ms ' . e(t('last cycle')) : '' ?></div></div>
          <span class="<?= ui('chip') ?> <?= $w['online'] ? 'bg-ms text-white' : 'bg-red-50 text-red-900 border-red-700' ?>"><?= e(t($w['online'] ? 'Running' : 'Offline')) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="<?= ui('card') ?> overflow-hidden">
    <div class="px-5 py-3 bg-ms-light border-b border-black font-bold"><?= e(t('Queues')) ?></div>
    <dl class="p-5 text-sm">
      <?php foreach ([[t('RSS articles waiting for the page'), $m['pending_articles']], [t('Sitemap URLs waiting'), $m['queue_pending']], [t('Sitemap URLs failed for good'), $m['queue_failed']],
                      [t('Incomplete articles (gave up)'), $m['incomplete']], [t('Change journal for Manticore (30 days)'), $m['changes_total']]] as [$k, $v]): ?>
        <div class="flex justify-between py-1.5 border-b border-black/10 last:border-0"><dt class="font-semibold"><?= e($k) ?></dt><dd class="font-bold"><?= number_format((int)$v) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>
</div>

<div class="grid gap-6 xl:grid-cols-3">
  <section class="<?= ui('card') ?> overflow-hidden xl:col-span-1">
    <div class="px-5 py-3 bg-ms-light border-b border-black font-bold"><?= e(t('Silent sources')) ?></div>
    <?php if (!$m['silent']): ?><div class="p-5 text-sm font-semibold"><?= e(t('None. Every source publishes at its usual pace.')) ?></div><?php endif; ?>
    <div class="divide-y divide-black/10">
      <?php foreach ($m['silent'] as $x): ?>
        <a href="/source.php?id=<?= (int)$x['id'] ?>" class="block px-5 py-3 hover:bg-ms-light/60"><div class="font-bold text-red-900"><?= e($x['name']) ?></div>
          <div class="text-xs font-semibold"><?= e(t('Last article')) ?>: <?= e(ago($x['last_article_at'])) ?></div></a>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="<?= ui('card') ?> overflow-hidden xl:col-span-1">
    <div class="px-5 py-3 bg-ms-light border-b border-black font-bold"><?= e(t('Slowest sources')) ?></div>
    <div class="divide-y divide-black/10">
      <?php foreach ($m['slow'] as $x): ?>
        <a href="/source.php?id=<?= (int)$x['id'] ?>" class="flex justify-between px-5 py-3 hover:bg-ms-light/60"><span class="font-bold"><?= e($x['name']) ?></span><span class="font-semibold"><?= (int)$x['last_response_ms'] ?> ms</span></a>
      <?php endforeach; ?>
      <?php if (!$m['slow']): ?><div class="p-5 text-sm font-semibold"><?= e(t('No data yet.')) ?></div><?php endif; ?>
    </div>
  </section>
  <section class="<?= ui('card') ?> overflow-hidden xl:col-span-1">
    <div class="px-5 py-3 bg-ms-light border-b border-black font-bold"><?= e(t('Largest tables')) ?></div>
    <div class="divide-y divide-black/10">
      <?php foreach ($m['tables'] as $x): ?>
        <div class="flex justify-between px-5 py-2.5 text-sm"><span class="font-bold"><?= e($x['table_name'] ?? $x['TABLE_NAME']) ?></span><span class="font-semibold"><?= number_format((float)($x['mb'] ?? $x['MB'])) ?> MB · ~<?= number_format((int)($x['table_rows'] ?? $x['TABLE_ROWS'])) ?></span></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php app_end();
