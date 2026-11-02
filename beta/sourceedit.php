<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';

$id = (int)($_GET['id'] ?? 0);
$user = $id ? require_perm('sources.manage') : require_perm('source.add');
$intervals = [60 => '1 minute', 120 => '2 minutes', 180 => '3 minutes', 300 => '5 minutes', 600 => '10 minutes', 900 => '15 minutes'];
$dedupModes = ['url' => 'By article URL', 'guid' => 'By feed GUID', 'title' => 'By title', 'title_date' => 'By title + publication day', 'content' => 'By article text'];

$s = ['name' => '', 'type' => 'rss', 'feed_url' => '', 'dedup_by' => 'url', 'category_id' => null,
      'start_mode' => 'all', 'start_date' => '', 'check_interval_sec' => 120, 'is_active' => 1,
      'fetch_profile' => 'browser', 'fetch_method' => 'auto', 'respect_robots' => 1, 'proxy_url' => '', 'fetch_full_text' => 1, 'feed_timezone' => 'Asia/Yerevan', 'interval_mode' => 'fixed'];
$timezones = ['Asia/Yerevan' => 'Yerevan (UTC+4)', 'UTC' => 'UTC', 'Europe/Moscow' => 'Moscow (UTC+3)', 'Asia/Tbilisi' => 'Tbilisi (UTC+4)', 'Europe/Istanbul' => 'Istanbul (UTC+3)', 'Europe/Kyiv' => 'Kyiv', 'Europe/London' => 'London', 'Europe/Paris' => 'Paris / Berlin', 'America/New_York' => 'New York'];
$xs = ['list_container' => '', 'list_pattern' => '', 'history_pages' => 5, 'urls_extra' => '', 'probe_next' => 1, 'map_include' => '', 'url_include' => '', 'url_exclude' => '', 'sel_title' => '', 'sel_body' => ''];

if ($id) {
    $r = cdb()->query("SELECT * FROM sources WHERE id = $id LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        flash_set('err', 'Source not found.');
        header('Location: /sources.php');
        exit;
    }
    $s = array_merge($s, $row);
    if ($row['type'] === 'telegram') $s['feed_url'] = '@' . tg_username($row['feed_url']);
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
    $s['type'] = in_array($_POST['type'] ?? '', ['rss', 'sitemap', 'listing', 'telegram', 'facebook'], true) ? $_POST['type'] : 'rss';
    $s['fetch_profile'] = ($_POST['fetch_profile'] ?? '') === 'bot' ? 'bot' : 'browser';
    $s['fetch_full_text'] = isset($_POST['fetch_full_text']) ? 1 : 0;
    $s['fetch_method'] = in_array($_POST['fetch_method'] ?? '', ['auto', 'http', 'browser'], true) ? $_POST['fetch_method'] : 'auto';
    $s['respect_robots'] = isset($_POST['respect_robots']) ? 1 : 0;
    $s['proxy_url'] = trim((string)($_POST['proxy_url'] ?? ''));
    if ($s['proxy_url'] !== '' && !preg_match('#^https?://[^\s]+$#i', $s['proxy_url'])) $errors[] = 'Proxy must look like http://user:password@host:port';
    $xs = ['list_container' => trim((string)($_POST['list_container'] ?? '')), 'list_pattern' => trim((string)($_POST['list_pattern'] ?? '')), 'history_pages' => in_array((int)($_POST['history_pages'] ?? 5), [1, 5, 25, 50], true) ? (int)$_POST['history_pages'] : 5, 'urls_extra' => trim((string)($_POST['urls_extra'] ?? '')), 'probe_next' => isset($_POST['probe_next']) ? 1 : 0,
          'map_include' => trim((string)($_POST['map_include'] ?? '')), 'url_include' => trim((string)($_POST['url_include'] ?? '')),
          'url_exclude' => trim((string)($_POST['url_exclude'] ?? '')), 'sel_title' => trim((string)($_POST['sel_title'] ?? '')),
          'sel_body' => trim((string)($_POST['sel_body'] ?? ''))];
    $extraArr = $xs;
    $extraArr['urls_extra'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $xs['urls_extra']))));
    foreach ($extraArr['urls_extra'] as $eu) {
        if (!filter_var($eu, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $eu)) $errors[] = 'Additional sitemap URLs must be valid http(s) links.';
    }
    foreach (['sel_title', 'sel_body'] as $sk) {
        if ($xs[$sk] !== '' && !css_ok($xs[$sk])) $errors[] = 'Selector not supported (use tag, #id, .class and spaces): ' . $xs[$sk];
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
    if ($s['name'] === '') {
        $hn = parse_url($s['feed_url'], PHP_URL_HOST) ?: tg_username($s['feed_url']);
        $s['name'] = preg_replace('/^www\./', '', (string)$hn);
    }
    if ($s['name'] === '' || mb_strlen($s['name']) > 190) $errors[] = 'Source name is required (up to 190 characters).';
    if ($s['type'] === 'sitemap') {
        $s['fetch_full_text'] = 1;       // карта даёт только ссылки: текст берём со страниц
    }
    if ($s['type'] === 'telegram') {
        $tg = tg_username($s['feed_url']);
        if (!$tg) {
            $errors[] = 'Enter the username of a public Telegram channel, for example durov or https://t.me/durov';
        } else {
            $s['feed_url'] = 'https://t.me/s/' . $tg;
        }
        $s['fetch_full_text'] = 0;     // текст поста уже полный
        $s['dedup_by'] = 'url';
    } elseif ($s['type'] === 'listing') {
        if ($xs['list_pattern'] === '' || @preg_match('~' . str_replace('~', '\~', $xs['list_pattern']) . '~u', '') === false) {
            $errors[] = 'The page list needs a link pattern. Paste the address and pick the "Page list" result on the right.';
        }
        if ($xs['list_container'] !== '' && !css_ok($xs['list_container'])) $errors[] = 'List container selector is not supported (use tag, #id, .class and spaces).';
        $s['fetch_full_text'] = 1;
    } elseif ($s['type'] === 'facebook') {
        if (preg_match('#^(https?://)?([\w.-]+\.)?(facebook|fb)\.com#i', $s['feed_url']) || !preg_match('#^https?://#i', $s['feed_url'])) {
            $errors[] = 'Facebook cannot be read directly. Paste the address of a feed for the public page or group.';
        }
        $s['fetch_full_text'] = 0;     // страницы Facebook закрыты логином
    }
    if ($s['type'] !== 'telegram' && (!filter_var($s['feed_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $s['feed_url']) || strlen($s['feed_url']) > 1024)) {
        if (!$errors) $errors[] = 'Feed URL must be a valid http(s) link.';
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
                interval_mode=?, is_active=?, category_id=?, fetch_profile=?, fetch_method=?, respect_robots=?, proxy_url=?, fetch_full_text=?, feed_timezone=?, extra=? WHERE id=?');
            $purl = $s['proxy_url'] !== '' ? $s['proxy_url'] : null;
            $q->bind_param('ssssssisiissisissi', $s['name'], $s['type'], $s['feed_url'], $hash, $s['dedup_by'], $minUtc, $s['check_interval_sec'],
                $s['interval_mode'], $s['is_active'], $cid, $s['fetch_profile'], $s['fetch_method'], $s['respect_robots'], $purl, $s['fetch_full_text'], $s['feed_timezone'], $extraJson, $id);
        } else {
            $q = cdb()->prepare('INSERT INTO sources (name, type, feed_url, feed_url_hash, dedup_by, min_published_at, check_interval_sec,
                interval_mode, is_active, category_id, fetch_profile, fetch_method, respect_robots, proxy_url, fetch_full_text, feed_timezone, extra, next_check_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $purl = $s['proxy_url'] !== '' ? $s['proxy_url'] : null;
            $q->bind_param('ssssssisiissisiss', $s['name'], $s['type'], $s['feed_url'], $hash, $s['dedup_by'], $minUtc, $s['check_interval_sec'],
                $s['interval_mode'], $s['is_active'], $cid, $s['fetch_profile'], $s['fetch_method'], $s['respect_robots'], $purl, $s['fetch_full_text'], $s['feed_timezone'], $extraJson);
        }
        if ($q->execute()) {
            $sid = $id ?: (int)cdb()->insert_id;
            if (!$id && $s['is_active']) {      // первый забег сразу после добавления
                $r = cdb()->query("SELECT * FROM sources WHERE id = $sid");
                [$ft, $fm] = crawl_flash(engine_call('/source/' . $sid . '/fetch', [], 150), $s['name']);
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
$browserUp = browser_status() !== null;
app_start(t($title), $id ? 'sources' : 'source_add', $user);
page_title(t($title), '', $id ? '' : t('Paste an address and check the articles on the right before you save.'));
if ($errors): ?>
  <div class="mb-5 rounded-xl border border-[#E2A9AE] bg-dangerbg text-danger text-sm font-medium px-4 py-3">
    <?php foreach ($errors as $m): ?><div><?= e(t($m)) ?></div><?php endforeach; ?>
  </div>
<?php endif;
$lbl = ui('lbl'); $inp = ui('inp'); $card = ui('card') . ' p-5 sm:p-6';
$types = ['rss' => ['RSS feed', 'Any news site feed'], 'sitemap' => ['Sitemap', 'Map of the site'], 'listing' => ['Page list', 'Links on a page'], 'telegram' => ['Telegram', 'Public channel'], 'facebook' => ['Facebook', 'Page via RSS service']];
?>
<form method="post" id="sf" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_460px] items-start">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

  <!-- 1. Source -->
  <section class="<?= $card ?> space-y-5 min-w-0 xl:col-start-1">
    <h2 class="text-[15px] font-semibold"><?= e(t($id ? '1. Where to read from' : 'New source')) ?></h2>
    <div class="hidden">
      <div class="<?= $lbl ?>"><?= e(t('Type')) ?></div>
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2.5">
        <?php foreach ($types as $v => [$l, $sub]): ?>
          <label class="cursor-pointer rounded-xl border border-line2 px-3.5 py-2.5 has-[:checked]:border-ms has-[:checked]:bg-ms-light has-[:checked]:ring-2 has-[:checked]:ring-ms/20 hover:border-ms transition">
            <input type="radio" name="type" value="<?= $v ?>" class="sr-only" <?= $s['type'] === $v ? 'checked' : '' ?>>
            <span class="block text-sm font-semibold"><?= e(t($l)) ?></span><span class="block text-xs text-muted"><?= e(t($sub)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <label class="<?= $lbl ?>" for="feed_url"><span id="urlLabel"><?= e(t('Feed URL')) ?></span></label>
      <div class="relative">
        <input id="feed_url" name="feed_url" type="text" inputmode="url" required maxlength="1024" autocomplete="off" placeholder="news.am or any address" value="<?= e($s['feed_url']) ?>" class="<?= $inp ?> pr-11">
        <span id="urlSpin" class="hidden absolute right-3.5 top-1/2 -translate-y-1/2 h-5 w-5 rounded-full border-2 border-ms-mid border-t-ms animate-spin"></span>
        <span id="urlOk" class="hidden absolute right-3.5 top-1/2 -translate-y-1/2 text-ms"><svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></span>
      </div>
      <div id="tgBox" class="hidden mt-2 text-[13px] text-muted"><?= e(t('Only public channels work: https://t.me/s/<username> must show the posts in a browser. Posts with only a photo or video (no text) are skipped. The text of each post is saved in full.')) ?></div>
      <div id="fbBox" class="hidden mt-2 rounded-xl bg-ms-light px-3.5 py-2.5 text-[13px] text-[#0B4F94]"><?= e(t('Facebook does not let servers read pages directly. Paste the feed address of the public page or group, the crawler treats it like any other feed.')) ?></div>
    </div>
    <div class="grid gap-5 sm:grid-cols-2">
      <div>
        <label class="<?= $lbl ?>" for="name"><?= e(t('Source name')) ?></label>
        <input id="name" name="name" maxlength="190" placeholder="News.am" value="<?= e($s['name']) ?>" class="<?= $inp ?>">
      </div>
      <div>
        <label class="<?= $lbl ?>" for="category_id"><?= e(t('Category')) ?></label>
        <select id="category_id" name="category_id" class="<?= $inp ?>">
          <option value="0"><?= e(t('No category')) ?></option>
          <?php foreach ($cats as $cid => $cn): ?><option value="<?= $cid ?>" <?= (int)$s['category_id'] === $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (can('categories.manage')): ?>
      <div class="sm:col-span-2 sm:max-w-[50%]">
        <label class="<?= $lbl ?>" for="new_category"><?= e(t('Or create new category')) ?></label>
        <input id="new_category" name="new_category" maxlength="120" class="<?= $inp ?>">
      </div>
      <?php endif; ?>
    </div>
    <div>
      <div class="<?= $lbl ?>"><?= e(t('Which articles to download')) ?></div>
      <div class="grid gap-2.5 sm:grid-cols-3">
        <?php foreach (['all' => 'Everything in the feed', 'new' => 'Only new, from now', 'date' => 'Published from a date'] as $v => $l): ?>
          <label class="flex items-center gap-3 rounded-[10px] border border-line2 px-4 py-2.5 text-sm font-medium cursor-pointer has-[:checked]:border-ms has-[:checked]:bg-ms-light has-[:checked]:text-[#0B4F94]">
            <input type="radio" name="start_mode" value="<?= $v ?>" <?= $s['start_mode'] === $v ? 'checked' : '' ?> class="h-4 w-4 accent-[#0078D4]"><?= e(t($l)) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div id="dateWrap" class="mt-3 max-w-xs <?= $s['start_mode'] === 'date' ? '' : 'hidden' ?>"><input name="start_date" type="date" value="<?= e($s['start_date']) ?>" class="<?= $inp ?>"></div>
      <p class="mt-2 text-[13px] text-muted"><?= e(t('An RSS feed only contains the latest items (usually 10-100). Older articles are not available through RSS.')) ?></p>
    </div>
    <div>
      <label class="<?= $lbl ?>" for="url_exclude"><?= e(t('Skip URLs containing')) ?></label>
      <input id="url_exclude" name="url_exclude" value="<?= e($xs['url_exclude']) ?>" placeholder="/ru/, /video/" class="<?= $inp ?>">
      <p class="mt-1.5 text-xs text-muted"><?= e(t('Optional. Articles whose address contains any of these parts are not downloaded. Separate with commas.')) ?></p>
    </div>
  </section>

  <!-- Живой предпросмотр -->
  <aside class="xl:col-start-2 xl:row-start-1 xl:row-span-4 xl:sticky xl:top-24 min-w-0">
    <section class="<?= ui('card') ?> overflow-hidden">
      <div class="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-line bg-[#F6F8FC]">
        <h2 class="text-[15px] font-semibold"><?= e(t('Live preview')) ?></h2>
        <span id="pvMeta" class="text-xs text-muted"></span>
      </div>
      <div id="pvBody" class="min-h-[260px]">
        <div id="pvIdle" class="px-6 py-14 text-center">
          <div class="mx-auto h-12 w-12 rounded-full bg-ms-light text-ms flex items-center justify-center"><svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></svg></div>
          <div class="mt-3 text-sm font-semibold"><?= e(t('Nothing fetched yet')) ?></div>
          <div class="mt-1 text-[13px] text-muted"><?= e(t('Paste an address on the left. The crawler fetches it right away and shows the latest articles here.')) ?></div>
        </div>
        <div id="pvLoading" class="hidden">
          <div class="flex items-center gap-3 px-5 py-4 text-sm font-medium text-[#0B4F94]"><span class="h-5 w-5 rounded-full border-2 border-ms-mid border-t-ms animate-spin"></span><span id="pvLoadText"><?= e(t('Fetching…')) ?></span></div>
          <?php for ($i = 0; $i < 5; $i++): ?><div class="px-5 py-3.5 border-t border-line animate-pulse"><div class="h-3.5 rounded bg-line w-[<?= [82, 64, 90, 70, 76][$i] ?>%]"></div><div class="mt-2.5 h-3 rounded bg-line w-1/3"></div></div><?php endfor; ?>
        </div>
        <div id="pvError" class="hidden p-5"></div>
        <div id="pvCands" class="hidden"></div>
        <ul id="pvList" class="hidden"></ul>
      </div>
    </section>
  </aside>

  <details class="xl:col-start-1 min-w-0 group"<?= $id ? ' open' : '' ?>>
    <summary class="cursor-pointer list-none flex items-center gap-2 text-sm font-semibold text-[#0B4F94] mb-5 select-none"><span><?= e(t('More settings')) ?></span><span class="text-xs font-normal text-muted"><?= e(t('Optional. The defaults are fine.')) ?></span></summary>
    <div class="grid gap-5 min-w-0">
  <!-- Список ссылок на странице -->
  <section id="lsBox" class="<?= $card ?> hidden space-y-4 min-w-0 xl:col-start-1">
    <h2 class="text-[15px] font-semibold"><?= e(t('Page list rule')) ?></h2>
    <p class="text-[13px] text-muted"><?= e(t('Found automatically from the page. Change it only if the wrong links are picked. Each link is an article page: it is opened to read the title, date and full text.')) ?></p>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="<?= $lbl ?>" for="list_container"><?= e(t('Block that holds the list (CSS)')) ?></label><input id="list_container" name="list_container" value="<?= e($xs['list_container']) ?>" placeholder="div.allNewsBox" class="<?= $inp ?>"></div>
      <div><label class="<?= $lbl ?>" for="list_pattern"><?= e(t('Article link pattern (regular expression on the path)')) ?></label><input id="list_pattern" name="list_pattern" value="<?= e($xs['list_pattern']) ?>" placeholder="^/article/\d+/" class="<?= $inp ?>"></div>
    </div>
  </section>

  <!-- Sitemap -->
  <section id="smBox" class="<?= $card ?> hidden space-y-4 min-w-0 xl:col-start-1">
    <h2 class="text-[15px] font-semibold"><?= e(t('Sitemap settings')) ?></h2>
    <p class="text-[13px] text-muted"><?= e(t('Use a sitemap index (sitemap_index.xml) or a direct sitemap (post-sitemap89.xml). The crawler reads the newest sitemaps, finds new article URLs and downloads each article page.')) ?></p>
    <div><label class="<?= $lbl ?>" for="urls_extra"><?= e(t('More sitemap URLs (one per line, optional)')) ?></label><textarea id="urls_extra" name="urls_extra" rows="2" class="<?= $inp ?>"><?= e($xs['urls_extra']) ?></textarea></div>
    <label class="flex items-center gap-3 rounded-[10px] border border-line2 px-4 py-2.5 text-sm font-medium cursor-pointer"><input type="checkbox" name="probe_next" value="1" <?= $xs['probe_next'] ? 'checked' : '' ?> class="h-4 w-4 accent-[#0078D4]"><?= e(t('Probe the next number when there is nothing new (post-sitemap89.xml → post-sitemap90.xml)')) ?></label>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="<?= $lbl ?>"><?= e(t('Only sitemaps containing')) ?></label><input name="map_include" value="<?= e($xs['map_include']) ?>" placeholder="post-sitemap" class="<?= $inp ?>"></div>
      <div><label class="<?= $lbl ?>"><?= e(t('Only URLs containing')) ?></label><input name="url_include" value="<?= e($xs['url_include']) ?>" placeholder="/news/" class="<?= $inp ?>"></div>
    </div>
  </section>

  <!-- 2. Что и как часто -->
  <section class="<?= $card ?> space-y-5 min-w-0 xl:col-start-1">
    <h2 class="text-[15px] font-semibold"><?= e(t('2. What to download and how often')) ?></h2>
    <div id="tgHist" class="hidden">
      <label class="<?= $lbl ?>" for="history_pages"><?= e(t('History to download on the first fetch')) ?></label>
      <select id="history_pages" name="history_pages" class="<?= $inp ?> sm:max-w-sm">
        <?php foreach ([1 => 'Last ~20 posts', 5 => 'Last ~100 posts', 25 => 'Last ~500 posts', 50 => 'Last ~1000 posts'] as $v => $l): ?><option value="<?= $v ?>" <?= (int)$xs['history_pages'] === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="grid gap-5 sm:grid-cols-2">
      <div>
        <label class="<?= $lbl ?>" for="check_interval_sec"><?= e(t('Check every')) ?></label>
        <select id="check_interval_sec" name="check_interval_sec" class="<?= $inp ?>"><?php foreach ($intervals as $v => $l): ?><option value="<?= $v ?>" <?= (int)$s['check_interval_sec'] === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select>
      </div>
      <div>
        <label class="<?= $lbl ?>" for="feed_timezone"><?= e(t('Time zone of the feed dates')) ?></label>
        <select id="feed_timezone" name="feed_timezone" class="<?= $inp ?>"><?php foreach ($timezones as $v => $l): ?><option value="<?= e($v) ?>" <?= $s['feed_timezone'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <p class="mt-1.5 text-xs text-muted"><?= e(t('Used only when a date has no time zone in it.')) ?></p>
      </div>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="flex items-start gap-3 rounded-[10px] border border-line2 px-4 py-2.5 text-sm font-medium cursor-pointer"><input type="checkbox" name="interval_auto" value="1" <?= $s['interval_mode'] === 'auto' ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 accent-[#0078D4]"><span><?= e(t('Adjust the interval to the publishing frequency')) ?><span class="block text-xs text-muted font-normal"><?= e(t('Never faster than the interval above, never slower than 15 minutes.')) ?></span></span></label>
      <label class="flex items-center gap-3 rounded-[10px] border border-line2 px-4 py-2.5 text-sm font-medium cursor-pointer"><input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?> class="h-4 w-4 accent-[#0078D4]"><?= e(t('Active (crawl this source)')) ?></label>
    </div>
  </section>

  <!-- 3. Дополнительно -->
  <section class="<?= $card ?> space-y-5 min-w-0 xl:col-start-1">
    <h2 class="text-[15px] font-semibold"><?= e(t('3. Advanced')) ?></h2>
    <div class="grid gap-5 sm:grid-cols-2">
      <div id="dedupWrap">
        <label class="<?= $lbl ?>" for="dedup_by"><?= e(t('Duplicate check (within this source)')) ?></label>
        <select id="dedup_by" name="dedup_by" class="<?= $inp ?>"><?php foreach ($dedupModes as $v => $l): ?><option value="<?= $v ?>" <?= $s['dedup_by'] === $v ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select>
        <p class="mt-1.5 text-xs text-muted"><?= e(t('Always on: this source never stores the same article twice. Other sources may. Changing the method applies to new articles only.')) ?></p>
      </div>
      <div>
        <label class="<?= $lbl ?>" for="fetch_profile"><?= e(t('Request headers')) ?></label>
        <select id="fetch_profile" name="fetch_profile" class="<?= $inp ?>">
          <option value="browser" <?= $s['fetch_profile'] === 'browser' ? 'selected' : '' ?>><?= e(t('Browser-like (recommended)')) ?></option>
          <option value="bot" <?= $s['fetch_profile'] === 'bot' ? 'selected' : '' ?>><?= e(t('Honest bot')) ?></option>
        </select>
      </div>
    </div>
    <div class="grid gap-5 sm:grid-cols-2">
      <div>
        <label class="<?= $lbl ?>" for="fetch_method"><?= e(t('How to fetch')) ?></label>
        <select id="fetch_method" name="fetch_method" class="<?= $inp ?>">
          <option value="auto" <?= $s['fetch_method'] === 'auto' ? 'selected' : '' ?>><?= e(t('Auto: direct request, real browser if the site blocks it')) ?></option>
          <option value="http" <?= $s['fetch_method'] === 'http' ? 'selected' : '' ?>><?= e(t('Direct request only (fastest)')) ?></option>
          <option value="browser" <?= $s['fetch_method'] === 'browser' ? 'selected' : '' ?>><?= e(t('Real browser always (for protected sites)')) ?></option>
        </select>
      </div>
      <div>
        <label class="<?= $lbl ?>" for="proxy_url"><?= e(t('Proxy (optional)')) ?></label>
        <input id="proxy_url" name="proxy_url" value="<?= e((string)$s['proxy_url']) ?>" placeholder="http://user:password@host:port" class="<?= $inp ?>">
        <p class="mt-1.5 text-xs text-muted"><?= e(t('For sites that block the server address. Used by both the direct request and the real browser.')) ?></p>
      </div>
    </div>
    <label class="flex items-start gap-3 rounded-[10px] border border-line2 px-4 py-2.5 text-sm font-medium cursor-pointer"><input type="checkbox" name="respect_robots" value="1" <?= $s['respect_robots'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 accent-[#0078D4]"><span><?= e(t('Respect robots.txt')) ?><span class="block text-xs text-muted font-normal"><?= e(t('Pages the site closed in robots.txt are not opened. The article keeps its title and the reason is written to the log.')) ?></span></span></label>
    <label id="ftWrap" class="flex items-start gap-3 rounded-[10px] border border-line2 px-4 py-2.5 text-sm font-medium cursor-pointer"><input type="checkbox" name="fetch_full_text" value="1" <?= $s['fetch_full_text'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 accent-[#0078D4]"><span><?= e(t('Open article pages to get the full text')) ?><span class="block text-xs text-muted font-normal"><?= e(t('Recommended: many feeds give only a short description. The page text is used when it is longer')) ?></span></span></label>
    <details class="rounded-xl border border-line px-4 py-3">
      <summary class="cursor-pointer text-sm font-semibold"><?= e(t('Article page selectors and extraction test (optional)')) ?></summary>
      <p class="mt-2 text-[13px] text-muted"><?= e(t('Leave empty: title, date and text are detected automatically. If a site is detected wrongly, set CSS selectors (tag, #id, .class, spaces).')) ?></p>
      <div class="mt-3 grid gap-4 sm:grid-cols-2">
        <div><label class="<?= $lbl ?>"><?= e(t('Title selector')) ?></label><input name="sel_title" value="<?= e($xs['sel_title']) ?>" placeholder="h1.article-title" class="<?= $inp ?>"></div>
        <div><label class="<?= $lbl ?>"><?= e(t('Text selector')) ?></label><input name="sel_body" value="<?= e($xs['sel_body']) ?>" placeholder="div.article-body" class="<?= $inp ?>"></div>
      </div>
      <div class="mt-4 border-t border-line pt-4">
        <label class="<?= $lbl ?>" for="pv_url"><?= e(t('Test on an article page')) ?></label>
        <div class="flex flex-col sm:flex-row gap-3">
          <input id="pv_url" type="url" placeholder="https://site.am/news/123" class="<?= $inp ?>">
          <button type="button" id="pv_btn" class="<?= ui('btn2') ?> sm:w-52"><?= e(t('Preview extraction')) ?></button>
        </div>
        <div id="pv_out" class="mt-3 hidden rounded-xl border border-line bg-surface p-3 text-sm"></div>
      </div>
    </details>
  </section>

    </div>
  </details>

  <div class="xl:col-start-1 flex flex-col-reverse sm:flex-row gap-3 sm:justify-end">
    <a href="<?= can('sources.view') ? '/sources.php' : '/' ?>" class="<?= ui('btn2') ?>"><?= e(t('Cancel')) ?></a>
    <button id="saveBtn" class="<?= ui('btn') ?> sm:px-8"><?= e(t($id ? 'Save' : 'Add source')) ?></button>
  </div>
</form>

<div id="busy" class="hidden fixed inset-0 z-50 bg-ink/40 flex items-center justify-center p-6">
  <div class="<?= ui('card') ?> px-8 py-7 text-center max-w-sm"><span class="mx-auto block h-9 w-9 rounded-full border-[3px] border-ms-mid border-t-ms animate-spin"></span>
    <div class="mt-4 text-base font-semibold"><?= e(t($id ? 'Saving…' : 'Saving and fetching the first articles…')) ?></div><div class="mt-1 text-[13px] text-muted"><?= e(t('This can take up to a minute for large feeds.')) ?></div></div>
</div>

<script>
const f = document.getElementById('sf'), $ = id => document.getElementById(id);
const BROWSER_UP = <?= $browserUp ? 'true' : 'false' ?>;
const L = {listing: ['<?= e(t('Address of the page with the list')) ?>', 'https://site.am/timeline'], rss: ['<?= e(t('Feed URL')) ?>', 'https://news.am/rss'], sitemap: ['<?= e(t('Sitemap URL')) ?>', 'https://site.am/sitemap_index.xml'],
           telegram: ['<?= e(t('Telegram channel username')) ?>', '@durov  or  https://t.me/durov'], facebook: ['<?= e(t('Feed URL of the Facebook page')) ?>', 'https://example.com/facebook-page-feed.xml']};
let ctl = null, timer = null, tick = null;
const curType = () => f.querySelector('input[name=type]:checked').value;

function syncType() {
  const v = curType();
  $('smBox').classList.toggle('hidden', v !== 'sitemap');
  $('lsBox').classList.toggle('hidden', v !== 'listing');
  $('tgBox').classList.toggle('hidden', v !== 'telegram');
  $('tgHist').classList.toggle('hidden', v !== 'telegram');
  $('fbBox').classList.toggle('hidden', v !== 'facebook');
  $('urlLabel').textContent = L[v][0];
  $('feed_url').placeholder = L[v][1];
  $('ftWrap').classList.toggle('hidden', v !== 'rss');
  $('dedupWrap').classList.toggle('hidden', v === 'telegram');
  if (v === 'sitemap' || v === 'listing') f.elements.fetch_full_text.checked = true;
}
function show(which) { ['pvIdle', 'pvLoading', 'pvError', 'pvList'].forEach(i => $(i).classList.toggle('hidden', i !== which)); }
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
const hostPath = u => { try { const x = new URL(u); return x.host.replace(/^www\./, '') + x.pathname.replace(/\/$/, ''); } catch (e) { return u; } };

const KIND = {rss: '<?= e(t('RSS feed')) ?>', sitemap: '<?= e(t('Sitemap')) ?>', listing: '<?= e(t('Page list')) ?>', telegram: 'Telegram'};
let cands = [], picked = -1;
function renderCands() {
  let h = '';
  cands.forEach((c, i) => {
    const on = i === picked;
    h += '<button type="button" data-c="' + i + '" class="w-full text-left px-5 py-3.5 border-b border-line flex gap-3 items-start ' + (on ? 'bg-ms-light' : 'hover:bg-surface') + '">'
      + '<span class="mt-1 h-4 w-4 rounded-full border-2 shrink-0 ' + (on ? 'border-ms bg-ms' : 'border-line2') + '"></span>'
      + '<span class="flex-1 min-w-0"><span class="flex flex-wrap items-center gap-2"><span class="text-sm font-semibold">' + esc(KIND[c.kind] || c.label) + '</span>'
      + '<span class="text-xs text-muted">' + c.count + ' <?= e(t('found')) ?></span>' + (c.recommended ? '<span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-ms-light text-[#0B4F94]"><?= e(t('Recommended')) ?></span>' : '') + '</span>'
      + '<span class="block text-xs text-[#0B5CAD] break-all">' + esc(hostPath(c.url)) + '</span>'
      + (c.note ? '<span class="block text-xs text-muted mt-0.5">' + esc(c.note) + '</span>' : '') + '</span></button>';
  });
  $('pvCands').innerHTML = h; $('pvCands').classList.remove('hidden');
  $('pvCands').querySelectorAll('[data-c]').forEach(b => b.onclick = () => pick(+b.dataset.c));
}
function renderItems(c) {
  let h = '';
  c.items.forEach(i => h += '<li class="px-5 py-3 border-b border-line last:border-0"><div class="text-sm font-semibold leading-snug">' + esc(i.title || hostPath(i.url)) + '</div>'
    + '<div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted">' + (i.date ? '<span>' + esc(i.date) + '</span>' : '') + '<span class="text-[#0B5CAD] break-all">' + esc(hostPath(i.url)) + '</span>' + (i.chars !== null && i.chars !== undefined ? '<span>' + i.chars + ' <?= e(t('chars')) ?></span>' : '') + '</div></li>');
  $('pvList').innerHTML = h; show('pvList'); $('pvCands').classList.remove('hidden');
}
function pick(i) {
  picked = i; const c = cands[i];
  f.querySelector('input[name=type][value=' + c.kind + ']').checked = true;
  $('feed_url').value = c.kind === 'telegram' ? '@' + c.url.split('/').pop() : c.url;
  f.elements.list_container.value = (c.config || {}).list_container || '';
  f.elements.list_pattern.value = (c.config || {}).list_pattern || '';
  syncType(); renderCands(); renderItems(c);
  if (!$('name').value.trim()) $('name').value = c.kind === 'telegram' ? $('feed_url').value : hostPath(c.url).split('/')[0];
}
async function probe() {
  const url = $('feed_url').value.trim();
  if (!url) { show('pvIdle'); $('pvCands').classList.add('hidden'); $('pvMeta').textContent = ''; $('urlSpin').classList.add('hidden'); $('urlOk').classList.add('hidden'); return; }
  if (ctl) ctl.abort(); ctl = new AbortController();
  show('pvLoading'); $('pvCands').classList.add('hidden'); $('urlSpin').classList.remove('hidden'); $('urlOk').classList.add('hidden'); $('pvMeta').textContent = '';
  let sec = 0; clearInterval(tick); $('pvLoadText').textContent = '<?= e(t('Checking the address: feed, sitemap, page list…')) ?>';
  tick = setInterval(() => { sec++; $('pvLoadText').textContent = '<?= e(t('Checking the address: feed, sitemap, page list…')) ?> ' + sec + ' s'; }, 1000);
  const fd = new FormData();
  ['csrf', 'feed_url', 'fetch_profile', 'fetch_method', 'feed_timezone'].forEach(k => { const el = f.elements[k]; if (el) fd.append(k, el.value); });
  try {
    const r = await (await fetch('/sourceprobe.php', { method: 'POST', body: fd, signal: ctl.signal })).json();
    clearInterval(tick); $('urlSpin').classList.add('hidden');
    if (r.idle) { show('pvIdle'); return; }
    if (!r.ok) {
      let h = '<div class="rounded-xl border border-[#E2A9AE] bg-dangerbg px-4 py-3 text-sm font-medium text-danger">' + esc(r.error) + '</div>';
      if (r.can_try_browser && BROWSER_UP) h += '<button type="button" id="tryBrowser" class="<?= ui('btn') ?> mt-3 !h-9"><?= e(t('Try with the real browser')) ?></button>';
      $('pvError').innerHTML = h; show('pvError'); $('pvMeta').textContent = ((r.ms || 0) / 1000).toFixed(1) + ' s';
      const tb = $('tryBrowser'); if (tb) tb.onclick = () => { f.elements.fetch_method.value = 'browser'; probe(); };
      return;
    }
    cands = r.candidates; $('urlOk').classList.remove('hidden');
    $('pvMeta').textContent = cands.length + ' <?= e(t('ways found')) ?> · ' + (r.via === 'browser' ? '<?= e(t('real browser')) ?>' : '<?= e(t('direct')) ?>') + ' · ' + (r.ms / 1000).toFixed(1) + ' s';
    const manual = curType(); let idx = cands.findIndex(c => c.kind === manual && c.recommended); if (idx < 0) idx = cands.findIndex(c => c.recommended); if (idx < 0) idx = 0;
    pick(idx);
  } catch (e) { if (e.name !== 'AbortError') { clearInterval(tick); $('urlSpin').classList.add('hidden'); $('pvError').innerHTML = '<div class="rounded-xl border border-[#E2A9AE] bg-dangerbg px-4 py-3 text-sm text-danger"><?= e(t('Preview request failed. Reload the page and try again.')) ?></div>'; show('pvError'); } }
}
const later = () => { clearTimeout(timer); timer = setTimeout(probe, 800); };
$('feed_url').addEventListener('input', later);
$('feed_url').addEventListener('paste', () => setTimeout(probe, 50));
f.querySelectorAll('input[name=type]').forEach(r => r.onchange = () => { const i = cands.findIndex(c => c.kind === curType()); if (i >= 0) pick(i); else syncType(); });
f.elements.feed_timezone.onchange = later; f.elements.fetch_profile.onchange = later; f.elements.fetch_method.onchange = later;
f.querySelectorAll('input[name=start_mode]').forEach(r => r.onchange = () => $('dateWrap').classList.toggle('hidden', f.querySelector('input[name=start_mode]:checked').value !== 'date'));
f.onsubmit = () => { $('busy').classList.remove('hidden'); $('saveBtn').disabled = true; };
$('pv_btn').onclick = async () => {
  const out = $('pv_out'), fd = new FormData();
  ['csrf', 'sel_title', 'sel_body', 'fetch_profile', 'feed_timezone'].forEach(k => fd.append(k, f.elements[k].value));
  fd.append('url', $('pv_url').value); out.classList.remove('hidden'); out.textContent = '…';
  try {
    const r = await (await fetch('/sourcepreview.php', { method: 'POST', body: fd })).json();
    out.innerHTML = r.ok ? '<div class="font-semibold">' + r.title + '</div><div class="text-xs text-muted mt-0.5">' + r.date + ' · ' + r.length + ' chars</div><div class="mt-2 whitespace-pre-line">' + r.text + '</div>' : '<span class="font-semibold text-danger">' + r.error + '</span>';
  } catch (e) { out.textContent = 'Request failed'; }
};
syncType();
<?php if ($id || $s['feed_url'] !== ''): ?>probe();<?php endif; ?>
</script>
<?php app_end();
