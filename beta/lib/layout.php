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
tailwind.config = { theme: { extend: { colors: {
  ms: { DEFAULT: '#0078D4', dark: '#005A9E', light: '#EAF4FD', mid: '#B3D7F5' }
}, fontFamily: { sans: ['Inter', 'Segoe UI', 'system-ui', 'sans-serif'] } } } };
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<?php }

// [ключ => [ссылка, подпись, право, иконка(path)]] ; группа Sources с подменю
function nav_tree(): array
{
    return [
        ['dashboard', '/', 'Dashboard', 'dashboard', 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10'],
        ['group', 'Sources', 'M4 6h16M4 12h16M4 18h10', [
            ['sources', '/sources.php', 'All sources', 'sources.view'],
            ['categories', '/categories.php', 'Categories', 'categories.view'],
            ['source_add', '/source_edit.php', 'Add source', 'source.add'],
        ]],
        ['search', '/search.php', 'Search', 'search', 'M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z'],
        ['users', '/users.php', 'Users', 'users.view', 'M17 20v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2M10 10a4 4 0 100-8 4 4 0 000 8zM21 20v-2a4 4 0 00-3-3.9'],
    ];
}

function nav_link(string $href, string $label, bool $on, string $icon = '', bool $sub = false): string
{
    $cls = 'flex items-center gap-3 rounded-lg px-3.5 py-2.5 text-sm font-semibold transition '
        . ($sub ? 'ml-5 ' : '') . ($on ? 'bg-ms text-white border border-black shadow-[0_2px_0_0_#000]' : 'text-black border border-transparent hover:bg-ms-light');
    $svg = $icon ? '<svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="' . e($icon) . '"/></svg>' : '<span class="h-1.5 w-1.5 rounded-full bg-current"></span>';
    return '<a href="' . e($href) . '" class="' . $cls . '">' . $svg . e(t($label)) . '</a>';
}

function app_start(string $title, string $active, array $user): void
{
    page_head($title . ' — Media Monitoring Admin');
    $initial = e(strtoupper(mb_substr($user['username'], 0, 1)));
    ?>
<body class="min-h-screen bg-ms-light/50 text-black font-sans antialiased">
<div class="min-h-screen lg:flex">
  <div id="overlay" class="hidden fixed inset-0 z-30 bg-black/50 lg:hidden"></div>

  <aside id="sidebar" class="fixed lg:sticky top-0 z-40 h-screen w-72 shrink-0 -translate-x-full lg:translate-x-0 transition-transform bg-white border-r border-ms-mid shadow-[6px_0_28px_-14px_rgba(0,120,212,.35)] flex flex-col">
    <div class="px-5 py-5 flex items-center gap-3 border-b border-black/10">
      <div class="h-10 w-10 rounded-xl bg-ms text-white border border-black shadow-[0_2px_0_0_#000] flex items-center justify-center text-lg font-bold">M</div>
      <div class="leading-tight">
        <div class="font-bold">Media Monitoring</div>
        <div class="text-xs font-semibold text-ms">Admin · beta</div>
      </div>
    </div>
    <nav class="flex-1 overflow-y-auto p-3 space-y-1">
      <?php foreach (nav_tree() as $n):
        if ($n[0] === 'group') {
            $kids = array_filter($n[3], fn($k) => can($k[3]));
            if (!$kids) continue;
            $hasActive = (int)in_array($active, array_column($kids, 0), true);
            echo '<div data-group="' . e(strtolower($n[1])) . '" data-active="' . $hasActive . '">';
            echo '<button type="button" aria-expanded="false" class="grp flex w-full items-center justify-between gap-3 rounded-lg border border-transparent px-3.5 py-2.5 text-sm font-semibold text-black hover:bg-ms-light transition">'
                . '<span class="flex items-center gap-3"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="' . e($n[2]) . '"/></svg>' . e(t($n[1])) . '</span>'
                . '<span class="grp-ico w-5 text-center text-lg font-bold leading-none text-ms">+</span></button>';
            echo '<div class="grp-body hidden mt-1 space-y-1">';
            foreach ($kids as $k) echo nav_link($k[1], $k[2], $k[0] === $active, '', true);
            echo '</div></div>';
            continue;
        }
        if (!can($n[3])) continue;
        echo nav_link($n[1], $n[2], $n[0] === $active, $n[4]);
      endforeach; ?>
    </nav>
    <div class="p-3 border-t border-black/10">
      <a href="/profile.php" class="flex items-center gap-3 rounded-xl border <?= $active === 'profile' ? 'border-black bg-ms-light' : 'border-black/10 hover:border-black hover:bg-ms-light' ?> p-3 transition">
        <span class="h-10 w-10 rounded-full bg-ms text-white border border-black flex items-center justify-center font-bold"><?= $initial ?></span>
        <span class="leading-tight min-w-0">
          <span class="block font-bold truncate"><?= e($user['username']) ?></span>
          <span class="block text-xs font-semibold text-ms-dark"><?= e(t(ucfirst($user['role']))) ?> · <?= e(t('Profile')) ?></span>
        </span>
      </a>
      <a href="/logout.php" class="mt-2 block text-center rounded-lg border border-black text-sm font-semibold py-2 hover:bg-black hover:text-white transition"><?= e(t('Sign out')) ?></a>
    </div>
  </aside>

  <div class="flex-1 min-w-0 flex flex-col">
    <header class="sticky top-0 z-20 bg-white border-b border-ms-mid shadow-[0_10px_28px_-18px_rgba(0,120,212,.45)] flex items-center gap-3 px-4 sm:px-8 py-3">
      <button id="menuBtn" aria-label="Menu" class="lg:hidden h-10 w-10 shrink-0 rounded-lg border border-black bg-ms text-white shadow-[0_2px_0_0_#000] flex items-center justify-center">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <?php if (can('search')): ?>
      <form action="/search.php" method="get" class="flex-1 max-w-xl relative">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-ms" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z"/></svg>
        <input name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="<?= e(t('Search articles…')) ?>" class="<?= ui('inp') ?> pl-10">
      </form>
      <?php endif; ?>
      <a href="/profile.php" class="ml-auto hidden sm:flex items-center gap-2 font-semibold hover:text-ms">
        <span class="h-9 w-9 rounded-full bg-ms text-white border border-black flex items-center justify-center font-bold"><?= $initial ?></span>
      </a>
    </header>
    <main class="flex-1 px-4 sm:px-8 py-6 sm:py-8">
    <?php if ($f = flash_get()): $ok = $f[0] === 'ok'; ?>
      <div class="mb-6 rounded-xl border px-4 py-3 text-sm font-semibold <?= $ok ? 'border-black bg-ms-light' : 'border-red-700 bg-red-50 text-red-900' ?>"><?= e(t($f[1])) ?></div>
    <?php endif; ?>
<?php }

function app_end(): void
{ ?>
    </main>
  </div>
</div>
<script>
const sb = document.getElementById('sidebar'), ov = document.getElementById('overlay');
function tog(o) { sb.classList.toggle('-translate-x-full', !o); ov.classList.toggle('hidden', !o); }
document.getElementById('menuBtn').onclick = () => tog(true);
ov.onclick = () => tog(false);
document.querySelectorAll('[data-group]').forEach(g => {
  const id = 'nav:' + g.dataset.group, btn = g.querySelector('.grp'), body = g.querySelector('.grp-body'), ico = g.querySelector('.grp-ico');
  let open = g.dataset.active === '1';
  try { const v = localStorage.getItem(id); if (v !== null && !open) open = v === '1'; } catch (e) {}
  const apply = () => { body.classList.toggle('hidden', !open); ico.textContent = open ? '−' : '+'; btn.setAttribute('aria-expanded', open); };
  btn.onclick = () => { open = !open; try { localStorage.setItem(id, open ? '1' : '0'); } catch (e) {} apply(); };
  apply();
});
</script>
</body>
</html>
<?php }

function page_title(string $title, string $actions = ''): void
{
    echo '<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3"><h1 class="' . ui('h1') . '">' . e($title) . '</h1>' . ($actions ? '<div class="flex flex-wrap gap-2">' . $actions . '</div>' : '') . '</div>';
}

function forbidden(array $user): void
{
    http_response_code(403);
    app_start('Forbidden', '', $user);
    echo '<div class="' . ui('card') . ' p-8 text-center font-semibold">' . e(t('You do not have permission to open this page.')) . '</div>';
    app_end();
    exit;
}
