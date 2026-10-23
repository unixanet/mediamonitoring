<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_login();
page_head('Dashboard — Media Monitoring Admin');
?>
<body class="min-h-screen bg-indigo-50">
  <header class="bg-indigo-950">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="h-9 w-9 rounded-lg bg-indigo-500 text-white flex items-center justify-center font-bold">M</div>
        <span class="hidden sm:inline text-white font-bold">Media Monitoring Admin</span>
      </div>
      <div class="flex items-center gap-3">
        <span class="text-white text-sm sm:text-base">Welcome, <b class="text-indigo-200"><?= e($user['username']) ?></b></span>
        <a href="/logout.php" class="rounded-lg bg-white text-indigo-950 text-sm font-semibold px-3 py-1.5 hover:bg-indigo-100 transition">Выйти</a>
      </div>
    </div>
  </header>
  <main class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <h1 class="text-2xl sm:text-3xl font-bold text-indigo-950">Welcome, <?= e($user['username']) ?></h1>
  </main>
</body>
</html>
