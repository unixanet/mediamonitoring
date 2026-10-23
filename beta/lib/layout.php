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
  ms: { DEFAULT: '#0078D4', dark: '#005A9E', light: '#EAF4FD', mid: '#B3D7F5' },
  accent: { DEFAULT: '#E05A00', dark: '#B84A00', light: '#FFF0E3' }
} } } };
</script>
</head>
<?php }

function nav_items(): array
{
    return [
        'dashboard' => ['/', 'Dashboard', 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10'],
        'sources'   => ['/sources.php', 'Sources', 'M4 6h16M4 12h16M4 18h10'],
    ];
}

function app_start(string $title, string $active, array $user): void
{
    page_head($title . ' — Media Monitoring Admin'); ?>
<body class="min-h-screen bg-white text-black">
<div class="min-h-screen lg:flex">

  <!-- Mobile top bar -->
  <header class="lg:hidden sticky top-0 z-30 bg-white border-b-4 border-accent flex items-center justify-between px-4 py-3">
    <button id="menuBtn" aria-label="Menu" class="h-10 w-10 rounded-lg bg-ms text-white flex items-center justify-center">
      <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <span class="font-extrabold text-black">Media Monitoring</span>
    <span class="h-10 w-10 rounded-full bg-accent text-white flex items-center justify-center font-bold"><?= e(strtoupper(substr($user['username'], 0, 1))) ?></span>
  </header>

  <div id="overlay" class="hidden fixed inset-0 z-30 bg-black/50 lg:hidden"></div>

  <!-- Sidebar -->
  <aside id="sidebar" class="fixed lg:sticky top-0 z-40 h-screen w-72 shrink-0 -translate-x-full lg:translate-x-0 transition-transform bg-white border-r-2 border-ms-mid flex flex-col">
    <div class="px-6 py-6 flex items-center gap-3 border-b-4 border-accent">
      <div class="h-11 w-11 rounded-xl bg-ms text-white flex items-center justify-center text-xl font-extrabold">M</div>
      <div class="leading-tight">
        <div class="font-extrabold text-black">Media Monitoring</div>
        <div class="text-sm font-semibold text-ms">Admin · beta</div>
      </div>
    </div>
    <nav class="flex-1 p-4 space-y-1.5">
      <?php foreach (nav_items() as $key => [$href, $label, $icon]): $on = $key === $active; ?>
        <a href="<?= e($href) ?>" class="flex items-center gap-3 rounded-xl px-4 py-3 font-semibold transition <?= $on ? 'bg-ms text-white' : 'text-black hover:bg-ms-light' ?>">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="<?= e($icon) ?>"/></svg>
          <?= e(t($label)) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="p-4 border-t-2 border-ms-mid">
      <div class="flex items-center gap-3 mb-3">
        <span class="h-10 w-10 rounded-full bg-accent text-white flex items-center justify-center font-bold"><?= e(strtoupper(substr($user['username'], 0, 1))) ?></span>
        <div class="leading-tight">
          <div class="font-bold text-black"><?= e($user['username']) ?></div>
          <div class="text-sm font-medium text-ms-dark"><?= e($user['role']) ?></div>
        </div>
      </div>
      <a href="/logout.php" class="block text-center rounded-xl border-2 border-black text-black font-semibold py-2 hover:bg-black hover:text-white transition"><?= e(t('Sign out')) ?></a>
    </div>
  </aside>

  <main class="flex-1 min-w-0 px-4 sm:px-8 py-6 sm:py-10 bg-white">
    <?php if ($f = flash_get()): $ok = $f[0] === 'ok'; ?>
      <div class="mb-6 rounded-xl border-2 px-4 py-3 font-semibold <?= $ok ? 'border-ms bg-ms-light text-black' : 'border-red-600 bg-red-50 text-red-900' ?>"><?= e($f[1]) ?></div>
    <?php endif; ?>
<?php }

function app_end(): void
{ ?>
  </main>
</div>
<script>
const sb = document.getElementById('sidebar'), ov = document.getElementById('overlay');
function tog(open) { sb.classList.toggle('-translate-x-full', !open); ov.classList.toggle('hidden', !open); }
document.getElementById('menuBtn').onclick = () => tog(true);
ov.onclick = () => tog(false);
</script>
</body>
</html>
<?php }
