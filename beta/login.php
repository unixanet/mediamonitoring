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
<body class="min-h-screen bg-white text-black font-sans antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-2">

  <section class="bg-ms text-white px-6 py-10 sm:px-12 lg:py-0 lg:flex lg:flex-col lg:justify-center relative overflow-hidden">
    <div class="absolute -right-24 -top-24 h-72 w-72 rounded-full border border-white/40"></div>
    <div class="absolute -right-10 -top-10 h-48 w-48 rounded-full border border-white/40"></div>
    <div class="absolute -left-20 -bottom-20 h-64 w-64 rounded-full border border-white/40"></div>
    <div class="relative flex items-center gap-3">
      <div class="h-12 w-12 rounded-xl bg-white text-ms border border-black shadow-[0_3px_0_0_#000] flex items-center justify-center text-2xl font-bold">M</div>
      <span class="text-2xl font-bold">Media Monitoring</span>
    </div>
    <h2 class="relative mt-8 lg:mt-14 text-3xl sm:text-4xl font-bold leading-tight max-w-md">Every Armenian news source. One place.</h2>
    <p class="relative mt-4 text-lg font-medium text-white max-w-md">Manage sources, users and the crawler.</p>
  </section>

  <main class="flex items-center justify-center px-5 py-10 sm:px-12 bg-ms-light/50">
    <div class="w-full max-w-md <?= ui('card') ?> p-6 sm:p-9">
      <h1 class="text-3xl font-bold"><?= e(t('Sign in')) ?></h1>
      <p class="mt-1 font-medium text-ms-dark"><?= e(t('Welcome back. Enter your details.')) ?></p>

      <?php if ($error): ?>
        <div class="mt-5 rounded-xl border border-red-700 bg-red-50 text-red-900 text-sm font-semibold px-4 py-3"><?= e(t($error)) ?></div>
      <?php endif; ?>

      <form method="post" class="mt-6 space-y-5" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <div>
          <label for="username" class="<?= ui('lbl') ?>"><?= e(t('Username')) ?></label>
          <input id="username" name="username" type="text" required autofocus autocomplete="username"
                 value="<?= e((string)($_POST['username'] ?? '')) ?>" class="<?= ui('inp') ?>">
        </div>
        <div>
          <label for="password" class="<?= ui('lbl') ?>"><?= e(t('Password')) ?></label>
          <input id="password" name="password" type="password" required autocomplete="current-password" class="<?= ui('inp') ?>">
        </div>
        <button type="submit" class="<?= ui('btn') ?> w-full !py-3 !text-base"><?= e(t('Sign in')) ?></button>
      </form>
    </div>
  </main>
</div>
</body>
</html>
