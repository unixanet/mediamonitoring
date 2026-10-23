<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('logs.view');

$levels = ['problems' => 'Problems (errors + warnings)', 'error' => 'Errors', 'warning' => 'Warnings', 'info' => 'New articles', 'all' => 'Everything'];
$periods = [3600 => 'Last hour', 86400 => 'Last 24 hours', 604800 => 'Last 7 days', 2592000 => 'Last 30 days'];
$events = ['http_error' => 'HTTP error', 'timeout' => 'Timeout', 'connection' => 'Connection', 'ssl' => 'SSL', 'parse_error' => 'Invalid feed', 'empty_feed' => 'Empty feed', 'fetched' => 'Fetched'];

$level = isset($levels[$_GET['level'] ?? '']) ? $_GET['level'] : 'problems';
$period = isset($periods[(int)($_GET['period'] ?? 0)]) ? (int)$_GET['period'] : 86400;
$srcId = (int)($_GET['source'] ?? 0);
$event = isset($events[$_GET['event'] ?? '']) ? $_GET['event'] : '';
$open = (int)($_GET['open'] ?? 0);
$st = sort_state(['time' => 'l.id', 'source' => 's.name', 'event' => 'l.event'], 'time', 'desc');
$page = page_no();
$per = 50;

$where = "l.created_at >= NOW() - INTERVAL $period SECOND";
if ($level === 'problems') $where .= " AND l.level IN ('error','warning')";
elseif ($level !== 'all') $where .= " AND l.level = '" . $level . "'";
if ($srcId) $where .= " AND l.source_id = $srcId";
if ($event) $where .= " AND l.event = '" . $event . "'";

// Сводка за 24 часа
$sum = ['error' => 0, 'warning' => 0, 'new' => 0];
$r = cdb()->query("SELECT level, COUNT(*), COALESCE(SUM(new_articles),0) FROM crawl_log WHERE created_at >= NOW() - INTERVAL 1 DAY GROUP BY level");
while ($r && ($x = $r->fetch_row())) {
    if ($x[0] === 'info') $sum['new'] = (int)$x[2]; else $sum[$x[0]] = (int)$x[1];
}
$failing = [];
$r = cdb()->query('SELECT id, name, consecutive_errors, last_error FROM sources WHERE is_active = 1 AND consecutive_errors > 0 ORDER BY consecutive_errors DESC LIMIT 12');
while ($r && ($x = $r->fetch_assoc())) {
    $failing[] = $x;
}
$srcs = [];
$r = cdb()->query('SELECT id, name FROM sources ORDER BY name LIMIT 1000');
while ($r && ($x = $r->fetch_assoc())) {
    $srcs[(int)$x['id']] = $x['name'];
}

$rows = [];
$r = cdb()->query("SELECT l.*, COALESCE(s.name, '(deleted)') AS source_name FROM crawl_log l LEFT JOIN sources s ON s.id = l.source_id
    WHERE $where ORDER BY {$st['sql']}, l.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per));
while ($r && ($x = $r->fetch_assoc())) {
    $rows[] = $x;
}
$hasNext = count($rows) > $per;
$rows = array_slice($rows, 0, $per);

function lvl_cls(string $l): string
{
    return $l === 'error' ? 'border-red-700 bg-red-50 text-red-900' : ($l === 'warning' ? 'border-black bg-white text-black' : 'border-black bg-ms text-white');
}

app_start(t('Logs'), 'logs', $user);
crawler_banner();
page_title(t('Logs')); ?>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
  <?php foreach ([
      [t('Errors, 24 h'), $sum['error'], '/logs.php?level=error&period=86400', $sum['error'] ? 'text-red-800' : 'text-ms'],
      [t('Warnings, 24 h'), $sum['warning'], '/logs.php?level=warning&period=86400', 'text-ms'],
      [t('Sources failing now'), count($failing), '#failing', count($failing) ? 'text-red-800' : 'text-ms'],
      [t('New articles, 24 h'), $sum['new'], '/logs.php?level=info&period=86400', 'text-ms'],
  ] as [$l, $n, $href, $c]): ?>
    <a href="<?= e($href) ?>" class="<?= ui('card') ?> p-5 block hover:-translate-y-0.5 transition">
      <div class="text-sm font-semibold"><?= e($l) ?></div>
      <div class="mt-1 text-4xl font-bold <?= $c ?>"><?= number_format($n) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($failing): ?>
<section id="failing" class="<?= ui('card') ?> p-4 mb-6">
  <h2 class="font-bold mb-3"><?= e(t('Sources failing right now')) ?></h2>
  <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($failing as $f): ?>
      <a href="/logs.php?source=<?= (int)$f['id'] ?>&level=problems&period=2592000" class="block rounded-lg border border-red-700 bg-red-50 px-3 py-2 hover:shadow-md transition">
        <div class="flex justify-between gap-2"><span class="font-bold text-red-900 truncate"><?= e($f['name']) ?></span><span class="text-xs font-bold text-red-900 shrink-0">×<?= (int)$f['consecutive_errors'] ?></span></div>
        <div class="text-sm font-medium break-words"><?= e(mb_strimwidth((string)$f['last_error'], 0, 90, '…')) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<form method="get" class="<?= ui('card') ?> p-4 mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_1fr_auto]">
  <select name="level" class="<?= ui('inp') ?>"><?php foreach ($levels as $v => $l): ?><option value="<?= $v ?>" <?= $level === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select>
  <select name="source" class="<?= ui('inp') ?>"><option value="0"><?= e(t('All sources')) ?></option><?php foreach ($srcs as $sid => $sn): ?><option value="<?= $sid ?>" <?= $srcId === $sid ? 'selected' : '' ?>><?= e($sn) ?></option><?php endforeach; ?></select>
  <select name="event" class="<?= ui('inp') ?>"><option value=""><?= e(t('All event types')) ?></option><?php foreach ($events as $v => $l): ?><option value="<?= $v ?>" <?= $event === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select>
  <select name="period" class="<?= ui('inp') ?>"><?php foreach ($periods as $v => $l): ?><option value="<?= $v ?>" <?= $period === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select>
  <button class="<?= ui('btn') ?>"><?= e(t('Apply')) ?></button>
</form>

<div class="flex flex-wrap items-center gap-2 mb-3">
  <span class="text-sm font-semibold"><?= e(t('Sort')) ?>:</span>
  <?php foreach (['time' => 'Time', 'source' => 'Source', 'event' => 'Event'] as $k => $l): $on = $st['key'] === $k; $nd = $on && $st['dir'] === 'asc' ? 'desc' : 'asc'; ?>
    <a href="<?= e(qs(['sort' => $k, 'dir' => $nd, 'page' => null])) ?>" class="<?= ui('chip') ?> <?= $on ? 'bg-ms text-white' : 'bg-white text-black' ?>"><?= e(t($l)) ?><?= $on ? ($st['dir'] === 'asc' ? ' ▲' : ' ▼') : '' ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <div class="<?= ui('card') ?> p-8 text-center font-semibold"><?= e(t('Nothing here. No entries match these filters.')) ?></div>
<?php else: ?>
  <div class="<?= ui('card') ?> overflow-hidden divide-y divide-black/10">
  <?php foreach ($rows as $l): ?>
    <details class="group" <?= $open === (int)$l['id'] ? 'open' : '' ?>>
      <summary class="cursor-pointer list-none px-4 py-3 hover:bg-ms-light/60 flex flex-col md:flex-row md:items-center gap-2 md:gap-4">
        <div class="flex items-center gap-2 md:w-72 shrink-0">
          <span class="<?= ui('chip') ?> <?= lvl_cls($l['level']) ?> uppercase"><?= e($l['level']) ?></span>
          <span class="text-sm font-semibold" title="<?= e(fmt_dt($l['created_at'])) ?>"><?= e(ago($l['created_at'])) ?></span>
          <span class="<?= ui('chip') ?>"><?= e(str_replace('_', ' ', $l['event'])) ?></span>
        </div>
        <div class="font-bold md:w-48 shrink-0 truncate"><?= e($l['source_name']) ?></div>
        <div class="text-sm font-medium min-w-0 flex-1 break-words"><?= e(mb_strimwidth($l['message'], 0, 160, '…')) ?></div>
        <span class="hidden md:inline text-ms font-bold group-open:rotate-180 transition">⌄</span>
      </summary>
      <div class="px-4 pb-4 pt-1 bg-ms-light/40">
        <div class="grid gap-3 md:grid-cols-[1fr_16rem]">
          <div>
            <div class="text-xs font-bold uppercase mb-1"><?= e(t('Message')) ?></div>
            <div class="font-medium break-words"><?= e($l['message']) ?></div>
            <?php if ($l['details']): ?>
              <div class="text-xs font-bold uppercase mt-3 mb-1"><?= e(t('Details')) ?></div>
              <pre class="whitespace-pre-wrap break-words rounded-lg border border-black bg-white p-3 text-xs font-medium"><?= e($l['details']) ?></pre>
            <?php endif; ?>
          </div>
          <dl class="rounded-lg border border-black bg-white p-3 text-sm self-start">
            <?php foreach ([[t('Time'), fmt_dt($l['created_at'])], ['HTTP', $l['http_code'] ?: '—'], [t('Duration'), $l['duration_ms'] !== null ? $l['duration_ms'] . ' ms' : '—'], [t('New articles'), (string)$l['new_articles']]] as [$k, $v]): ?>
              <div class="flex justify-between gap-3 py-1"><dt class="font-semibold"><?= e($k) ?></dt><dd class="font-bold"><?= e((string)$v) ?></dd></div>
            <?php endforeach; ?>
            <?php if ($l['source_name'] !== '(deleted)'): ?>
            <div class="mt-2 flex flex-col gap-2">
              <a href="/source.php?id=<?= (int)$l['source_id'] ?>" class="<?= ui('btn') ?> !py-1.5"><?= e(t('Open source')) ?></a>
              <a href="/logs.php?source=<?= (int)$l['source_id'] ?>&level=all&period=2592000" class="<?= ui('btn2') ?> !py-1.5"><?= e(t('All entries of this source')) ?></a>
            </div>
            <?php endif; ?>
          </dl>
        </div>
      </div>
    </details>
  <?php endforeach; ?>
  </div>
  <?php pager($page, $hasNext);
endif;
app_end();
