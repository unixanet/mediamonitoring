<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('search');

$id = (int)($_GET['id'] ?? 0);
$r = cdb()->query("SELECT a.*, s.name AS source_name, c.name AS category FROM articles a
    JOIN sources s ON s.id = a.source_id LEFT JOIN categories c ON c.id = s.category_id WHERE a.id = $id");
$a = $r ? $r->fetch_assoc() : null;

app_start($a['title'] ?? t('Article'), 'search', $user);
if (!$a) {
    echo '<div class="' . ui('card') . ' p-8 text-center font-semibold">' . e(t('Article not found.')) . '</div>';
    app_end();
    exit;
}
?>
<a href="javascript:history.back()" class="<?= ui('link') ?>">← <?= e(t('Back')) ?></a>
<article class="<?= ui('card') ?> mt-3 max-w-4xl p-5 sm:p-8">
  <h1 class="text-2xl sm:text-3xl font-bold leading-snug"><?= e($a['title']) ?></h1>
  <div class="mt-3 flex flex-wrap gap-2">
    <span class="<?= ui('chip') ?> bg-ms text-white"><?= e($a['source_name']) ?></span>
    <?php if ($a['category']): ?><span class="<?= ui('chip') ?>"><?= e($a['category']) ?></span><?php endif; ?>
    <span class="<?= ui('chip') ?>"><?= e(t('Published')) ?>: <?= e(fmt_dt($a['published_at'])) ?></span>
    <span class="<?= ui('chip') ?>"><?= e(t('Downloaded')) ?>: <?= e(fmt_dt($a['fetched_at'])) ?></span>
  </div>
  <?php if ($a['image_url']): ?><img src="<?= e($a['image_url']) ?>" alt="" class="mt-5 max-h-96 rounded-xl border border-black" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
  <div class="mt-5 whitespace-pre-line leading-relaxed"><?= e($a['content']) ?></div>
  <a href="<?= e($a['url']) ?>" target="_blank" rel="noopener noreferrer" class="<?= ui('btn') ?> mt-6"><?= e(t('Open original')) ?> ↗</a>
</article>
<?php app_end();
