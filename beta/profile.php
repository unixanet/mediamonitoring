<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('password');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Session expired, please reload the page.';
    } else {
        $st = db()->prepare('SELECT password_hash FROM admin_users WHERE id=?');
        $st->bind_param('i', $user['id']);
        $st->execute();
        $hash = $st->get_result()->fetch_row()[0] ?? '';
        $st->close();

        if (!password_verify((string)($_POST['current'] ?? ''), $hash)) {
            $errors[] = 'Current password is wrong.';
        }
        $new = (string)($_POST['new'] ?? '');
        $username = $user['username'];
        if (can('users.manage')) {
            $username = trim((string)($_POST['username'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
                $errors[] = 'Username: 3–64 characters, letters, digits, dot, dash, underscore.';
            }
        }
        if ($new !== '') {
            if ($e = validate_password($new)) $errors[] = $e;
            if ($new !== (string)($_POST['new2'] ?? '')) $errors[] = 'New passwords do not match.';
        }
        if (!$errors) {
            $newHash = $new !== '' ? password_hash($new, PASSWORD_BCRYPT) : $hash;
            $q = db()->prepare('UPDATE admin_users SET username=?, password_hash=? WHERE id=?');
            $q->bind_param('ssi', $username, $newHash, $user['id']);
            if ($q->execute()) {
                $_SESSION['user']['username'] = $username;
                flash_set('ok', 'Profile saved.');
                header('Location: /profile.php');
                exit;
            }
            $errors[] = $q->errno === 1062 ? 'This username is already taken.' : 'Database error: ' . $q->errno . ' ' . $q->error;
        }
    }
}

app_start(t('Profile'), 'profile', $user);
page_title(t('Profile'));
if ($errors): ?>
  <div class="mb-6 max-w-xl rounded-xl border border-red-700 bg-red-50 text-red-900 text-sm font-semibold px-4 py-3">
    <?php foreach ($errors as $m): ?><div><?= e(t($m)) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>
<form method="post" class="<?= ui('card') ?> max-w-xl p-5 sm:p-8 space-y-5">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
  <div class="flex items-center gap-3">
    <span class="h-12 w-12 rounded-full bg-ms text-white border border-black flex items-center justify-center text-lg font-bold"><?= e(strtoupper(mb_substr($user['username'], 0, 1))) ?></span>
    <div><div class="font-bold text-lg"><?= e($user['username']) ?></div><span class="<?= ui('chip') ?> bg-ms text-white"><?= e(t(ucfirst($user['role']))) ?></span></div>
  </div>
  <?php if (can('users.manage')): ?>
  <div>
    <label class="<?= ui('lbl') ?>" for="username"><?= e(t('Username')) ?></label>
    <input id="username" name="username" required value="<?= e($_POST['username'] ?? $user['username']) ?>" class="<?= ui('inp') ?>">
  </div>
  <?php endif; ?>
  <div class="grid gap-5 sm:grid-cols-2">
    <div>
      <label class="<?= ui('lbl') ?>" for="new"><?= e(t('New password')) ?></label>
      <input id="new" name="new" type="password" autocomplete="new-password" class="<?= ui('inp') ?>">
    </div>
    <div>
      <label class="<?= ui('lbl') ?>" for="new2"><?= e(t('Repeat new password')) ?></label>
      <input id="new2" name="new2" type="password" autocomplete="new-password" class="<?= ui('inp') ?>">
    </div>
  </div>
  <p class="text-sm font-medium"><?= e(t('Leave the new password empty to keep the current one.')) ?></p>
  <div class="border-t border-black/10 pt-5">
    <label class="<?= ui('lbl') ?>" for="current"><?= e(t('Current password (to confirm)')) ?></label>
    <input id="current" name="current" type="password" required autocomplete="current-password" class="<?= ui('inp') ?>">
  </div>
  <button class="<?= ui('btn') ?> w-full sm:w-auto sm:px-8"><?= e(t('Save')) ?></button>
</form>
<?php app_end();
