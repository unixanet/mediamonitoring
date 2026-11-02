<?php
function page_head(string $title): void
{ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { theme: { extend: {
  colors: {
    ms: { DEFAULT: '#0078D4', dark: '#005A9E', light: '#E8F2FC', mid: '#B3D7F5' },
    ink: '#0F1B2D', muted: '#3C4C63', line: '#E1E7F0', line2: '#CBD5E3', surface: '#F3F6FA',
    danger: '#8E1F25', dangerbg: '#FDE7E9', warn: '#6B4E00', warnbg: '#FFF4CE'
  },
  fontFamily: { sans: ['Inter', 'Noto Sans Armenian', 'Segoe UI', 'system-ui', 'sans-serif'] }
} } };
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Armenian:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<?php }

// Секции меню: [заголовок, [[ключ, ссылка, подпись, право, иконка, бейдж?]]]
function nav_sections(): array
{
    $ic = [
        'home' => 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10', 'list' => 'M4 6h16M4 12h16M4 18h10', 'tag' => 'M20 12l-8 8-9-9V3h8zM7.5 7.5h.01',
        'doc' => 'M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h7', 'pulse' => 'M3 12h4l3-8 4 16 3-8h4', 'log' => 'M5 4h14v16H5zM9 9h6M9 13h6M9 17h3',
        'users' => 'M17 20v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2M10 10a4 4 0 100-8 4 4 0 000 8z', 'search' => 'M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z',
        'plus' => 'M12 5v14M5 12h14',
    ];
    $editor = !can('sources.view');
    return [
        ['Overview', [['dashboard', '/', 'Dashboard', 'dashboard', $ic['home']]]],
        ['Content', array_values(array_filter([
            ['sources', '/sources.php', 'Sources', 'sources.view', $ic['list']],
            ['categories', '/categories.php', 'Categories', 'categories.view', $ic['tag']],
            ['articles', '/articles.php', 'Articles', 'articles.view', $ic['doc']],
            $editor ? ['source_add', '/sourceedit.php', 'Add source', 'source.add', $ic['plus']] : null,
            $editor ? ['search', '/search.php', 'Search', 'search', $ic['search']] : null,
        ]))],
        ['Operations', [['health', '/health.php', 'Engine', 'logs.view', $ic['pulse']], ['logs', '/logs.php', 'Logs', 'logs.view', $ic['log'], true]]],
        ['Settings', [['users', '/users.php', 'Users', 'users.view', $ic['users']]]],
    ];
}

function nav_link(string $href, string $label, bool $on, string $icon, string $badge = ''): string
{
    $cls = 'flex items-center gap-3 h-10 px-3 rounded-lg text-sm font-medium transition ' . ($on ? 'bg-ms-light text-[#0B4F94]' : 'text-[#26364D] hover:bg-surface');
    $svg = '<svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="' . e($icon) . '"/></svg>';
    return '<a href="' . e($href) . '" class="' . $cls . '">' . $svg . '<span class="flex-1">' . e(t($label)) . '</span>'
        . ($badge !== '' ? '<span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-dangerbg text-danger">' . e($badge) . '</span>' : '') . '</a>';
}

// Состояние браузерного сервиса (кэш на 20 секунд в сессии)
function browser_status(): ?array
{
    $c = $_SESSION['bstat'] ?? null;
    if ($c && time() - $c[0] < 20) {
        return $c[1];
    }
    $ch = curl_init(ENGINE_API . '/health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_CONNECTTIMEOUT => 1]);
    $r = curl_exec($ch);
    $j = $r ? json_decode($r, true) : null;
    $_SESSION['bstat'] = [time(), $j];
    return $j ?: null;
}

function status_pill(): string
{
    $c = crawler_status(cdb());
    if (!$c['online']) {
        return '<a href="/health.php" class="hidden sm:flex items-center gap-2 h-8 px-3 rounded-full bg-dangerbg text-danger text-[13px] font-semibold"><span class="h-2 w-2 rounded-full bg-danger"></span>' . e(t('Crawler offline')) . '</a>';
    }
    return '<a href="/health.php" class="hidden sm:flex items-center gap-2 h-8 px-3 rounded-full bg-ms-light text-[#0B4F94] text-[13px] font-semibold"><span class="h-2 w-2 rounded-full bg-ms"></span>'
        . e(t('Engine running')) . '</a>';
}

function app_start(string $title, string $active, array $user): void
{
    page_head($title . ': Media Monitoring Admin');
    $initial = e(strtoupper(mb_substr($user['username'], 0, 1)));
    $errs = 0;
    if (can('logs.view') && ($r = cdb()->query("SELECT COUNT(*) FROM crawl_log WHERE level='error' AND created_at > NOW() - INTERVAL 24 HOUR"))) {
        $errs = (int)$r->fetch_row()[0];
    }
    ?>
<body class="min-h-screen bg-surface text-ink font-sans antialiased">
<div class="min-h-screen lg:flex">
  <div id="overlay" class="hidden fixed inset-0 z-30 bg-ink/50 lg:hidden"></div>

  <aside id="sidebar" class="fixed lg:sticky top-0 z-40 h-screen w-[248px] shrink-0 -translate-x-full lg:translate-x-0 transition-transform bg-white border-r border-line flex flex-col px-3.5 py-5">
    <div class="flex items-center gap-2.5 px-2.5 pb-4">
      <div class="h-[34px] w-[34px] rounded-[9px] bg-ms text-white flex items-center justify-center text-base font-bold">M</div>
      <div class="leading-tight"><div class="text-[15px] font-bold">Media Monitoring</div><div class="text-xs text-muted">Admin console</div></div>
    </div>
    <nav class="flex-1 overflow-y-auto space-y-0.5">
      <?php foreach (nav_sections() as [$head, $items]):
        $items = array_values(array_filter($items, fn($i) => can($i[3])));
        if (!$items) continue; ?>
        <div class="px-3 pt-4 pb-1.5 text-[11px] font-semibold uppercase tracking-[.08em] text-muted"><?= e(t($head)) ?></div>
        <?php foreach ($items as $i) echo nav_link($i[1], $i[2], $i[0] === $active, $i[4], (!empty($i[5]) && $errs) ? (string)min($errs, 99) : ''); ?>
      <?php endforeach; ?>
    </nav>
    <a href="/profile.php" class="mt-3 flex items-center gap-2.5 p-3 rounded-xl border <?= $active === 'profile' ? 'border-ms bg-ms-light' : 'border-line hover:border-line2' ?> transition">
      <span class="h-[34px] w-[34px] rounded-full bg-ms text-white flex items-center justify-center text-sm font-semibold"><?= $initial ?></span>
      <span class="leading-tight min-w-0 flex-1"><span class="block text-sm font-semibold truncate"><?= e($user['username']) ?></span><span class="block text-xs text-muted"><?= e(t(ucfirst($user['role']))) ?></span></span>
    </a>
    <a href="/logout.php" class="mt-2 text-center h-9 leading-9 rounded-[10px] border border-line2 text-[13px] font-semibold hover:bg-surface transition"><?= e(t('Sign out')) ?></a>
  </aside>

  <div class="flex-1 min-w-0 flex flex-col pb-[72px] lg:pb-0">
    <header class="sticky top-0 z-20 bg-white border-b border-line flex items-center gap-3 sm:gap-4 h-16 px-4 sm:px-8">
      <button id="menuBtn" aria-label="Menu" class="lg:hidden h-11 w-11 shrink-0 rounded-xl border border-line2 bg-white flex items-center justify-center">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <?php if (can('search')): ?>
      <form action="/search.php" method="get" class="flex-1 max-w-[520px] relative">
        <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 h-[18px] w-[18px] text-muted" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z"/></svg>
        <input id="gsearch" name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="<?= e(t('Search articles…')) ?>" class="w-full h-10 rounded-[10px] border border-line2 bg-[#F8FAFD] pl-10 pr-14 text-sm text-ink placeholder:text-muted/70 focus:outline-none focus:border-ms focus:bg-white focus:ring-4 focus:ring-ms-light">
        <span class="hidden sm:block absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted border border-line2 rounded-md px-1.5 py-0.5 bg-white">Ctrl K</span>
      </form>
      <?php endif; ?>
      <div class="flex-1"></div>
      <?php if (can('logs.view')) echo status_pill(); ?>
      <?php if (can('source.add') && can('sources.view')): ?>
        <a href="/sourceedit.php" class="<?= ui('btn') ?> hidden sm:inline-flex"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><?= e(t('Add source')) ?></a>
      <?php endif; ?>
    </header>
    <main class="flex-1 px-4 sm:px-8 py-6 sm:py-7">
    <?php if ($f = flash_get()): $ok = $f[0] === 'ok'; ?>
      <div class="mb-5 rounded-xl border px-4 py-3 text-sm font-medium <?= $ok ? 'border-ms-mid bg-ms-light text-[#0B4F94]' : 'border-[#E2A9AE] bg-dangerbg text-danger' ?>"><?= e(t($f[1])) ?></div>
    <?php endif; ?>
<?php }

function app_end(): void
{ ?>
    </main>
  </div>
</div>

<?php // нижняя панель на телефоне
$tabs = [['/', 'Home', 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10', 'dashboard'], ['/sources.php', 'Sources', 'M4 6h16M4 12h16M4 18h10', 'sources.view'],
         ['/sourceedit.php', 'Add', 'M12 5v14M5 12h14', 'source.add'], ['/search.php', 'Search', 'M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z', 'search'],
         ['/health.php', 'Engine', 'M3 12h4l3-8 4 16 3-8h4', 'logs.view']];
?>
<nav aria-label="Primary" class="lg:hidden fixed bottom-0 inset-x-0 z-20 bg-white border-t border-line flex px-1.5 pt-1.5 pb-2.5">
  <?php foreach ($tabs as [$href, $label, $icon, $perm]): if (!can($perm)) continue; if ($label === 'Add' && can('sources.view')) continue;
    $on = ($_SERVER['SCRIPT_NAME'] === $href) || ($href === '/' && $_SERVER['SCRIPT_NAME'] === '/index.php'); ?>
    <a href="<?= e($href) ?>" class="flex-1 flex flex-col items-center gap-0.5 py-1.5 text-[11px] font-semibold <?= $on ? 'text-[#0B4F94]' : 'text-muted' ?>">
      <svg class="h-[22px] w-[22px]" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="<?= e($icon) ?>"/></svg><?= e(t($label)) ?>
    </a>
  <?php endforeach; ?>
  <button id="moreBtn" class="flex-1 flex flex-col items-center gap-0.5 py-1.5 text-[11px] font-semibold text-muted">
    <svg class="h-[22px] w-[22px]" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h.01M12 12h.01M19 12h.01"/></svg><?= e(t('More')) ?>
  </button>
</nav>
<script>
const sb = document.getElementById('sidebar'), ov = document.getElementById('overlay');
function tog(o) { sb.classList.toggle('-translate-x-full', !o); ov.classList.toggle('hidden', !o); }
document.getElementById('menuBtn').onclick = () => tog(true);
document.getElementById('moreBtn').onclick = () => tog(true);
ov.onclick = () => tog(false);
document.addEventListener('keydown', e => { const g = document.getElementById('gsearch'); if (g && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); g.focus(); g.select(); } });
</script>
</body>
</html>
<?php }

function page_title(string $title, string $actions = '', string $sub = ''): void
{
    echo '<div class="mb-6 flex flex-wrap items-center justify-between gap-3"><div class="min-w-0"><h1 class="' . ui('h1') . '">' . e($title) . '</h1>'
        . ($sub !== '' ? '<div class="mt-1 text-sm text-muted">' . e($sub) . '</div>' : '') . '</div>' . ($actions ? '<div class="flex flex-wrap gap-2">' . $actions . '</div>' : '') . '</div>';
}

function forbidden(array $user): void
{
    http_response_code(403);
    app_start('Forbidden', '', $user);
    echo '<div class="' . ui('card') . ' p-8 text-center font-semibold">' . e(t('You do not have permission to open this page.')) . '</div>';
    app_end();
    exit;
}

// Красная плашка только если опрос лент остановлен (обычный статус виден в шапке)
function crawler_banner(): void
{
    $c = crawler_status(cdb());
    if (!$c['online']) {
        echo '<div class="mb-5 rounded-xl border border-[#E2A9AE] bg-dangerbg text-danger px-4 py-3 text-sm font-semibold">'
            . e(t('Crawler is offline. Scheduled fetching is stopped. “Fetch now” still works. Start it on the server: sudo systemctl start mmengine')) . '</div>';
    }
}
