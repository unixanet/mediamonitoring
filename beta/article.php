<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('search');

$id = (int)($_GET['id'] ?? 0);
$r = cdb()->query("SELECT a.*, COALESCE(s.name, '(deleted source)') AS source_name, c.name AS category FROM articles a
    LEFT JOIN sources s ON s.id = a.source_id LEFT JOIN categories c ON c.id = s.category_id WHERE a.id = $id");
$a = $r ? $r->fetch_assoc() : null;

app_start($a['title'] ?? t('Article'), 'search', $user);
if (!$a) {
    echo '<div class="' . ui('card') . ' p-8 text-center font-semibold">' . e(t('Article not found.')) . '</div>';
    app_end();
    exit;
}
?>
<?php
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
$rp = parse_url($ref);
$back = ($rp && ($rp['host'] ?? '') === explode(':', $_SERVER['HTTP_HOST'] ?? '')[0] && !preg_match('#/(article|article_action|login)\.php#', $rp['path'] ?? ''))
    ? ($rp['path'] ?? '/') . (isset($rp['query']) ? '?' . $rp['query'] : '')
    : (can('articles.view') ? '/articles.php?source=' . (int)$a['source_id'] : '/search.php');
?>
<a href="<?= e($back) ?>" class="<?= ui('link') ?>">← <?= e(t('Back')) ?></a>
<article class="<?= ui('card') ?> mt-3 max-w-4xl p-5 sm:p-8">
  <h1 class="text-2xl sm:text-3xl font-bold leading-snug"><?= e($a['title']) ?></h1>
  <div class="mt-3 flex flex-wrap gap-2">
    <span class="<?= ui('chip') ?> bg-ms text-white"><?= e($a['source_name']) ?></span>
    <?php if ($a['category']): ?><span class="<?= ui('chip') ?>"><?= e($a['category']) ?></span><?php endif; ?>
    <span class="<?= ui('chip') ?>"><?= e(t('Published')) ?>: <?= e(fmt_dt($a['published_at'])) ?></span>
    <span class="<?= ui('chip') ?>"><?= e(t('Downloaded')) ?>: <?= e(fmt_dt($a['fetched_at'])) ?></span>
    <?= status_chip($a['content_status']) ?>
    <?php if ((int)$a['revision'] > 0): ?><span class="<?= ui('chip') ?>"><?= e(t('Edited')) ?> ×<?= (int)$a['revision'] ?> · <?= e(fmt_dt($a['updated_at'])) ?></span><?php endif; ?>
  </div>
  <?php if ($a['content_status'] !== 'full'): ?>
    <div class="mt-4 rounded-xl border border-red-700 bg-red-50 px-4 py-3 text-sm font-semibold text-red-900">
      <?= e(t('Only the title and a short description were saved.')) ?>
      <?php if ($a['fail_reason']): ?><div class="font-medium"><?= e(t('Reason')) ?>: <?= e($a['fail_reason']) ?> (<?= (int)$a['fetch_attempts'] ?> <?= e(t('attempts')) ?>)</div><?php endif; ?>
      <?php if ($a['content_status'] === 'pending' && $a['next_retry_at']): ?><div class="font-medium"><?= e(t('Next attempt')) ?>: <?= e(until($a['next_retry_at'])) ?></div><?php endif; ?>
      <?php if (can('sources.view')): ?>
      <form method="post" action="/article_action.php" class="mt-2"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <button name="act" value="retry" class="<?= ui('btn') ?> !py-1.5"><?= e(t('Retry full text now')) ?></button></form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="mt-5 whitespace-pre-line leading-relaxed"><?= e($a['content']) ?></div>
  <a href="<?= e($a['url']) ?>" target="_blank" rel="noopener noreferrer" class="<?= ui('btn') ?> mt-6"><?= e(t('Open original')) ?> ↗</a>
</article>
<?php app_end();
