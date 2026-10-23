<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('sources.view');

$st = sort_state(['name' => 's.name', 'category' => 'c.name', 'articles' => 's.articles_count', 'last_article' => 's.last_article_at',
    'last_check' => 's.last_checked_at', 'status' => 's.is_active'], 'name', 'asc');
$rows = [];
$r = cdb()->query('SELECT s.id, s.name, s.type, s.feed_url, s.is_active, s.articles_count, s.last_article_at, s.last_checked_at,
    s.consecutive_errors, s.last_error, s.unfinished_count, s.silent_since, c.name AS category FROM sources s LEFT JOIN categories c ON c.id = s.category_id
    ORDER BY ' . $st['sql'] . ', s.id LIMIT 1000');
while ($r && ($x = $r->fetch_assoc())) {
    $rows[] = $x;
}

function src_badge(array $s): array
{
    if (!$s['is_active']) return ['Paused', 'bg-black text-white'];
    if (!empty($s['silent_since'])) return ['Silent', 'bg-red-50 text-red-900 border-red-700'];
    if ($s['consecutive_errors'] > 0) return ['Error', 'bg-red-50 text-red-900 border-red-700'];
    return ['Active', 'bg-ms text-white'];
}

app_start(t('Sources'), 'sources', $user);
crawler_banner();
page_title(t('Sources'), '<a href="/source_edit.php" class="' . ui('btn') . '">+ ' . e(t('Add source')) . '</a>');

if (!$rows): ?>
  <div class="<?= ui('card') ?> p-8 text-center font-semibold"><?= e(t('No sources yet. Add the first one.')) ?></div>
<?php else:
  mobile_sort(['name' => 'Name', 'articles' => 'Articles', 'last_article' => 'Last article', 'status' => 'Status'], $st); ?>
  <div class="<?= ui('card') ?> overflow-hidden hidden md:block">
    <table class="w-full">
      <thead class="bg-ms-light border-b border-black"><tr>
        <th class="<?= ui('th') ?>"><?= sort_th('Name', 'name', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Category', 'category', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Articles', 'articles', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Last article', 'last_article', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Last check', 'last_check', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Status', 'status', $st) ?></th>
        <th class="<?= ui('th') ?>"></th>
      </tr></thead>
      <tbody class="divide-y divide-black/10">
      <?php foreach ($rows as $s): $b = src_badge($s); ?>
        <tr class="hover:bg-ms-light/60">
          <td class="<?= ui('td') ?> max-w-xs">
            <div class="font-bold"><a class="<?= ui('link') ?>" href="/source.php?id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a> <span class="<?= ui('chip') ?> ml-1 uppercase"><?= e($s['type']) ?></span></div>
            <div class="truncate text-sm font-medium text-ms-dark"><?= e($s['feed_url']) ?></div>
            <?php if ($s['consecutive_errors'] > 0 && $s['last_error']): ?><div class="text-xs font-semibold text-red-800"><?= e(mb_strimwidth($s['last_error'], 0, 80, '…')) ?></div><?php endif; ?>
          </td>
          <td class="<?= ui('td') ?> font-medium"><?= $s['category'] ? e($s['category']) : '—' ?></td>
          <td class="<?= ui('td') ?> font-bold"><?= number_format((int)$s['articles_count']) ?><?php if ($s['unfinished_count'] > 0): ?><a href="/articles.php?source=<?= (int)$s['id'] ?>&status=unfinished" class="block text-xs font-bold text-red-800 hover:underline"><?= (int)$s['unfinished_count'] ?> <?= e(t('incomplete')) ?></a><?php endif; ?></td>
          <td class="<?= ui('td') ?> font-medium whitespace-nowrap" title="<?= e(fmt_dt($s['last_article_at'])) ?>"><?= e(ago($s['last_article_at'])) ?></td>
          <td class="<?= ui('td') ?> font-medium whitespace-nowrap" title="<?= e(fmt_dt($s['last_checked_at'])) ?>"><?= e(ago($s['last_checked_at'])) ?></td>
          <td class="<?= ui('td') ?>"><span class="<?= ui('chip') ?> <?= $b[1] ?>"><?= e(t($b[0])) ?></span></td>
          <td class="<?= ui('td') ?>"><?php src_actions($s, 'list'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="md:hidden space-y-3">
    <?php foreach ($rows as $s): $b = src_badge($s); ?>
      <div class="<?= ui('card') ?> p-4">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0"><div class="font-bold"><a class="<?= ui('link') ?>" href="/source.php?id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a></div>
            <div class="truncate text-sm font-medium text-ms-dark"><?= e($s['feed_url']) ?></div></div>
          <span class="<?= ui('chip') ?> shrink-0 <?= $b[1] ?>"><?= e(t($b[0])) ?></span>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
          <div class="rounded-lg border border-black/10 bg-ms-light py-2"><div class="font-bold"><?= number_format((int)$s['articles_count']) ?></div><div class="text-xs font-semibold"><?= e(t('Articles')) ?><?= $s['unfinished_count'] > 0 ? ' · <span class="text-red-800">' . (int)$s['unfinished_count'] . ' ' . e(t('incomplete')) . '</span>' : '' ?></div></div>
          <div class="rounded-lg border border-black/10 bg-ms-light py-2"><div class="text-sm font-bold"><?= e(ago($s['last_article_at'])) ?></div><div class="text-xs font-semibold"><?= e(t('Last article')) ?></div></div>
          <div class="rounded-lg border border-black/10 bg-ms-light py-2"><div class="text-sm font-bold"><?= e(ago($s['last_checked_at'])) ?></div><div class="text-xs font-semibold"><?= e(t('Last check')) ?></div></div>
        </div>
        <?php if ($s['category']): ?><div class="mt-2 text-sm font-semibold"><?= e(t('Category')) ?>: <?= e($s['category']) ?></div><?php endif; ?>
        <div class="mt-3"><?php src_actions($s, 'list'); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif;
app_end();
