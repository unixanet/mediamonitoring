<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('users.manage');

$id = (int)($_GET['id'] ?? 0);
$u = ['username' => '', 'role' => 'editor', 'is_active' => 1];
if ($id) {
    $r = db()->query("SELECT id, username, role, is_active FROM admin_users WHERE id = $id");
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        flash_set('err', 'User not found.');
        header('Location: /users.php');
        exit;
    }
    $u = $row;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Session expired, please reload the page.';
    } elseif (isset($_POST['delete']) && $id) {
        if ($id === $user['id']) {
            $errors[] = 'You cannot delete your own account.';
        } elseif ($u['role'] === 'admin' && active_admin_count($id) === 0) {
            $errors[] = 'You cannot delete the last administrator.';
        } else {
            db()->query("DELETE FROM admin_users WHERE id = $id");
            flash_set('ok', 'User deleted.');
            header('Location: /users.php');
            exit;
        }
    } else {
        $u['username'] = trim((string)($_POST['username'] ?? ''));
        $u['role'] = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'editor';
        $u['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        $pw = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $u['username'])) $errors[] = 'Username: 3–64 characters, letters, digits, dot, dash, underscore.';
        if (($pw !== '' || !$id) && ($e = validate_password($pw))) $errors[] = $e;
        if ($id && ($u['role'] !== 'admin' || !$u['is_active']) && active_admin_count($id) === 0) {
            $errors[] = 'At least one active administrator is required.';
        }

        if (!$errors) {
            if ($id) {
                if ($pw !== '') {
                    $h = password_hash($pw, PASSWORD_BCRYPT);
                    $q = db()->prepare('UPDATE admin_users SET username=?, role=?, is_active=?, password_hash=? WHERE id=?');
                    $q->bind_param('ssisi', $u['username'], $u['role'], $u['is_active'], $h, $id);
                } else {
                    $q = db()->prepare('UPDATE admin_users SET username=?, role=?, is_active=? WHERE id=?');
                    $q->bind_param('ssii', $u['username'], $u['role'], $u['is_active'], $id);
                }
            } else {
                $h = password_hash($pw, PASSWORD_BCRYPT);
                $q = db()->prepare('INSERT INTO admin_users (username, role, is_active, password_hash) VALUES (?,?,?,?)');
                $q->bind_param('ssis', $u['username'], $u['role'], $u['is_active'], $h);
            }
            if ($q->execute()) {
                flash_set('ok', $id ? 'User saved.' : 'User added.');
                header('Location: /users.php');
                exit;
            }
            $errors[] = $q->errno === 1062 ? 'This username is already taken.' : 'Database error: ' . $q->errno . ' ' . $q->error;
        }
    }
}

$title = $id ? 'Edit user' : 'Add user';
app_start(t($title), 'users', $user);
echo '<div class="mb-2"><a href="/users.php" class="' . ui('link') . '">← ' . e(t('Users')) . '</a></div>';
page_title(t($title));
if ($errors): ?>
  <div class="mb-6 max-w-xl rounded-xl border border-red-700 bg-red-50 text-red-900 text-sm font-semibold px-4 py-3">
    <?php foreach ($errors as $m): ?><div><?= e(t($m)) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>
<form method="post" class="<?= ui('card') ?> max-w-xl p-5 sm:p-8 space-y-5">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
  <div>
    <label class="<?= ui('lbl') ?>" for="username"><?= e(t('Username')) ?></label>
    <input id="username" name="username" required value="<?= e($u['username']) ?>" class="<?= ui('inp') ?>">
  </div>
  <div>
    <label class="<?= ui('lbl') ?>" for="role"><?= e(t('Role')) ?></label>
    <select id="role" name="role" class="<?= ui('inp') ?>">
      <option value="editor" <?= $u['role'] === 'editor' ? 'selected' : '' ?>><?= e(t('Editor')) ?> — <?= e(t('add sources, search, own password')) ?></option>
      <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>><?= e(t('Admin')) ?> — <?= e(t('full access')) ?></option>
    </select>
  </div>
  <div>
    <label class="<?= ui('lbl') ?>" for="password"><?= e(t($id ? 'New password (leave empty to keep)' : 'Password')) ?></label>
    <input id="password" name="password" type="password" autocomplete="new-password" <?= $id ? '' : 'required' ?> class="<?= ui('inp') ?>">
  </div>
  <label class="flex items-center gap-3 rounded-lg border border-black px-4 py-2.5 text-sm font-semibold cursor-pointer">
    <input type="checkbox" name="is_active" value="1" <?= $u['is_active'] ? 'checked' : '' ?> class="h-4 w-4 accent-black">
    <?= e(t('Account is active')) ?>
  </label>
  <div class="flex flex-col-reverse sm:flex-row gap-3 border-t border-black/10 pt-5">
    <a href="/users.php" class="<?= ui('btn2') ?>"><?= e(t('Cancel')) ?></a>
    <button class="<?= ui('btn') ?> sm:px-8"><?= e(t('Save')) ?></button>
    <?php if ($id && $id !== $user['id']): ?>
      <button name="delete" value="1" formnovalidate onclick="return confirm('<?= e(t('Delete this user?')) ?>')" class="<?= ui('btnd') ?> sm:ml-auto"><?= e(t('Delete')) ?></button>
    <?php endif; ?>
  </div>
</form>
<?php app_end();
