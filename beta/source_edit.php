<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$intervals = [60 => '1 minute', 120 => '2 minutes', 180 => '3 minutes', 300 => '5 minutes', 600 => '10 minutes', 900 => '15 minutes'];

$s = ['name' => '', 'type' => 'rss', 'feed_url' => '', 'download_mode' => 'text', 'dedup_by' => 'url',
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

    if (!csrf_ok()) $errors[] = 'Session expired, please reload the page.';
    if ($s['name'] === '' || mb_strlen($s['name']) > 190) $errors[] = 'Source name is required (up to 190 characters).';
    if (!filter_var($s['feed_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $s['feed_url']) || strlen($s['feed_url']) > 1024) {
        $errors[] = 'Feed URL must be a valid http(s) link.';
    }
    if (!$minUtc) $errors[] = 'Start date is invalid.';

    if (!$errors) {
        $hash = md5($s['feed_url']);
        if ($id) {
            $st = cdb()->prepare('UPDATE sources SET name=?, type=?, feed_url=?, feed_url_hash=?, download_mode=?, dedup_by=?,
                min_published_at=?, check_interval_sec=?, is_active=? WHERE id=?');
            $st->bind_param('sssssssiii', $s['name'], $s['type'], $s['feed_url'], $hash, $s['download_mode'], $s['dedup_by'],
                $minUtc, $s['check_interval_sec'], $s['is_active'], $id);
        } else {
            $st = cdb()->prepare('INSERT INTO sources (name, type, feed_url, feed_url_hash, download_mode, dedup_by,
                min_published_at, check_interval_sec, is_active, next_check_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
            $st->bind_param('sssssssii', $s['name'], $s['type'], $s['feed_url'], $hash, $s['download_mode'], $s['dedup_by'],
                $minUtc, $s['check_interval_sec'], $s['is_active']);
        }
        if ($st->execute()) {
            flash_set('ok', $id ? 'Source saved.' : 'Source added.');
            header('Location: /sources.php');
            exit;
        }
        $errors[] = $st->errno === 1062 ? 'A source with this name or feed URL already exists.' : 'Database error, please try again.';
    }
}

$title = $id ? 'Edit source' : 'Add source';
$inp = 'w-full rounded-xl border-2 border-ms px-4 py-3 text-black bg-white focus:outline-none focus:ring-4 focus:ring-ms-mid';
$lbl = 'block font-bold text-black mb-1.5';
app_start(t($title), 'sources', $user); ?>
<a href="/sources.php" class="inline-block font-bold text-ms hover:text-ms-dark">← <?= e(t('Sources')) ?></a>
<h1 class="mt-3 text-3xl sm:text-4xl font-extrabold"><?= e(t($title)) ?></h1>
<div class="mt-2 h-1.5 w-16 rounded-full bg-accent"></div>

<?php if ($errors): ?>
  <div class="mt-6 max-w-3xl rounded-xl border-2 border-red-600 bg-red-50 text-red-900 font-semibold px-4 py-3">
    <?php foreach ($errors as $m): ?><div><?= e(t($m)) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" class="mt-8 max-w-3xl space-y-6">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

  <div class="grid gap-6 sm:grid-cols-3">
    <div class="sm:col-span-2">
      <label class="<?= $lbl ?>" for="name"><?= e(t('Source name')) ?></label>
      <input id="name" name="name" required maxlength="190" placeholder="News.am" value="<?= e($s['name']) ?>" class="<?= $inp ?>">
    </div>
    <div>
      <label class="<?= $lbl ?>" for="type"><?= e(t('Type')) ?></label>
      <select id="type" name="type" class="<?= $inp ?>">
        <?php foreach (['rss' => 'RSS', 'html' => 'HTML', 'social' => 'Social'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $s['type'] === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div>
    <label class="<?= $lbl ?>" for="feed_url"><?= e(t('Feed URL')) ?></label>
    <input id="feed_url" name="feed_url" type="url" required maxlength="1024" placeholder="https://news.am/rss" value="<?= e($s['feed_url']) ?>" class="<?= $inp ?>">
  </div>

  <div>
    <div class="<?= $lbl ?>"><?= e(t('What to download')) ?></div>
    <div class="grid gap-3 sm:grid-cols-2">
      <?php foreach (['text' => 'Text only', 'text_photo' => 'Photo + text'] as $v => $l): ?>
        <label class="flex items-center gap-3 rounded-xl border-2 border-ms px-4 py-3 font-semibold cursor-pointer has-[:checked]:bg-ms has-[:checked]:text-white">
          <input type="radio" name="download_mode" value="<?= $v ?>" <?= $s['download_mode'] === $v ? 'checked' : '' ?> class="h-5 w-5 accent-accent">
          <?= e(t($l)) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="grid gap-6 sm:grid-cols-2">
    <div>
      <label class="<?= $lbl ?>" for="min_published_at"><?= e(t('Download articles published after')) ?></label>
      <input id="min_published_at" name="min_published_at" type="datetime-local" required value="<?= e($s['min_input']) ?>" class="<?= $inp ?>">
      <p class="mt-1.5 text-sm font-medium text-black"><?= e(t('Older articles are skipped. Time zone: Yerevan (UTC+4).')) ?></p>
    </div>
    <div>
      <label class="<?= $lbl ?>" for="check_interval_sec"><?= e(t('Check every')) ?></label>
      <select id="check_interval_sec" name="check_interval_sec" class="<?= $inp ?>">
        <?php foreach ($intervals as $v => $l): ?>
          <option value="<?= $v ?>" <?= (int)$s['check_interval_sec'] === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="grid gap-6 sm:grid-cols-2">
    <div>
      <label class="<?= $lbl ?>" for="dedup_by"><?= e(t('Duplicate check (within this source)')) ?></label>
      <select id="dedup_by" name="dedup_by" class="<?= $inp ?>">
        <option value="url" <?= $s['dedup_by'] === 'url' ? 'selected' : '' ?>><?= e(t('By article URL')) ?></option>
        <option value="guid" <?= $s['dedup_by'] === 'guid' ? 'selected' : '' ?>><?= e(t('By feed GUID')) ?></option>
      </select>
      <p class="mt-1.5 text-sm font-medium text-black"><?= e(t('Always on: this source never stores the same article twice. Other sources may.')) ?></p>
    </div>
    <label class="flex items-center gap-3 self-start sm:mt-8 rounded-xl border-2 border-ms px-4 py-3 font-semibold cursor-pointer">
      <input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?> class="h-5 w-5 accent-accent">
      <?= e(t('Active (crawl this source)')) ?>
    </label>
  </div>

  <div class="flex flex-col-reverse sm:flex-row gap-3 pt-2">
    <a href="/sources.php" class="text-center rounded-xl border-2 border-black text-black font-bold px-6 py-3 hover:bg-black hover:text-white transition"><?= e(t('Cancel')) ?></a>
    <button class="rounded-xl bg-accent hover:bg-accent-dark text-white font-bold px-8 py-3 transition"><?= e(t('Save')) ?></button>
  </div>
</form>
<?php app_end();
