<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('sources.view');
$db = cdb();

$id = (int)($_GET['id'] ?? 0);
$r = $db->query("SELECT s.*, c.name AS category FROM sources s LEFT JOIN categories c ON c.id = s.category_id WHERE s.id = $id");
$s = $r ? $r->fetch_assoc() : null;
if (!$s) {
    flash_set('err', 'Source not found.');
    header('Location: /sources.php');
    exit;
}
$tab = in_array($_GET['tab'] ?? '', ['articles', 'history', 'errors'], true) ? $_GET['tab'] : 'articles';

// статьи (первые 20) для вкладки Articles
$arts = [];
$more = false;
if ($tab === 'articles') {
    $r = $db->query("SELECT id, title, url, published_at, content_status FROM articles WHERE source_id = $id ORDER BY published_at DESC, id DESC LIMIT 21");
    while ($r && ($a = $r->fetch_assoc())) $arts[] = $a;
    $more = count($arts) > 20;
    $arts = array_slice($arts, 0, 20);
}
// журнал для вкладок History / Errors и правой колонки
$lv = $tab === 'history' ? "('info','warning','error')" : "('error','warning')";
$logs = [];
$r = $db->query("SELECT id, level, event, message, created_at, new_articles, duration_ms FROM crawl_log WHERE source_id = $id AND level IN $lv ORDER BY id DESC LIMIT " . ($tab === 'articles' ? 4 : 50));
while ($r && ($a = $r->fetch_assoc())) $logs[] = $a;

$ftc = ['full' => 0, 'pending' => 0, 'partial' => 0, 'title_only' => 0];
$r = $db->query("SELECT content_status, COUNT(*) FROM articles WHERE source_id = $id GROUP BY content_status");
while ($r && ($x = $r->fetch_row())) $ftc[$x[0]] = (int)$x[1];
$qp = $qf = 0;
$r = $db->query("SELECT status, COUNT(*) FROM url_queue WHERE source_id = $id AND status IN ('pending','failed') GROUP BY status");
while ($r && ($x = $r->fetch_row())) { if ($x[0] === 'pending') $qp = (int)$x[1]; else $qf = (int)$x[1]; }
$ftTotal = array_sum($ftc);
$bad = $ftc['partial'] + $ftc['title_only'];
$pct = $ftTotal ? (int)round(100 * $ftc['full'] / $ftTotal) : 100;
$scfg = json_decode($s['extra'] ?? '', true) ?: [];
$dedup = ['url' => 'By article URL', 'guid' => 'By feed GUID', 'title' => 'By title', 'title_date' => 'By title + publication day', 'content' => 'By article text'];
$typeLabel = ['rss' => 'RSS', 'sitemap' => 'Sitemap', 'listing' => 'Page list', 'telegram' => 'Telegram', 'facebook' => 'Facebook'];
$state = src_state($s);
$method = ['auto' => 'Auto (browser if blocked)', 'http' => 'Direct request', 'browser' => 'Real browser'][$s['fetch_method'] ?? 'http'] ?? 'Direct request';

$nextTs = $s['next_check_at'] ? strtotime($s['next_check_at'] . ' UTC') : null;
$lastTs = $s['last_checked_at'] ? strtotime($s['last_checked_at'] . ' UTC') : null;
$prog = ($nextTs && $lastTs && $nextTs > $lastTs) ? max(2, min(100, (int)round(100 * (time() - $lastTs) / ($nextTs - $lastTs)))) : 0;

function kv(string $k, string $v): void
{
    echo '<div class="flex items-start justify-between gap-4 py-2.5 border-t border-line text-[13px]"><dt class="text-muted">' . e($k) . '</dt><dd class="font-semibold text-right break-words min-w-0">' . $v . '</dd></div>';
}
$tabs = ['articles' => ['Articles', number_format((int)$s['articles_count'])], 'history' => ['Fetch history', ''], 'errors' => ['Errors', '']];

app_start($s['name'], 'sources', $user);
crawler_banner(); ?>
<nav aria-label="Breadcrumb" class="mb-4 text-[13px] text-muted"><a href="/sources.php" class="hover:text-[#0B5CAD]"><?= e(t('Sources')) ?></a> &nbsp;/&nbsp; <span class="font-semibold text-ink"><?= e($s['name']) ?></span></nav>

<div class="mb-6 flex flex-wrap items-start gap-4">
  <div class="flex-1 min-w-[260px]">
    <div class="flex flex-wrap items-center gap-2.5">
      <h1 class="<?= ui('h1') ?> !text-[28px]"><?= e($s['name']) ?></h1>
      <span class="<?= ui('chip') ?> <?= $state[1] ?>"><?= e(t($state[0])) ?></span>
      <span class="<?= ui('chip') ?> border border-line2 text-[#26364D]"><?= e($typeLabel[$s['type']] ?? $s['type']) ?><?= ($s['fetch_method'] ?? '') === 'browser' ? ' · ' . e(t('browser')) : '' ?></span>
      <?php if ($s['category']): ?><span class="<?= ui('chip') ?> border border-line2 text-[#26364D]"><?= e($s['category']) ?></span><?php endif; ?>
    </div>
    <a href="<?= e($s['feed_url']) ?>" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1.5 text-sm text-[#0B5CAD] hover:underline break-all"><?= e($s['feed_url']) ?><?= ext_icon() ?></a>
  </div>
  <?php src_actions($s, 'detail'); ?>
</div>

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px] items-start">
  <section class="<?= ui('card') ?> overflow-hidden order-2 xl:order-1">
    <div class="flex items-center gap-1 px-3 border-b border-line overflow-x-auto">
      <?php foreach ($tabs as $k => [$label, $cnt]): $on = $tab === $k; ?>
        <a href="?id=<?= $id ?>&tab=<?= $k ?>" class="h-[50px] px-3.5 flex items-center gap-2 text-sm whitespace-nowrap border-b-2 <?= $on ? 'border-ms font-semibold text-[#0B4F94]' : 'border-transparent font-medium text-muted hover:text-ink' ?>"><?= e(t($label)) ?><?php if ($cnt !== ''): ?><span class="text-xs px-2 py-0.5 rounded-full bg-ms-light"><?= e($cnt) ?></span><?php endif; ?></a>
      <?php endforeach; ?>
      <div class="flex-1"></div>
      <?php if ($tab === 'articles'): ?><span class="text-[13px] text-muted whitespace-nowrap pr-2"><?= e(t('Newest first')) ?></span><?php endif; ?>
    </div>

    <?php if ($tab === 'articles'): ?>
      <?php if (!$arts): ?>
        <div class="px-5 py-12 text-center"><div class="text-base font-semibold"><?= e(t('No articles yet.')) ?></div><div class="mt-1 text-sm text-muted"><?= e(t('Click “Fetch now” to download the first ones.')) ?></div></div>
      <?php else: ?>
        <ul id="artList"><?php foreach ($arts as $a) echo source_article_row($a); ?></ul>
        <div id="moreWrap" class="p-4 text-center border-t border-line <?= $more ? '' : 'hidden' ?>"><button id="moreBtn" class="<?= ui('btn2') ?>"><?= e(t('Load more')) ?></button></div>
      <?php endif; ?>
    <?php else: ?>
      <?php if (!$logs): ?><div class="px-5 py-12 text-center text-sm text-muted"><?= e(t('Nothing here yet.')) ?></div><?php endif; ?>
      <?php foreach ($logs as $l): $bad2 = $l['level'] === 'error'; ?>
        <a href="/logs.php?source=<?= $id ?>&level=all&period=2592000&open=<?= (int)$l['id'] ?>" class="flex items-start gap-3 px-5 py-3.5 border-b border-line last:border-0 hover:bg-surface">
          <span class="<?= ui('chip') ?> shrink-0 mt-0.5 <?= $bad2 ? 'bg-dangerbg text-danger' : ($l['level'] === 'warning' ? 'bg-warnbg text-warn' : 'bg-ms-light text-[#0B4F94]') ?>"><?= e(str_replace('_', ' ', $l['event'])) ?></span>
          <span class="flex-1 min-w-0 text-sm"><?= e(mb_strimwidth($l['message'], 0, 200, '…')) ?></span>
          <span class="text-xs text-muted whitespace-nowrap" title="<?= e(fmt_dt($l['created_at'])) ?>"><?= e(ago($l['created_at'])) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <aside class="space-y-4 order-1 xl:order-2 min-w-0">
    <section class="<?= ui('card') ?> p-5">
      <h2 class="text-[15px] font-semibold"><?= e(t('Schedule')) ?></h2>
      <?php if ($s['is_active']): ?>
        <div class="mt-3 flex items-baseline gap-2"><span id="nextCheck" data-ts="<?= (int)$nextTs ?>" class="text-[30px] font-bold tracking-tight text-[#0B4F94]"><?= e(until($s['next_check_at'])) ?></span></div>
        <div class="mt-1 text-[13px] text-muted"><?= e(t('until the next check')) ?></div>
        <div class="mt-3 h-1.5 rounded-full bg-ms-light overflow-hidden"><div class="h-full bg-ms" style="width:<?= $prog ?>%"></div></div>
      <?php else: ?><div class="mt-2 text-sm text-muted"><?= e(t('Paused. Press Start to resume.')) ?></div><?php endif; ?>
      <dl class="mt-3">
        <?php
        kv(t('Last check'), e(ago($s['last_checked_at'])));
        kv(t('Last new article'), e(ago($s['last_article_at'])));
        kv(t('HTTP / response'), e($s['last_http_code'] ? (string)$s['last_http_code'] : '-') . ' · ' . e($s['last_response_ms'] !== null ? $s['last_response_ms'] . ' ms' : '-'));
        kv(t('Errors in a row'), (int)$s['consecutive_errors'] > 0 ? '<span class="text-danger">' . (int)$s['consecutive_errors'] . '</span>' : '0');
        ?>
      </dl>
    </section>

    <section class="<?= ui('card') ?> p-5">
      <div class="flex items-baseline justify-between"><h2 class="text-[15px] font-semibold"><?= e(t('Full text')) ?></h2><span class="text-[22px] font-bold text-[#0B4F94]"><?= $pct ?>%</span></div>
      <div class="mt-3 h-2 rounded-full bg-ms-light overflow-hidden"><div class="h-full bg-ms" style="width:<?= $pct ?>%"></div></div>
      <div class="mt-2.5 text-[13px] text-muted"><?= number_format($ftc['full']) ?> <?= e(t('complete')) ?> · <?= number_format($bad + $ftc['pending'] + $qp) ?> <?= e(t('not complete')) ?><?= $qf ? ' · ' . number_format($qf) . ' ' . e(t('pages failed')) : '' ?></div>
      <?php if ($bad || $qf || $ftc['pending']): ?>
        <form method="post" action="/sourceaction.php" class="mt-3"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="detail">
          <button name="act" value="retry" class="<?= ui('btn2') ?> w-full !h-9"><?= e(t('Retry incomplete articles')) ?></button></form>
        <?php if ($bad): ?><a href="/articles.php?source=<?= $id ?>&status=unfinished" class="<?= ui('link') ?> mt-2 inline-block text-[13px]"><?= e(t('Show incomplete articles')) ?> →</a><?php endif; ?>
      <?php endif; ?>
    </section>

    <?php if ($tab === 'articles'): ?>
    <section class="<?= ui('card') ?> p-5">
      <div class="flex items-center justify-between"><h2 class="text-[15px] font-semibold"><?= e(t('Error log')) ?></h2><a href="?id=<?= $id ?>&tab=errors" class="<?= ui('link') ?> text-[13px]"><?= e(t('View all')) ?></a></div>
      <?php if (!$logs): ?>
        <div class="mt-3 rounded-xl bg-ms-light px-3.5 py-3 text-[13px] font-medium text-[#0B4F94]"><?= e(t('No errors. All good.')) ?></div>
      <?php else: foreach ($logs as $l): $b2 = $l['level'] === 'error'; ?>
        <a href="/logs.php?source=<?= $id ?>&level=all&period=2592000&open=<?= (int)$l['id'] ?>" class="mt-2.5 block rounded-xl border px-3.5 py-2.5 hover:shadow-sm <?= $b2 ? 'border-[#F0C4C8] bg-[#FDF1F2]' : 'border-line bg-white' ?>">
          <div class="flex justify-between gap-2 text-[11px] font-bold uppercase <?= $b2 ? 'text-danger' : 'text-[#26364D]' ?>"><span><?= e(str_replace('_', ' ', $l['event'])) ?></span><span class="font-medium normal-case text-muted"><?= e(ago($l['created_at'])) ?></span></div>
          <div class="mt-0.5 text-[13px] text-muted break-words"><?= e(mb_strimwidth($l['message'], 0, 130, '…')) ?></div>
        </a>
      <?php endforeach; endif; ?>
    </section>
    <?php endif; ?>

    <section class="<?= ui('card') ?> p-5">
      <div class="flex items-center justify-between mb-1"><h2 class="text-[15px] font-semibold"><?= e(t('Settings')) ?></h2><a href="/sourceedit.php?id=<?= $id ?>" class="<?= ui('link') ?> text-[13px]"><?= e(t('Edit')) ?></a></div>
      <dl>
        <?php
        kv(t('Category'), $s['category'] ? e($s['category']) : '-');
        kv(t('How to fetch'), e(t($method)));
        kv(t('Check interval'), e(((int)$s['check_interval_sec'] / 60) . ' ' . t('min')) . ($s['interval_mode'] === 'auto' ? ' · ' . e(t('auto')) . ($s['auto_interval_sec'] ? ' (' . round($s['auto_interval_sec'] / 60, 1) . ' ' . e(t('min')) . ')' : '') : ''));
        kv(t('Articles from'), $s['min_published_at'] ? e(fmt_dt($s['min_published_at'])) : e(t('Everything in the feed')));
        kv(t('Duplicate check'), e(t($dedup[$s['dedup_by']])));
        kv(t('Time zone of dates'), e($s['feed_timezone']));
        kv(t('Open article pages'), e(t($s['fetch_full_text'] ? 'Yes' : 'No')));
        kv(t('Respect robots.txt'), e(t(!empty($s['respect_robots']) ? 'Yes' : 'No')));
        if (!empty($s['proxy_url'])) kv(t('Proxy'), e(preg_replace('#//[^@/]+@#', '//***@', $s['proxy_url'])));
        if ($s['type'] === 'listing') { kv(t('List block'), e($scfg['list_container'] ?: t('whole page'))); kv(t('Link pattern'), '<span class="break-all">' . e((string)($scfg['list_pattern'] ?? '')) . '</span>'); }
        kv(t('Articles in 7 days'), number_format((int)$s['articles_7d']));
        if ($s['type'] === 'sitemap' && !empty($scfg['probe_next'])) kv(t('Sitemap probe next number'), e(t('On')));
        kv(t('Added'), e(fmt_dt($s['created_at'])));
        ?>
      </dl>
    </section>
  </aside>
</div>

<script>
const mb = document.getElementById('moreBtn');
if (mb) {
  let off = 20;
  mb.onclick = async () => {
    mb.disabled = true;
    try {
      const r = await (await fetch('/sourcearticles.php?id=<?= $id ?>&offset=' + off)).json();
      document.getElementById('artList').insertAdjacentHTML('beforeend', r.html);
      off += 20;
      if (!r.more) document.getElementById('moreWrap').classList.add('hidden');
    } catch (e) {}
    mb.disabled = false;
  };
}
const nc = document.getElementById('nextCheck');
if (nc && +nc.dataset.ts) setInterval(() => {
  const s = +nc.dataset.ts - Math.floor(Date.now() / 1000);
  nc.textContent = s <= 0 ? '<?= e(t('due now')) ?>' : Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}, 1000);
</script>
<?php app_end();
