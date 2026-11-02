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
$typeLabel = ['rss' => 'RSS', 'sitemap' => 'Sitemap', 'telegram' => 'Telegram', 'facebook' => 'Facebook'];

app_start(t('Sources'), 'sources', $user);
crawler_banner();
page_title(t('Sources'), '<a href="/source_edit.php" class="' . ui('btn') . '"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>' . e(t('Add source')) . '</a>',
    count($rows) . ' ' . t('sources'));

if (!$rows): ?>
  <div class="<?= ui('card') ?> p-10 text-center"><div class="text-base font-semibold"><?= e(t('No sources yet.')) ?></div><div class="mt-1 text-sm text-muted"><?= e(t('Add the first one: paste a feed address and see the articles before saving.')) ?></div>
    <a href="/source_edit.php" class="<?= ui('btn') ?> mt-4"><?= e(t('Add source')) ?></a></div>
<?php else:
  mobile_sort(['name' => 'Name', 'articles' => 'Articles', 'last_article' => 'Last article', 'status' => 'Status'], $st); ?>
  <div class="<?= ui('card') ?> overflow-hidden hidden md:block">
    <table class="w-full">
      <thead class="bg-[#F6F8FC] border-b border-line"><tr>
        <th class="<?= ui('th') ?> pl-5"><?= sort_th('Name', 'name', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Category', 'category', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Articles', 'articles', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Last article', 'last_article', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Last check', 'last_check', $st) ?></th>
        <th class="<?= ui('th') ?>"><?= sort_th('Status', 'status', $st) ?></th>
        <th class="<?= ui('th') ?>"></th>
      </tr></thead>
      <tbody class="divide-y divide-line">
      <?php foreach ($rows as $s): $b = src_state($s); ?>
        <tr class="hover:bg-surface">
          <td class="<?= ui('td') ?> pl-5 max-w-[320px]">
            <div class="flex items-center gap-2"><a class="font-semibold text-ink hover:text-[#0B5CAD]" href="/source.php?id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a><span class="text-[11px] font-semibold px-2 py-0.5 rounded-full border border-line2 text-muted"><?= e($typeLabel[$s['type']] ?? $s['type']) ?></span></div>
            <div class="truncate text-[13px] text-muted"><?= e($s['feed_url']) ?></div>
            <?php if ($s['consecutive_errors'] > 0 && $s['last_error']): ?><div class="mt-0.5 text-xs font-medium text-danger truncate"><?= e(mb_strimwidth($s['last_error'], 0, 90, '…')) ?></div><?php endif; ?>
          </td>
          <td class="<?= ui('td') ?> text-sm"><?= $s['category'] ? e($s['category']) : '—' ?></td>
          <td class="<?= ui('td') ?> text-sm font-semibold"><?= number_format((int)$s['articles_count']) ?><?php if ($s['unfinished_count'] > 0): ?><a href="/articles.php?source=<?= (int)$s['id'] ?>&status=unfinished" class="block text-xs font-semibold text-danger hover:underline"><?= (int)$s['unfinished_count'] ?> <?= e(t('incomplete')) ?></a><?php endif; ?></td>
          <td class="<?= ui('td') ?> text-sm whitespace-nowrap" title="<?= e(fmt_dt($s['last_article_at'])) ?>"><?= e(ago($s['last_article_at'])) ?></td>
          <td class="<?= ui('td') ?> text-sm whitespace-nowrap" title="<?= e(fmt_dt($s['last_checked_at'])) ?>"><?= e(ago($s['last_checked_at'])) ?></td>
          <td class="<?= ui('td') ?>"><span class="<?= ui('chip') ?> <?= $b[1] ?>"><?= e(t($b[0])) ?></span></td>
          <td class="<?= ui('td') ?> pr-5"><?php src_actions($s, 'list'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="md:hidden space-y-3">
    <?php foreach ($rows as $s): $b = src_state($s); ?>
      <div class="<?= ui('card') ?> p-4">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0"><a class="text-[15px] font-semibold text-ink" href="/source.php?id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a>
            <div class="truncate text-[13px] text-muted"><?= e($s['feed_url']) ?></div></div>
          <span class="<?= ui('chip') ?> shrink-0 <?= $b[1] ?>"><?= e(t($b[0])) ?></span>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
          <div class="rounded-xl bg-surface py-2"><div class="text-base font-bold"><?= number_format((int)$s['articles_count']) ?></div><div class="text-[11px] text-muted"><?= e(t('Articles')) ?></div></div>
          <div class="rounded-xl bg-surface py-2"><div class="text-sm font-bold"><?= e(ago($s['last_article_at'])) ?></div><div class="text-[11px] text-muted"><?= e(t('Last article')) ?></div></div>
          <div class="rounded-xl bg-surface py-2"><div class="text-sm font-bold"><?= e(ago($s['last_checked_at'])) ?></div><div class="text-[11px] text-muted"><?= e(t('Last check')) ?></div></div>
        </div>
        <?php if ($s['unfinished_count'] > 0): ?><div class="mt-2 text-xs font-semibold text-danger"><?= (int)$s['unfinished_count'] ?> <?= e(t('incomplete')) ?></div><?php endif; ?>
        <div class="mt-3"><?php src_actions($s, 'list'); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif;
app_end();
