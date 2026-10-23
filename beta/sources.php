<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('sources.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    if (isset($_POST['start'])) {             // старт: включить и опросить сразу
        $id = (int)$_POST['start'];
        cdb()->query("UPDATE sources SET is_active = 1, next_check_at = NOW() WHERE id = $id");
        flash_set('ok', 'Source started. It will be fetched right away.');
    } elseif (isset($_POST['fetch'])) {       // опросить сейчас, не дожидаясь интервала
        $id = (int)$_POST['fetch'];
        cdb()->query("UPDATE sources SET next_check_at = NOW() WHERE id = $id AND is_active = 1");
        flash_set('ok', 'Fetch requested. The crawler will pick it up right away.');
    } elseif (isset($_POST['pause'])) {
        $id = (int)$_POST['pause'];
        cdb()->query("UPDATE sources SET is_active = 0 WHERE id = $id");
        flash_set('ok', 'Source paused.');
    } elseif (isset($_POST['delete'])) {      // удаление источника вместе со статьями
        $id = (int)$_POST['delete'];
        set_time_limit(300);
        do {
            $ids = [];
            $r = cdb()->query("SELECT id FROM articles WHERE source_id = $id LIMIT 2000");
            while ($r && ($x = $r->fetch_row())) {
                $ids[] = (int)$x[0];
            }
            if ($ids) {
                $in = implode(',', $ids);
                cdb()->query("DELETE FROM article_raw WHERE article_id IN ($in)");
                cdb()->query("DELETE FROM articles WHERE id IN ($in)");
            }
        } while ($ids);
        cdb()->query("DELETE FROM sources WHERE id = $id");
        flash_set('ok', 'Source and its articles deleted.');
    }
    header('Location: /sources.php' . ($_GET ? qs([]) : ''));
    exit;
}

$st = sort_state(['name' => 's.name', 'category' => 'c.name', 'articles' => 's.articles_count', 'last_article' => 's.last_article_at',
    'last_check' => 's.last_checked_at', 'status' => 's.is_active'], 'name', 'asc');
$rows = [];
$r = cdb()->query('SELECT s.id, s.name, s.type, s.feed_url, s.is_active, s.articles_count, s.last_article_at, s.last_checked_at,
    s.consecutive_errors, s.last_error, c.name AS category FROM sources s LEFT JOIN categories c ON c.id = s.category_id
    ORDER BY ' . $st['sql'] . ', s.id LIMIT 1000');
while ($r && ($x = $r->fetch_assoc())) {
    $rows[] = $x;
}

function src_badge(array $s): array
{
    if (!$s['is_active']) return ['Paused', 'bg-black text-white'];
    if ($s['consecutive_errors'] > 0) return ['Error', 'bg-red-50 text-red-900 border-red-700'];
    return ['Active', 'bg-ms text-white'];
}

function src_actions(array $s): void
{ ?>
  <div class="flex flex-wrap gap-2">
    <a class="<?= ui('btn2') ?> !px-3 !py-1.5" href="/articles.php?source=<?= (int)$s['id'] ?>"><?= e(t('Articles')) ?></a>
    <a class="<?= ui('btn2') ?> !px-3 !py-1.5" href="/source_edit.php?id=<?= (int)$s['id'] ?>"><?= e(t('Edit')) ?></a>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <?php if ($s['is_active']): ?>
        <button name="fetch" value="<?= (int)$s['id'] ?>" class="<?= ui('btn') ?> !px-3 !py-1.5">▶ <?= e(t('Fetch now')) ?></button>
      <?php else: ?>
        <button name="start" value="<?= (int)$s['id'] ?>" class="<?= ui('btn') ?> !px-3 !py-1.5">▶ <?= e(t('Start')) ?></button>
      <?php endif; ?>
    </form>
    <?php if ($s['is_active']): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <button name="pause" value="<?= (int)$s['id'] ?>" class="<?= ui('btn2') ?> !px-3 !py-1.5">❚❚ <?= e(t('Pause')) ?></button></form>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('<?= e(t('Delete this source and ALL its articles? This cannot be undone.')) ?>')">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <button name="delete" value="<?= (int)$s['id'] ?>" class="<?= ui('btnd') ?> !px-3 !py-1.5"><?= e(t('Delete')) ?></button></form>
  </div>
<?php }

app_start(t('Sources'), 'sources', $user);
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
            <div class="font-bold"><?= e($s['name']) ?> <span class="<?= ui('chip') ?> ml-1 uppercase"><?= e($s['type']) ?></span></div>
            <div class="truncate text-sm font-medium text-ms-dark"><?= e($s['feed_url']) ?></div>
            <?php if ($s['consecutive_errors'] > 0 && $s['last_error']): ?><div class="text-xs font-semibold text-red-800"><?= e(mb_strimwidth($s['last_error'], 0, 80, '…')) ?></div><?php endif; ?>
          </td>
          <td class="<?= ui('td') ?> font-medium"><?= $s['category'] ? e($s['category']) : '—' ?></td>
          <td class="<?= ui('td') ?> font-bold"><?= number_format((int)$s['articles_count']) ?></td>
          <td class="<?= ui('td') ?> font-medium whitespace-nowrap" title="<?= e(fmt_dt($s['last_article_at'])) ?>"><?= e(ago($s['last_article_at'])) ?></td>
          <td class="<?= ui('td') ?> font-medium whitespace-nowrap" title="<?= e(fmt_dt($s['last_checked_at'])) ?>"><?= e(ago($s['last_checked_at'])) ?></td>
          <td class="<?= ui('td') ?>"><span class="<?= ui('chip') ?> <?= $b[1] ?>"><?= e(t($b[0])) ?></span></td>
          <td class="<?= ui('td') ?>"><?php src_actions($s); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="md:hidden space-y-3">
    <?php foreach ($rows as $s): $b = src_badge($s); ?>
      <div class="<?= ui('card') ?> p-4">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0"><div class="font-bold"><?= e($s['name']) ?></div>
            <div class="truncate text-sm font-medium text-ms-dark"><?= e($s['feed_url']) ?></div></div>
          <span class="<?= ui('chip') ?> shrink-0 <?= $b[1] ?>"><?= e(t($b[0])) ?></span>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
          <div class="rounded-lg border border-black/10 bg-ms-light py-2"><div class="font-bold"><?= number_format((int)$s['articles_count']) ?></div><div class="text-xs font-semibold"><?= e(t('Articles')) ?></div></div>
          <div class="rounded-lg border border-black/10 bg-ms-light py-2"><div class="text-sm font-bold"><?= e(ago($s['last_article_at'])) ?></div><div class="text-xs font-semibold"><?= e(t('Last article')) ?></div></div>
          <div class="rounded-lg border border-black/10 bg-ms-light py-2"><div class="text-sm font-bold"><?= e(ago($s['last_checked_at'])) ?></div><div class="text-xs font-semibold"><?= e(t('Last check')) ?></div></div>
        </div>
        <?php if ($s['category']): ?><div class="mt-2 text-sm font-semibold"><?= e(t('Category')) ?>: <?= e($s['category']) ?></div><?php endif; ?>
        <div class="mt-3"><?php src_actions($s); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif;
app_end();
