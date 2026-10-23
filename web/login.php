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
        $error = 'Сессия устарела, обновите страницу.';
    } elseif (too_many_attempts($ip)) {
        $error = 'Слишком много попыток. Попробуйте позже.';
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
        $error = 'Неверный логин или пароль.';
    }
}
page_head('Вход — Media Monitoring Admin');
?>
<body class="min-h-screen bg-indigo-950 flex items-center justify-center p-4">
  <main class="w-full max-w-md bg-white rounded-2xl shadow-2xl p-6 sm:p-10">
    <div class="flex items-center gap-3 mb-8">
      <div class="h-11 w-11 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-xl font-bold">M</div>
      <div>
        <h1 class="text-xl font-bold text-indigo-950 leading-tight">Media Monitoring</h1>
        <p class="text-sm font-medium text-indigo-700">Admin · beta</p>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="mb-5 rounded-lg bg-red-50 border border-red-300 text-red-800 text-sm font-medium px-4 py-3"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="space-y-5" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <div>
        <label for="username" class="block text-sm font-semibold text-indigo-950 mb-1.5">Логин</label>
        <input id="username" name="username" type="text" required autofocus autocomplete="username"
               value="<?= e((string)($_POST['username'] ?? '')) ?>"
               class="w-full rounded-lg border-2 border-indigo-200 px-4 py-3 text-indigo-950 focus:border-indigo-600 focus:outline-none focus:ring-4 focus:ring-indigo-200">
      </div>
      <div>
        <label for="password" class="block text-sm font-semibold text-indigo-950 mb-1.5">Пароль</label>
        <input id="password" name="password" type="password" required autocomplete="current-password"
               class="w-full rounded-lg border-2 border-indigo-200 px-4 py-3 text-indigo-950 focus:border-indigo-600 focus:outline-none focus:ring-4 focus:ring-indigo-200">
      </div>
      <button type="submit"
              class="w-full rounded-lg bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-semibold py-3 transition focus:outline-none focus:ring-4 focus:ring-indigo-300">
        Войти
      </button>
    </form>
  </main>
</body>
</html>
