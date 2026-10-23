<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';

$id = (int)($_GET['id'] ?? 0);
$user = $id ? require_perm('sources.manage') : require_perm('source.add');
$intervals = [60 => '1 minute', 120 => '2 minutes', 180 => '3 minutes', 300 => '5 minutes', 600 => '10 minutes', 900 => '15 minutes'];
$dedupModes = ['url' => 'By article URL', 'guid' => 'By feed GUID', 'title' => 'By title', 'title_date' => 'By title + publication day', 'content' => 'By article text'];

$s = ['name' => '', 'type' => 'rss', 'feed_url' => '', 'dedup_by' => 'url', 'category_id' => null,
      'start_mode' => 'all', 'start_date' => '', 'check_interval_sec' => 120, 'is_active' => 1,
      'fetch_profile' => 'browser', 'fetch_full_text' => 0, 'feed_timezone' => 'Asia/Yerevan', 'interval_mode' => 'fixed'];
$timezones = ['Asia/Yerevan' => 'Yerevan (UTC+4)', 'UTC' => 'UTC', 'Europe/Moscow' => 'Moscow (UTC+3)', 'Asia/Tbilisi' => 'Tbilisi (UTC+4)', 'Europe/Istanbul' => 'Istanbul (UTC+3)', 'Europe/Kyiv' => 'Kyiv', 'Europe/London' => 'London', 'Europe/Paris' => 'Paris / Berlin', 'America/New_York' => 'New York'];
$xs = ['urls_extra' => '', 'probe_next' => 0, 'map_include' => '', 'url_include' => '', 'url_exclude' => '', 'sel_title' => '', 'sel_body' => ''];

if ($id) {
    $r = cdb()->query("SELECT * FROM sources WHERE id = $id LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        flash_set('err', 'Source not found.');
        header('Location: /sources.php');
        exit;
    }
    $s = array_merge($s, $row);
    $saved = json_decode($row['extra'] ?? '', true) ?: [];
    $xs = array_merge($xs, $saved, ['urls_extra' => implode("\n", (array)($saved['urls_extra'] ?? []))]);
    $s['start_mode'] = $row['min_published_at'] ? 'date' : 'all';
    $s['start_date'] = $row['min_published_at'] ? substr(utc_to_ui_input($row['min_published_at']), 0, 10) : '';
}

$cats = [];
$r = cdb()->query('SELECT id, name FROM categories ORDER BY name LIMIT 1000');
while ($r && ($row2 = $r->fetch_assoc())) {
    $cats[(int)$row2['id']] = $row2['name'];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s['name'] = trim((string)($_POST['name'] ?? ''));
    $s['feed_url'] = trim((string)($_POST['feed_url'] ?? ''));
    $s['type'] = in_array($_POST['type'] ?? '', ['rss', 'sitemap'], true) ? $_POST['type'] : 'rss';
    $s['fetch_profile'] = ($_POST['fetch_profile'] ?? '') === 'bot' ? 'bot' : 'browser';
    $s['fetch_full_text'] = isset($_POST['fetch_full_text']) ? 1 : 0;
    $xs = ['urls_extra' => trim((string)($_POST['urls_extra'] ?? '')), 'probe_next' => isset($_POST['probe_next']) ? 1 : 0,
          'map_include' => trim((string)($_POST['map_include'] ?? '')), 'url_include' => trim((string)($_POST['url_include'] ?? '')),
          'url_exclude' => trim((string)($_POST['url_exclude'] ?? '')), 'sel_title' => trim((string)($_POST['sel_title'] ?? '')),
          'sel_body' => trim((string)($_POST['sel_body'] ?? ''))];
    $extraArr = $xs;
    $extraArr['urls_extra'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $xs['urls_extra']))));
    foreach ($extraArr['urls_extra'] as $eu) {
        if (!filter_var($eu, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $eu)) $errors[] = 'Additional sitemap URLs must be valid http(s) links.';
    }
    foreach (['sel_title', 'sel_body'] as $sk) {
        if ($xs[$sk] !== '' && ft_css_to_xpath($xs[$sk]) === null) $errors[] = 'Selector not supported (use tag, #id, .class and spaces): ' . $xs[$sk];
    }
    $extraJson = json_encode($extraArr, JSON_UNESCAPED_UNICODE);
    $s['feed_timezone'] = isset($timezones[$_POST['feed_timezone'] ?? '']) ? $_POST['feed_timezone'] : 'Asia/Yerevan';
    $s['interval_mode'] = isset($_POST['interval_auto']) ? 'auto' : 'fixed';
    $s['dedup_by'] = isset($dedupModes[$_POST['dedup_by'] ?? '']) ? $_POST['dedup_by'] : 'url';
    $s['check_interval_sec'] = isset($intervals[(int)($_POST['check_interval_sec'] ?? 0)]) ? (int)$_POST['check_interval_sec'] : 120;
    $s['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $s['start_mode'] = in_array($_POST['start_mode'] ?? '', ['all', 'new', 'date'], true) ? $_POST['start_mode'] : 'all';
    $s['start_date'] = trim((string)($_POST['start_date'] ?? ''));
    $catId = (int)($_POST['category_id'] ?? 0);
    $s['category_id'] = isset($cats[$catId]) ? $catId : null;
    $newCat = trim((string)($_POST['new_category'] ?? ''));

    $minUtc = null;   // NULL = всё, что есть в потоке
    if ($s['start_mode'] === 'new') {
        $minUtc = gmdate('Y-m-d H:i:s');
    } elseif ($s['start_mode'] === 'date') {
        $d = DateTime::createFromFormat('Y-m-d', $s['start_date']);
        if (!$d || $d->format('Y-m-d') !== $s['start_date']) {
            $errors[] = 'Pick a start date.';
        } else {
            $minUtc = ui_to_utc($s['start_date'] . ' 00:00');
        }
    }

    if (!csrf_ok()) $errors[] = 'Session expired, please reload the page.';
    if ($s['name'] === '' || mb_strlen($s['name']) > 190) $errors[] = 'Source name is required (up to 190 characters).';
    if (!filter_var($s['feed_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $s['feed_url']) || strlen($s['feed_url']) > 1024) {
        $errors[] = 'Feed URL must be a valid http(s) link.';
    }
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
            $q = cdb()->prepare('UPDATE sources SET name=?, type=?, feed_url=?, feed_url_hash=?, dedup_by=?, min_published_at=?, check_interval_sec=?,
                interval_mode=?, is_active=?, category_id=?, fetch_profile=?, fetch_full_text=?, feed_timezone=?, extra=? WHERE id=?');
            $q->bind_param('ssssssisiisissi', $s['name'], $s['type'], $s['feed_url'], $hash, $s['dedup_by'], $minUtc, $s['check_interval_sec'],
                $s['interval_mode'], $s['is_active'], $cid, $s['fetch_profile'], $s['fetch_full_text'], $s['feed_timezone'], $extraJson, $id);
        } else {
            $q = cdb()->prepare('INSERT INTO sources (name, type, feed_url, feed_url_hash, dedup_by, min_published_at, check_interval_sec,
                interval_mode, is_active, category_id, fetch_profile, fetch_full_text, feed_timezone, extra, next_check_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $q->bind_param('ssssssisiisiss', $s['name'], $s['type'], $s['feed_url'], $hash, $s['dedup_by'], $minUtc, $s['check_interval_sec'],
                $s['interval_mode'], $s['is_active'], $cid, $s['fetch_profile'], $s['fetch_full_text'], $s['feed_timezone'], $extraJson);
        }
        if ($q->execute()) {
            $sid = $id ?: (int)cdb()->insert_id;
            if (!$id && $s['is_active']) {      // первый забег сразу после добавления
                $r = cdb()->query("SELECT * FROM sources WHERE id = $sid");
                [$ft, $fm] = crawl_flash(crawl_source_sync(cdb(), $r->fetch_assoc()), $s['name']);
                flash_set($ft, 'Source added. ' . $fm);
            } else {
                flash_set('ok', $id ? 'Source saved.' : 'Source added.');
            }
            header('Location: ' . (can('sources.view') ? '/source.php?id=' . $sid : '/'));
            exit;
        }
        $errors[] = $q->errno === 1062 ? 'A source with this name or feed URL already exists.' : 'Database error: ' . $q->errno . ' ' . $q->error;
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
        <?php foreach (['rss' => 'RSS', 'sitemap' => 'Sitemap'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $s['type'] === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div>
    <label class="<?= ui('lbl') ?>" for="feed_url"><span id="urlLabel"><?= e(t('Feed URL')) ?></span></label>
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

  <div id="smBox" class="hidden rounded-xl border border-black bg-ms-light/40 p-4 space-y-4">
    <div class="font-bold"><?= e(t('Sitemap settings')) ?></div>
    <p class="text-sm font-medium"><?= e(t('Use a sitemap index (sitemap_index.xml) or a direct sitemap (post-sitemap89.xml). The crawler reads the newest sitemaps, finds new article URLs and downloads each article page.')) ?></p>
    <div>
      <label class="<?= ui('lbl') ?>" for="urls_extra"><?= e(t('More sitemap URLs (one per line, optional)')) ?></label>
      <textarea id="urls_extra" name="urls_extra" rows="2" class="<?= ui('inp') ?>"><?= e($xs['urls_extra']) ?></textarea>
    </div>
    <label class="flex items-center gap-3 rounded-lg border border-black bg-white px-4 py-2.5 text-sm font-semibold cursor-pointer">
      <input type="checkbox" name="probe_next" value="1" <?= $xs['probe_next'] ? 'checked' : '' ?> class="h-4 w-4 accent-black">
      <?= e(t('Probe the next number when there is nothing new (post-sitemap89.xml → post-sitemap90.xml)')) ?>
    </label>
    <div class="grid gap-4 sm:grid-cols-3">
      <div><label class="<?= ui('lbl') ?>"><?= e(t('Only sitemaps containing')) ?></label><input name="map_include" value="<?= e($xs['map_include']) ?>" placeholder="post-sitemap" class="<?= ui('inp') ?>"></div>
      <div><label class="<?= ui('lbl') ?>"><?= e(t('Only URLs containing')) ?></label><input name="url_include" value="<?= e($xs['url_include']) ?>" placeholder="/news/" class="<?= ui('inp') ?>"></div>
      <div><label class="<?= ui('lbl') ?>"><?= e(t('Skip URLs containing')) ?></label><input name="url_exclude" value="<?= e($xs['url_exclude']) ?>" placeholder="/video/, /photo/" class="<?= ui('inp') ?>"></div>
    </div>
  </div>

  <div class="grid gap-5 sm:grid-cols-2">
    <div>
      <label class="<?= ui('lbl') ?>" for="fetch_profile"><?= e(t('Request headers')) ?></label>
      <select id="fetch_profile" name="fetch_profile" class="<?= ui('inp') ?>">
        <option value="browser" <?= $s['fetch_profile'] === 'browser' ? 'selected' : '' ?>><?= e(t('Browser-like (recommended)')) ?></option>
        <option value="bot" <?= $s['fetch_profile'] === 'bot' ? 'selected' : '' ?>><?= e(t('Honest bot')) ?></option>
      </select>
    </div>
    <label class="flex items-center gap-3 self-start sm:mt-7 rounded-lg border border-black px-4 py-2.5 text-sm font-semibold cursor-pointer">
      <input type="checkbox" name="fetch_full_text" value="1" <?= $s['fetch_full_text'] ? 'checked' : '' ?> class="h-4 w-4 accent-black">
      <?= e(t('Open article pages to get the full text')) ?>
      <span class="font-medium">(<?= e(t('RSS: only if the feed has just short descriptions')) ?>)</span>
    </label>
  </div>

  <details class="rounded-xl border border-black/30 px-4 py-3">
    <summary class="cursor-pointer font-semibold"><?= e(t('Advanced: article page selectors (optional)')) ?></summary>
    <p class="mt-2 text-sm font-medium"><?= e(t('Leave empty: title, date and text are detected automatically. If a site is detected wrongly, set CSS selectors (tag, #id, .class, spaces).')) ?></p>
    <div class="mt-3 grid gap-4 sm:grid-cols-2">
      <div><label class="<?= ui('lbl') ?>"><?= e(t('Title selector')) ?></label><input name="sel_title" value="<?= e($xs['sel_title']) ?>" placeholder="h1.article-title" class="<?= ui('inp') ?>"></div>
      <div><label class="<?= ui('lbl') ?>"><?= e(t('Text selector')) ?></label><input name="sel_body" value="<?= e($xs['sel_body']) ?>" placeholder="div.article-body" class="<?= ui('inp') ?>"></div>
    </div>
    <div class="mt-4 border-t border-black/10 pt-4">
      <label class="<?= ui('lbl') ?>" for="pv_url"><?= e(t('Test on an article page (optional)')) ?></label>
      <div class="flex flex-col sm:flex-row gap-3">
        <input id="pv_url" type="url" placeholder="https://site.am/news/123" class="<?= ui('inp') ?>">
        <button type="button" id="pv_btn" class="<?= ui('btn2') ?> sm:w-56"><?= e(t('Preview extraction')) ?></button>
      </div>
      <div id="pv_out" class="mt-3 hidden rounded-lg border border-black bg-white p-3 text-sm"></div>
    </div>
  </details>

  <div>
    <div class="<?= ui('lbl') ?>"><?= e(t('Which articles to download')) ?></div>
    <div class="grid gap-3 sm:grid-cols-3">
      <?php foreach (['all' => 'Everything in the feed', 'new' => 'Only new, from now', 'date' => 'Published from a date'] as $v => $l): ?>
        <label class="flex items-center gap-3 rounded-lg border border-black px-4 py-2.5 text-sm font-semibold cursor-pointer has-[:checked]:bg-ms has-[:checked]:text-white">
          <input type="radio" name="start_mode" value="<?= $v ?>" <?= $s['start_mode'] === $v ? 'checked' : '' ?> class="h-4 w-4 accent-black">
          <?= e(t($l)) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div id="dateWrap" class="mt-3 max-w-xs <?= $s['start_mode'] === 'date' ? '' : 'hidden' ?>">
      <input name="start_date" type="date" value="<?= e($s['start_date']) ?>" class="<?= ui('inp') ?>">
    </div>
    <p class="mt-1.5 text-sm font-medium"><?= e(t('An RSS feed only contains the latest items (usually 10–100). Older articles are not available through RSS.')) ?></p>
  </div>

  <div class="grid gap-5 sm:grid-cols-2">
    <div>
      <label class="<?= ui('lbl') ?>" for="check_interval_sec"><?= e(t('Check every')) ?><?= '' ?></label>
      <select id="check_interval_sec" name="check_interval_sec" class="<?= ui('inp') ?>">
        <?php foreach ($intervals as $v => $l): ?>
          <option value="<?= $v ?>" <?= (int)$s['check_interval_sec'] === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <label class="flex items-center gap-3 self-start sm:mt-7 rounded-lg border border-black px-4 py-2.5 text-sm font-semibold cursor-pointer">
      <input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?> class="h-4 w-4 accent-black">
      <?= e(t('Active (crawl this source)')) ?>
    </label>
  </div>

  <div class="grid gap-5 sm:grid-cols-2">
    <label class="flex items-start gap-3 rounded-lg border border-black px-4 py-2.5 text-sm font-semibold cursor-pointer">
      <input type="checkbox" name="interval_auto" value="1" <?= $s['interval_mode'] === 'auto' ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 accent-black">
      <span><?= e(t('Adjust the interval to the publishing frequency')) ?><span class="block font-medium"><?= e(t('Never faster than the interval above, never slower than 15 minutes.')) ?></span></span>
    </label>
    <div>
      <label class="<?= ui('lbl') ?>" for="feed_timezone"><?= e(t('Time zone of the feed dates')) ?></label>
      <select id="feed_timezone" name="feed_timezone" class="<?= ui('inp') ?>">
        <?php foreach ($timezones as $v => $l): ?><option value="<?= e($v) ?>" <?= $s['feed_timezone'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <p class="mt-1.5 text-sm font-medium"><?= e(t('Used only when a date has no time zone in it.')) ?></p>
    </div>
  </div>

  <div>
    <label class="<?= ui('lbl') ?>" for="dedup_by"><?= e(t('Duplicate check (within this source)')) ?></label>
    <select id="dedup_by" name="dedup_by" class="<?= ui('inp') ?> sm:max-w-sm">
      <?php foreach ($dedupModes as $v => $l): ?>
        <option value="<?= $v ?>" <?= $s['dedup_by'] === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="mt-1.5 text-sm font-medium"><?= e(t('Always on: this source never stores the same article twice. Other sources may. Changing the method applies to new articles only.')) ?></p>
  </div>

  <div class="flex flex-col-reverse sm:flex-row gap-3 border-t border-black/10 pt-6">
    <a href="<?= can('sources.view') ? '/sources.php' : '/' ?>" class="<?= ui('btn2') ?>"><?= e(t('Cancel')) ?></a>
    <button class="<?= ui('btn') ?> sm:px-8"><?= e(t($id ? 'Save' : 'Save and fetch now')) ?></button>
  </div>
</form>
<script>
document.getElementById('pv_btn').onclick = async () => {
  const out = document.getElementById('pv_out'), f = document.querySelector('form'), fd = new FormData();
  ['csrf', 'sel_title', 'sel_body', 'fetch_profile', 'feed_timezone'].forEach(k => fd.append(k, f.elements[k].value));
  fd.append('url', document.getElementById('pv_url').value);
  out.classList.remove('hidden'); out.textContent = '…';
  try {
    const r = await (await fetch('/source_preview.php', { method: 'POST', body: fd })).json();
    out.innerHTML = r.ok
      ? '<div><b>' + r.title + '</b></div><div class="font-semibold">' + r.date + ' · ' + r.length + ' chars</div><div class="mt-2 whitespace-pre-line">' + r.text + '</div>'
      : '<span class="font-semibold text-red-800">' + r.error + '</span>';
  } catch (e) { out.textContent = 'Request failed'; }
};
const typeSel = document.getElementById('type');
function syncType() {
  const sm = typeSel.value === 'sitemap';
  document.getElementById('smBox').classList.toggle('hidden', !sm);
  document.getElementById('urlLabel').textContent = sm ? '<?= e(t('Sitemap URL')) ?>' : '<?= e(t('Feed URL')) ?>';
}
const ftBox = document.querySelector('input[name=fetch_full_text]');
typeSel.onchange = () => { syncType(); if (typeSel.value === 'sitemap') ftBox.checked = true; };
syncType();
document.querySelectorAll('input[name=start_mode]').forEach(r => r.onchange = () =>
  document.getElementById('dateWrap').classList.toggle('hidden', document.querySelector('input[name=start_mode]:checked').value !== 'date'));
</script>
<?php app_end();
