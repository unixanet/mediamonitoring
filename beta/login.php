<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';

if (current_user()) {
    header('Location: /');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_ok()) {
        $error = 'Session expired, please reload the page.';
    } elseif (too_many_attempts($ip)) {
        $error = 'Too many attempts. Try again later.';
    } else {
        $st = db()->prepare('SELECT id, username, password_hash, role FROM admin_users WHERE username=? AND is_active=1 LIMIT 1');
        $st->bind_param('s', $username);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if ($row && password_verify($password, $row['password_hash'])) {
            log_attempt($ip, $username, true);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => $row['username'], 'role' => $row['role']];
            db()->query('UPDATE admin_users SET last_login_at = NOW() WHERE id = ' . (int)$row['id']);
            header('Location: /');
            exit;
        }
        log_attempt($ip, $username, false);
        $error = 'Wrong username or password.';
    }
}
page_head('Sign in — Media Monitoring Admin');
?>
<body class="min-h-screen bg-white text-black">
<div class="min-h-screen lg:grid lg:grid-cols-2">

  <section class="bg-ms text-white px-6 py-10 sm:px-12 lg:py-0 lg:flex lg:flex-col lg:justify-center relative overflow-hidden">
    <div class="absolute -right-16 -bottom-16 h-64 w-64 rounded-full bg-accent"></div>
    <div class="relative flex items-center gap-3">
      <div class="h-12 w-12 rounded-xl bg-white text-ms flex items-center justify-center text-2xl font-extrabold">M</div>
      <span class="text-2xl font-extrabold">Media Monitoring</span>
    </div>
    <h2 class="relative mt-8 lg:mt-14 text-3xl sm:text-4xl font-extrabold leading-tight max-w-md">Every Armenian news source. One place.</h2>
    <p class="relative mt-4 text-lg font-medium text-white max-w-md">Manage sources, users and the crawler.</p>
  </section>

  <main class="flex items-center justify-center px-6 py-10 sm:px-12">
    <div class="w-full max-w-md">
      <h1 class="text-3xl font-extrabold text-black"><?= e(t('Sign in')) ?></h1>
      <div class="mt-2 h-1.5 w-16 rounded-full bg-accent"></div>

      <?php if ($error): ?>
        <div class="mt-6 rounded-xl border-2 border-red-600 bg-red-50 text-red-900 font-semibold px-4 py-3"><?= e(t($error)) ?></div>
      <?php endif; ?>

      <form method="post" class="mt-8 space-y-5" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <div>
          <label for="username" class="block font-bold text-black mb-1.5"><?= e(t('Username')) ?></label>
          <input id="username" name="username" type="text" required autofocus autocomplete="username"
                 value="<?= e((string)($_POST['username'] ?? '')) ?>"
                 class="w-full rounded-xl border-2 border-ms px-4 py-3 text-black bg-white focus:outline-none focus:ring-4 focus:ring-ms-mid">
        </div>
        <div>
          <label for="password" class="block font-bold text-black mb-1.5"><?= e(t('Password')) ?></label>
          <input id="password" name="password" type="password" required autocomplete="current-password"
                 class="w-full rounded-xl border-2 border-ms px-4 py-3 text-black bg-white focus:outline-none focus:ring-4 focus:ring-ms-mid">
        </div>
        <button type="submit" class="w-full rounded-xl bg-accent hover:bg-accent-dark text-white text-lg font-bold py-3.5 transition focus:outline-none focus:ring-4 focus:ring-orange-300">
          <?= e(t('Sign in')) ?>
        </button>
      </form>
    </div>
  </main>
</div>
</body>
</html>
