<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('sources.view');

$id = (int)($_GET['id'] ?? 0);
$r = cdb()->query("SELECT s.*, c.name AS category FROM sources s LEFT JOIN categories c ON c.id = s.category_id WHERE s.id = $id");
$s = $r ? $r->fetch_assoc() : null;
if (!$s) {
    flash_set('err', 'Source not found.');
    header('Location: /sources.php');
    exit;
}

$arts = [];
$r = cdb()->query("SELECT id, title, published_at FROM articles WHERE source_id = $id ORDER BY published_at DESC, id DESC LIMIT 21");
while ($r && ($a = $r->fetch_assoc())) {
    $arts[] = $a;
}
$more = count($arts) > 20;
$arts = array_slice($arts, 0, 20);

$logs = [];
$r = cdb()->query("SELECT id, level, event, message, created_at FROM crawl_log WHERE source_id = $id AND level IN ('error','warning') ORDER BY id DESC LIMIT 8");
while ($r && ($a = $r->fetch_assoc())) {
    $logs[] = $a;
}

$modes = ['text' => 'Text only', 'text_photo' => 'Photo + text'];
$dedup = ['url' => 'By article URL', 'guid' => 'By feed GUID', 'title' => 'By title', 'title_date' => 'By title + publication day', 'content' => 'By article text'];
if (!$s['is_active']) { $badge = ['Paused', 'bg-black text-white']; }
elseif ($s['consecutive_errors'] > 0) { $badge = ['Error', 'bg-red-50 text-red-900 border-red-700']; }
else { $badge = ['Active', 'bg-ms text-white']; }

function kv(string $k, string $v): void
{
    echo '<div class="flex items-start justify-between gap-4 py-2 border-b border-black/10 last:border-0"><dt class="text-sm font-semibold">' . e($k) . '</dt><dd class="text-sm font-bold text-right break-words min-w-0">' . $v . '</dd></div>';
}

app_start($s['name'], 'sources', $user);
crawler_banner(); ?>
<div class="mb-2"><a href="/sources.php" class="<?= ui('link') ?>">← <?= e(t('Sources')) ?></a></div>
<div class="mb-6 flex flex-col lg:flex-row lg:items-start justify-between gap-4">
  <div class="min-w-0">
    <div class="flex flex-wrap items-center gap-2">
      <h1 class="<?= ui('h1') ?>"><?= e($s['name']) ?></h1>
      <span class="<?= ui('chip') ?> <?= $badge[1] ?>"><?= e(t($badge[0])) ?></span>
      <span class="<?= ui('chip') ?> uppercase"><?= e($s['type']) ?></span>
    </div>
    <a href="<?= e($s['feed_url']) ?>" target="_blank" rel="noopener noreferrer" class="<?= ui('link') ?> break-all text-sm"><?= e($s['feed_url']) ?> ↗</a>
  </div>
  <?php src_actions($s, 'detail'); ?>
</div>

<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
  <!-- Статьи -->
  <section class="<?= ui('card') ?> overflow-hidden order-2 xl:order-1">
    <div class="flex items-center justify-between px-4 py-3 bg-ms-light border-b border-black">
      <h2 class="font-bold"><?= e(t('Latest articles')) ?></h2>
      <span class="text-sm font-semibold"><?= number_format((int)$s['articles_count']) ?> <?= e(t('total')) ?></span>
    </div>
    <?php if (!$arts): ?>
      <div class="p-8 text-center font-semibold"><?= e(t('No articles yet. Click “Fetch now” to download the first ones.')) ?></div>
    <?php else: ?>
      <ul id="artList" class="divide-y divide-black/10"><?php foreach ($arts as $a) echo source_article_row($a); ?></ul>
      <div class="p-4 border-t border-black/10 text-center <?= $more ? '' : 'hidden' ?>" id="moreWrap">
        <button id="moreBtn" class="<?= ui('btn2') ?>"><?= e(t('Load more')) ?></button>
      </div>
    <?php endif; ?>
  </section>

  <!-- Справа: тайминг, настройки, ошибки -->
  <aside class="space-y-6 order-1 xl:order-2">
    <section class="<?= ui('card') ?> p-4">
      <h2 class="font-bold mb-2"><?= e(t('Schedule')) ?></h2>
      <dl>
        <?php
        kv(t('Next check'), $s['is_active'] ? '<span id="nextCheck" data-ts="' . e((string)strtotime($s['next_check_at'] . ' UTC')) . '">' . e(until($s['next_check_at'])) . '</span>' : e(t('Paused')));
        kv(t('Last check'), e(ago($s['last_checked_at'])) . '<div class="text-xs font-semibold">' . e(fmt_dt($s['last_checked_at'])) . '</div>');
        kv(t('Last success'), e(ago($s['last_success_at'])));
        kv(t('Last article'), e(ago($s['last_article_at'])));
        kv(t('HTTP / response'), e($s['last_http_code'] ? (string)$s['last_http_code'] : '—') . ' · ' . e($s['last_response_ms'] !== null ? $s['last_response_ms'] . ' ms' : '—'));
        kv(t('Errors in a row'), (int)$s['consecutive_errors'] > 0 ? '<span class="text-red-800">' . (int)$s['consecutive_errors'] . '</span>' : '0');
        ?>
      </dl>
    </section>

    <section class="<?= ui('card') ?> p-4">
      <div class="flex items-center justify-between mb-2">
        <h2 class="font-bold"><?= e(t('Error log')) ?></h2>
        <a href="/logs.php?source=<?= $id ?>&level=all" class="<?= ui('link') ?> text-sm"><?= e(t('View all')) ?> →</a>
      </div>
      <?php if (!$logs): ?>
        <div class="rounded-lg border border-black/10 bg-ms-light px-3 py-3 text-sm font-semibold"><?= e(t('No errors. All good.')) ?></div>
      <?php else: foreach ($logs as $l): ?>
        <a href="/logs.php?source=<?= $id ?>&level=problems&open=<?= (int)$l['id'] ?>" class="block rounded-lg border <?= $l['level'] === 'error' ? 'border-red-700 bg-red-50' : 'border-black bg-white' ?> px-3 py-2 mb-2 hover:shadow-md transition">
          <div class="flex items-center justify-between gap-2 text-xs font-bold">
            <span class="<?= $l['level'] === 'error' ? 'text-red-900' : '' ?> uppercase"><?= e(str_replace('_', ' ', $l['event'])) ?></span>
            <span><?= e(ago($l['created_at'])) ?></span>
          </div>
          <div class="mt-0.5 text-sm font-medium break-words"><?= e(mb_strimwidth($l['message'], 0, 140, '…')) ?></div>
        </a>
      <?php endforeach; endif; ?>
    </section>

    <section class="<?= ui('card') ?> p-4">
      <div class="flex items-center justify-between mb-2">
        <h2 class="font-bold"><?= e(t('Settings')) ?></h2>
        <a href="/source_edit.php?id=<?= $id ?>" class="<?= ui('link') ?> text-sm"><?= e(t('Edit')) ?></a>
      </div>
      <dl>
        <?php
        kv(t('Category'), $s['category'] ? e($s['category']) : '—');
        kv(t('What to download'), e(t($modes[$s['download_mode']])));
        kv(t('Duplicate check'), e(t($dedup[$s['dedup_by']])));
        kv(t('Download articles published after'), $s['min_published_at'] ? e(fmt_dt($s['min_published_at'])) : e(t('Everything in the feed')));
        kv(t('Check every'), e((string)($s['check_interval_sec'] / 60)) . ' ' . e(t('min')));
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
      const r = await (await fetch('/source_articles.php?id=<?= $id ?>&offset=' + off)).json();
      document.getElementById('artList').insertAdjacentHTML('beforeend', r.html);
      off += 20;
      if (!r.more) document.getElementById('moreWrap').classList.add('hidden');
    } catch (e) {}
    mb.disabled = false;
  };
}
const nc = document.getElementById('nextCheck');
if (nc) setInterval(() => {
  const s = +nc.dataset.ts - Math.floor(Date.now() / 1000);
  nc.textContent = s <= 0 ? '<?= e(t('due now')) ?>' : (s < 60 ? s + ' s' : Math.floor(s / 60) + ' min ' + (s % 60) + ' s');
  if (s > 0) nc.textContent = '<?= e(t('in')) ?> ' + nc.textContent;
}, 1000);
</script>
<?php app_end();
