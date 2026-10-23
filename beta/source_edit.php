<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';

$id = (int)($_GET['id'] ?? 0);
$user = $id ? require_perm('sources.manage') : require_perm('source.add');
$intervals = [60 => '1 minute', 120 => '2 minutes', 180 => '3 minutes', 300 => '5 minutes', 600 => '10 minutes', 900 => '15 minutes'];

$s = ['name' => '', 'type' => 'rss', 'feed_url' => '', 'download_mode' => 'text', 'dedup_by' => 'url', 'category_id' => null,
      'min_input' => utc_to_ui_input(gmdate('Y-m-d H:i:s')), 'check_interval_sec' => 120, 'is_active' => 1];

if ($id) {
    $r = cdb()->query("SELECT * FROM sources WHERE id = $id LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        flash_set('err', 'Source not found.');
        header('Location: /sources.php');
        exit;
    }
    $s = array_merge($s, $row, ['min_input' => utc_to_ui_input($row['min_published_at'])]);
}

$cats = [];
$r = cdb()->query('SELECT id, name FROM categories ORDER BY name LIMIT 1000');
while ($r && ($x = $r->fetch_assoc())) {
    $cats[(int)$x['id']] = $x['name'];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s['name'] = trim((string)($_POST['name'] ?? ''));
    $s['feed_url'] = trim((string)($_POST['feed_url'] ?? ''));
    $s['type'] = in_array($_POST['type'] ?? '', ['rss', 'html', 'social'], true) ? $_POST['type'] : 'rss';
    $s['download_mode'] = ($_POST['download_mode'] ?? '') === 'text_photo' ? 'text_photo' : 'text';
    $s['dedup_by'] = ($_POST['dedup_by'] ?? '') === 'guid' ? 'guid' : 'url';
    $s['check_interval_sec'] = isset($intervals[(int)($_POST['check_interval_sec'] ?? 0)]) ? (int)$_POST['check_interval_sec'] : 120;
    $s['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $s['min_input'] = (string)($_POST['min_published_at'] ?? '');
    $minUtc = ui_to_utc($s['min_input']);
    $catId = (int)($_POST['category_id'] ?? 0);
    $s['category_id'] = isset($cats[$catId]) ? $catId : null;
    $newCat = trim((string)($_POST['new_category'] ?? ''));

    if (!csrf_ok()) $errors[] = 'Session expired, please reload the page.';
    if ($s['name'] === '' || mb_strlen($s['name']) > 190) $errors[] = 'Source name is required (up to 190 characters).';
    if (!filter_var($s['feed_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $s['feed_url']) || strlen($s['feed_url']) > 1024) {
        $errors[] = 'Feed URL must be a valid http(s) link.';
    }
    if (!$minUtc) $errors[] = 'Start date is invalid.';
    if ($newCat !== '' && mb_strlen($newCat) > 120) $errors[] = 'Category name is too long.';

    if (!$errors) {
        if ($newCat !== '' && can('categories.manage')) {
            $c = cdb()->prepare('INSERT INTO categories (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
            $c->bind_param('s', $newCat);
            $c->execute();
            $s['category_id'] = (int)cdb()->insert_id;
        }
        $hash = md5($s['feed_url']);
        $cid = $s['category_id'];
        if ($id) {
            $q = cdb()->prepare('UPDATE sources SET name=?, type=?, feed_url=?, feed_url_hash=?, download_mode=?, dedup_by=?,
                min_published_at=?, check_interval_sec=?, is_active=?, category_id=? WHERE id=?');
            $q->bind_param('sssssssiiii', $s['name'], $s['type'], $s['feed_url'], $hash, $s['download_mode'], $s['dedup_by'],
                $minUtc, $s['check_interval_sec'], $s['is_active'], $cid, $id);
        } else {
            $q = cdb()->prepare('INSERT INTO sources (name, type, feed_url, feed_url_hash, download_mode, dedup_by,
                min_published_at, check_interval_sec, is_active, category_id, next_check_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
            $q->bind_param('sssssssiii', $s['name'], $s['type'], $s['feed_url'], $hash, $s['download_mode'], $s['dedup_by'],
                $minUtc, $s['check_interval_sec'], $s['is_active'], $cid);
        }
        if ($q->execute()) {
            flash_set('ok', $id ? 'Source saved.' : 'Source added.');
            header('Location: ' . (can('sources.view') ? '/sources.php' : '/'));
            exit;
        }
        $errors[] = $q->errno === 1062 ? 'A source with this name or feed URL already exists.' : 'Database error, please try again.';
    }
}

$title = $id ? 'Edit source' : 'Add source';
app_start(t($title), $id ? 'sources' : 'source_add', $user);
page_title(t($title));
if ($errors): ?>
  <div class="mb-6 max-w-3xl rounded-xl border border-red-700 bg-red-50 text-red-900 text-sm font-semibold px-4 py-3">
    <?php foreach ($errors as $m): ?><div><?= e(t($m)) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" class="<?= ui('card') ?> max-w-3xl p-5 sm:p-8 space-y-6">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

  <div class="grid gap-5 sm:grid-cols-3">
    <div class="sm:col-span-2">
      <label class="<?= ui('lbl') ?>" for="name"><?= e(t('Source name')) ?></label>
      <input id="name" name="name" required maxlength="190" placeholder="News.am" value="<?= e($s['name']) ?>" class="<?= ui('inp') ?>">
    </div>
    <div>
      <label class="<?= ui('lbl') ?>" for="type"><?= e(t('Type')) ?></label>
      <select id="type" name="type" class="<?= ui('inp') ?>">
        <?php foreach (['rss' => 'RSS', 'html' => 'HTML', 'social' => 'Social'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $s['type'] === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div>
    <label class="<?= ui('lbl') ?>" for="feed_url"><?= e(t('Feed URL')) ?></label>
    <input id="feed_url" name="feed_url" type="url" required maxlength="1024" placeholder="https://news.am/rss" value="<?= e($s['feed_url']) ?>" class="<?= ui('inp') ?>">
  </div>

  <div class="grid gap-5 sm:grid-cols-2">
    <div>
      <label class="<?= ui('lbl') ?>" for="category_id"><?= e(t('Category')) ?></label>
      <select id="category_id" name="category_id" class="<?= ui('inp') ?>">
        <option value="0">— <?= e(t('No category')) ?> —</option>
        <?php foreach ($cats as $cid => $cn): ?>
          <option value="<?= $cid ?>" <?= (int)$s['category_id'] === $cid ? 'selected' : '' ?>><?= e($cn) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (can('categories.manage')): ?>
    <div>
      <label class="<?= ui('lbl') ?>" for="new_category"><?= e(t('Or create new category')) ?></label>
      <input id="new_category" name="new_category" maxlength="120" class="<?= ui('inp') ?>">
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="<?= ui('lbl') ?>"><?= e(t('What to download')) ?></div>
    <div class="grid gap-3 sm:grid-cols-2">
      <?php foreach (['text' => 'Text only', 'text_photo' => 'Photo + text'] as $v => $l): ?>
        <label class="flex items-center gap-3 rounded-lg border border-black px-4 py-2.5 text-sm font-semibold cursor-pointer has-[:checked]:bg-ms has-[:checked]:text-white">
          <input type="radio" name="download_mode" value="<?= $v ?>" <?= $s['download_mode'] === $v ? 'checked' : '' ?> class="h-4 w-4 accent-black">
          <?= e(t($l)) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="grid gap-5 sm:grid-cols-2">
    <div>
      <label class="<?= ui('lbl') ?>" for="min_published_at"><?= e(t('Download articles published after')) ?></label>
      <input id="min_published_at" name="min_published_at" type="datetime-local" required value="<?= e($s['min_input']) ?>" class="<?= ui('inp') ?>">
      <p class="mt-1.5 text-sm font-medium"><?= e(t('Older articles are skipped. Time zone: Yerevan (UTC+4).')) ?></p>
    </div>
    <div>
      <label class="<?= ui('lbl') ?>" for="check_interval_sec"><?= e(t('Check every')) ?></label>
      <select id="check_interval_sec" name="check_interval_sec" class="<?= ui('inp') ?>">
        <?php foreach ($intervals as $v => $l): ?>
          <option value="<?= $v ?>" <?= (int)$s['check_interval_sec'] === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="grid gap-5 sm:grid-cols-2">
    <div>
      <label class="<?= ui('lbl') ?>" for="dedup_by"><?= e(t('Duplicate check (within this source)')) ?></label>
      <select id="dedup_by" name="dedup_by" class="<?= ui('inp') ?>">
        <option value="url" <?= $s['dedup_by'] === 'url' ? 'selected' : '' ?>><?= e(t('By article URL')) ?></option>
        <option value="guid" <?= $s['dedup_by'] === 'guid' ? 'selected' : '' ?>><?= e(t('By feed GUID')) ?></option>
      </select>
      <p class="mt-1.5 text-sm font-medium"><?= e(t('Always on: this source never stores the same article twice. Other sources may.')) ?></p>
    </div>
    <label class="flex items-center gap-3 self-start sm:mt-7 rounded-lg border border-black px-4 py-2.5 text-sm font-semibold cursor-pointer">
      <input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?> class="h-4 w-4 accent-black">
      <?= e(t('Active (crawl this source)')) ?>
    </label>
  </div>

  <div class="flex flex-col-reverse sm:flex-row gap-3 pt-2 border-t border-black/10 pt-6">
    <a href="<?= can('sources.view') ? '/sources.php' : '/' ?>" class="<?= ui('btn2') ?>"><?= e(t('Cancel')) ?></a>
    <button class="<?= ui('btn') ?> sm:px-8"><?= e(t('Save')) ?></button>
  </div>
</form>
<?php app_end();
