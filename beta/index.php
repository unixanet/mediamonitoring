<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('dashboard');
$db = cdb();

$range = in_array($_GET['range'] ?? '', ['24h', '7d', '30d'], true) ? $_GET['range'] : '24h';
$hours = ['24h' => 24, '7d' => 168, '30d' => 720][$range];
$q1 = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? 0) : 0;

$total = (int)$q1('SELECT COUNT(*) FROM sources');
$active = (int)$q1('SELECT COUNT(*) FROM sources WHERE is_active = 1');
$arts = (int)$q1("SELECT COUNT(*) FROM articles WHERE fetched_at >= NOW() - INTERVAL $hours HOUR");
$prev = (int)$q1("SELECT COUNT(*) FROM articles WHERE fetched_at >= NOW() - INTERVAL " . (2 * $hours) . " HOUR AND fetched_at < NOW() - INTERVAL $hours HOUR");
$full = (int)$q1("SELECT COUNT(*) FROM articles WHERE fetched_at >= NOW() - INTERVAL $hours HOUR AND content_status = 'full'");
$errs = (int)$q1("SELECT COUNT(*) FROM crawl_log WHERE level = 'error' AND created_at >= NOW() - INTERVAL $hours HOUR");
$totalAll = (int)$q1('SELECT COALESCE(SUM(articles_count),0) FROM sources');
$rate = $arts ? round(100 * $full / $arts, 1) : 100.0;
$delta = $prev ? round(100 * ($arts - $prev) / $prev, 1) : null;

// график: по часам (24h) или по дням (7d, 30d)
$series = [];
if ($range === '24h') {
    $r = $db->query("SELECT DATE_FORMAT(fetched_at, '%Y-%m-%d %H:00:00') k, COUNT(*) FROM articles WHERE fetched_at >= NOW() - INTERVAL 24 HOUR GROUP BY k");
    $map = [];
    while ($r && ($x = $r->fetch_row())) $map[$x[0]] = (int)$x[1];
    for ($i = 23; $i >= 0; $i--) { $k = gmdate('Y-m-d H:00:00', time() - $i * 3600); $series[] = [gmdate('H:00', time() - $i * 3600), $map[$k] ?? 0]; }
} else {
    $days = $range === '7d' ? 7 : 30;
    $r = $db->query("SELECT DATE(fetched_at) k, COUNT(*) FROM articles WHERE fetched_at >= NOW() - INTERVAL $days DAY GROUP BY k");
    $map = [];
    while ($r && ($x = $r->fetch_row())) $map[$x[0]] = (int)$x[1];
    for ($i = $days - 1; $i >= 0; $i--) { $k = gmdate('Y-m-d', time() - $i * 86400); $series[] = [gmdate('d M', time() - $i * 86400), $map[$k] ?? 0]; }
}
$max = max(1, max(array_column($series, 1)));
$W = 640; $H = 200; $n = count($series);
$pts = [];
foreach ($series as $i => [$l, $v]) $pts[] = [round($i * $W / max(1, $n - 1), 1), round($H - 10 - ($H - 30) * $v / $max, 1)];
$line = 'M' . implode(' L', array_map(fn($p) => $p[0] . ' ' . $p[1], $pts));
$area = $line . " L$W $H L0 $H Z";
$peak = $series[array_search($max, array_column($series, 1))][0] ?? '';

// требует внимания
$attn = [];
$r = $db->query("SELECT id, name, last_error, consecutive_errors, last_checked_at FROM sources WHERE is_active = 1 AND consecutive_errors > 0 ORDER BY consecutive_errors DESC LIMIT 4");
while ($r && ($x = $r->fetch_assoc())) $attn[] = ['id' => $x['id'], 'name' => $x['name'], 'when' => ago($x['last_checked_at']), 'text' => mb_strimwidth((string)$x['last_error'], 0, 110, '…'), 'bad' => true];
$r = $db->query("SELECT id, name, silent_since, last_article_at FROM sources WHERE is_active = 1 AND silent_since IS NOT NULL ORDER BY silent_since LIMIT 3");
while ($r && ($x = $r->fetch_assoc())) $attn[] = ['id' => $x['id'], 'name' => $x['name'], 'when' => 'silent', 'text' => 'No new articles since ' . fmt_dt($x['last_article_at']) . '.', 'bad' => false];
$r = $db->query("SELECT s.id, s.name, s.unfinished_count FROM sources s WHERE s.is_active = 1 AND s.unfinished_count > 0 ORDER BY s.unfinished_count DESC LIMIT 3");
while ($r && ($x = $r->fetch_assoc())) $attn[] = ['id' => $x['id'], 'name' => $x['name'], 'when' => '', 'text' => (int)$x['unfinished_count'] . ' articles saved without full text.', 'bad' => false];
$attn = array_slice($attn, 0, 6);

// последние статьи
$rows = [];
$r = $db->query("SELECT a.id, a.title, a.url, a.published_at, a.content_status, COALESCE(s.name, '(deleted source)') AS source_name, a.source_id
    FROM articles a LEFT JOIN sources s ON s.id = a.source_id ORDER BY a.id DESC LIMIT 8");
while ($r && ($x = $r->fetch_assoc())) $rows[] = $x;

function link_label(string $u): string
{
    $p = parse_url($u);
    return preg_replace('/^www\./', '', (string)($p['host'] ?? '')) . rtrim((string)($p['path'] ?? ''), '/');
}
$chips = ['full' => ['Full', 'bg-ms-light text-[#0B4F94]'], 'pending' => ['Pending', 'bg-warnbg text-warn'], 'partial' => ['Partial', 'bg-dangerbg text-danger'], 'title_only' => ['Title only', 'bg-dangerbg text-danger']];

app_start(t('Dashboard'), 'dashboard', $user);
crawler_banner(); ?>
<div class="mb-6 flex flex-wrap items-center gap-4">
  <div class="flex-1 min-w-[220px]"><h1 class="<?= ui('h1') ?>"><?= e(t('Overview')) ?></h1><div class="mt-1 text-sm text-muted"><?= e(t('Welcome')) ?>, <?= e($user['username']) ?>. <?= e(t(['24h' => 'Everything the engine collected in the last 24 hours', '7d' => 'Everything collected in the last 7 days', '30d' => 'Everything collected in the last 30 days'][$range])) ?></div></div>
  <div class="flex rounded-[10px] border border-line2 bg-white overflow-hidden">
    <?php foreach (['24h' => '24 h', '7d' => '7 days', '30d' => '30 days'] as $k => $l): ?>
      <a href="?range=<?= $k ?>" class="h-[38px] px-4 flex items-center text-[13px] font-semibold <?= $range === $k ? 'bg-ms text-white' : 'text-[#26364D] hover:bg-surface' ?>"><?= e(t($l)) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid gap-4 grid-cols-2 xl:grid-cols-4">
  <?php foreach ([
      [t('Articles collected'), number_format($arts), $delta === null ? number_format($totalAll) . ' ' . t('in total') : ($delta >= 0 ? '+' : '−') . abs($delta) . '% ' . t('vs previous period'), 'text-[#0B5CAD]'],
      [t('Active sources'), $active . ' / ' . $total, $total - $active ? ($total - $active) . ' ' . t('paused') : t('all running'), 'text-muted'],
      [t('Full text rate'), $rate . '%', t('of articles in this period'), 'text-[#0B5CAD]'],
      [t('Errors'), number_format($errs), $errs ? t('see Logs') : t('none in this period'), $errs ? 'text-danger' : 'text-[#0B5CAD]'],
  ] as [$l, $v, $d, $c]): ?>
    <div class="<?= ui('card') ?> p-4 sm:p-5">
      <div class="text-[13px] font-medium text-muted"><?= e($l) ?></div>
      <div class="mt-2 text-2xl sm:text-[30px] font-bold tracking-tight leading-none"><?= e($v) ?></div>
      <div class="mt-2 text-xs font-semibold <?= $c ?>"><?= e($d) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-5 grid gap-4 xl:grid-cols-3">
  <section class="<?= ui('card') ?> p-5 xl:col-span-2 min-w-0">
    <div class="flex items-baseline justify-between gap-3"><h2 class="text-base font-semibold"><?= e(t($range === '24h' ? 'Articles per hour' : 'Articles per day')) ?></h2><span class="text-[13px] text-muted"><?= $arts ? e(t('Peak')) . ' ' . number_format($max) . ' · ' . e($peak) : '' ?></span></div>
    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" width="100%" height="200" preserveAspectRatio="none" class="mt-3 block" role="img" aria-label="Articles over time">
      <g stroke="#E1E7F0" stroke-width="1"><path d="M0 30H<?= $W ?>M0 80H<?= $W ?>M0 130H<?= $W ?>M0 180H<?= $W ?>"/></g>
      <path d="<?= $area ?>" fill="#E8F2FC"/>
      <path d="<?= $line ?>" stroke="#0078D4" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
    </svg>
    <div class="mt-1.5 flex justify-between text-xs text-muted"><span><?= e($series[0][0]) ?></span><span><?= e($series[(int)($n / 2)][0]) ?></span><span><?= e(t('now')) ?></span></div>
  </section>
  <section class="<?= ui('card') ?> p-5 min-w-0">
    <div class="flex items-center justify-between"><h2 class="text-base font-semibold"><?= e(t('Needs attention')) ?></h2><?php if ($attn): ?><span class="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-dangerbg text-danger"><?= count($attn) ?></span><?php endif; ?></div>
    <?php if (!$attn): ?>
      <div class="mt-3 rounded-xl bg-ms-light px-4 py-4 text-sm font-medium text-[#0B4F94]"><?= e(t('Everything runs normally.')) ?></div>
    <?php else: foreach ($attn as $a): ?>
      <a href="/source.php?id=<?= (int)$a['id'] ?>" class="block py-3 border-t border-line first:border-0 mt-1 first:mt-3 hover:bg-surface -mx-2 px-2 rounded-lg">
        <div class="flex justify-between gap-3"><span class="text-sm font-semibold"><?= e($a['name']) ?></span><span class="text-xs text-muted shrink-0"><?= e($a['when']) ?></span></div>
        <div class="mt-0.5 text-[13px] <?= $a['bad'] ? 'text-danger' : 'text-muted' ?>"><?= e($a['text']) ?></div>
      </a>
    <?php endforeach; endif; ?>
  </section>
</div>

<section class="<?= ui('card') ?> mt-5 overflow-hidden">
  <div class="flex items-center justify-between px-5 py-4"><h2 class="text-base font-semibold"><?= e(t('Latest articles')) ?></h2><?php if (can('articles.view')): ?><a href="/articles.php" class="<?= ui('link') ?> text-[13px]"><?= e(t('View all')) ?></a><?php endif; ?></div>
  <?php if (!$rows): ?><div class="px-5 pb-6 text-sm text-muted"><?= e(t('No articles yet. Add a source to start.')) ?></div><?php else: ?>
  <div class="hidden md:block overflow-x-auto"><div class="min-w-[760px]">
    <div class="grid grid-cols-[minmax(260px,3fr)_1.2fr_1.6fr_110px_100px] gap-4 px-5 py-2.5 bg-[#F6F8FC] border-y border-line text-xs font-semibold text-muted"><span><?= e(t('Title')) ?></span><span><?= e(t('Source')) ?></span><span><?= e(t('Original link')) ?></span><span><?= e(t('Published')) ?></span><span><?= e(t('Text')) ?></span></div>
    <?php foreach ($rows as $a): $c = $chips[$a['content_status']] ?? $chips['full']; ?>
      <div class="grid grid-cols-[minmax(260px,3fr)_1.2fr_1.6fr_110px_100px] gap-4 items-center px-5 py-3 border-b border-line last:border-0 text-sm hover:bg-surface">
        <a href="/article.php?id=<?= (int)$a['id'] ?>" class="font-medium text-ink truncate hover:text-[#0B5CAD]"><?= e($a['title']) ?></a>
        <span class="text-[#26364D] truncate"><?= e($a['source_name']) ?></span>
        <a href="<?= e($a['url']) ?>" target="_blank" rel="noopener noreferrer" class="flex items-center gap-1.5 text-[13px] text-[#0B5CAD] truncate hover:underline"><svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg><span class="truncate"><?= e(link_label($a['url'])) ?></span></a>
        <span class="text-[13px] text-muted"><?= e(ago($a['published_at'])) ?></span>
        <span class="justify-self-start <?= ui('chip') ?> <?= $c[1] ?>"><?= e(t($c[0])) ?></span>
      </div>
    <?php endforeach; ?>
  </div></div>
  <div class="md:hidden divide-y divide-line border-t border-line">
    <?php foreach ($rows as $a): $c = $chips[$a['content_status']] ?? $chips['full']; ?>
      <div class="px-4 py-3">
        <a href="/article.php?id=<?= (int)$a['id'] ?>" class="block text-sm font-semibold leading-snug"><?= e($a['title']) ?></a>
        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
          <span><?= e($a['source_name']) ?></span><span><?= e(ago($a['published_at'])) ?></span>
          <a href="<?= e($a['url']) ?>" target="_blank" rel="noopener noreferrer" class="text-[#0B5CAD] truncate max-w-[200px]"><?= e(link_label($a['url'])) ?> ↗</a>
          <span class="<?= ui('chip') ?> <?= $c[1] ?>"><?= e(t($c[0])) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php app_end();
