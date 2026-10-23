<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$user = require_perm('users.view');

$st = sort_state(['username' => 'username', 'role' => 'role', 'status' => 'is_active', 'last_login' => 'last_login_at', 'created' => 'created_at'], 'username', 'asc');
$rows = [];
$r = db()->query('SELECT id, username, role, is_active, last_login_at, created_at FROM admin_users ORDER BY ' . $st['sql'] . ', id LIMIT 1000');
while ($r && ($x = $r->fetch_assoc())) {
    $rows[] = $x;
}

app_start(t('Users'), 'users', $user);
page_title(t('Users'), '<a href="/user_edit.php" class="' . ui('btn') . '">+ ' . e(t('Add user')) . '</a>');
mobile_sort(['username' => 'Username', 'role' => 'Role', 'last_login' => 'Last login'], $st); ?>
<div class="<?= ui('card') ?> overflow-hidden hidden md:block">
  <table class="w-full">
    <thead class="bg-ms-light border-b border-black"><tr>
      <th class="<?= ui('th') ?>"><?= sort_th('Username', 'username', $st) ?></th>
      <th class="<?= ui('th') ?>"><?= sort_th('Role', 'role', $st) ?></th>
      <th class="<?= ui('th') ?>"><?= sort_th('Status', 'status', $st) ?></th>
      <th class="<?= ui('th') ?>"><?= sort_th('Last login', 'last_login', $st) ?></th>
      <th class="<?= ui('th') ?>"><?= sort_th('Created', 'created', $st) ?></th>
      <th class="<?= ui('th') ?>"></th>
    </tr></thead>
    <tbody class="divide-y divide-black/10">
    <?php foreach ($rows as $u): ?>
      <tr class="hover:bg-ms-light/60">
        <td class="<?= ui('td') ?> font-bold"><?= e($u['username']) ?><?= (int)$u['id'] === $user['id'] ? ' <span class="' . ui('chip') . '">' . e(t('You')) . '</span>' : '' ?></td>
        <td class="<?= ui('td') ?>"><span class="<?= ui('chip') ?> <?= $u['role'] === 'admin' ? 'bg-ms text-white' : '' ?>"><?= e(t(ucfirst($u['role']))) ?></span></td>
        <td class="<?= ui('td') ?>"><span class="<?= ui('chip') ?> <?= $u['is_active'] ? '' : 'bg-black text-white' ?>"><?= e(t($u['is_active'] ? 'Active' : 'Disabled')) ?></span></td>
        <td class="<?= ui('td') ?> font-medium"><?= e(fmt_dt($u['last_login_at'])) ?></td>
        <td class="<?= ui('td') ?> font-medium"><?= e(fmt_dt($u['created_at'])) ?></td>
        <td class="<?= ui('td') ?> text-right"><a class="<?= ui('btn2') ?> !px-3 !py-1.5" href="/user_edit.php?id=<?= (int)$u['id'] ?>"><?= e(t('Edit')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="md:hidden space-y-3">
  <?php foreach ($rows as $u): ?>
    <a href="/user_edit.php?id=<?= (int)$u['id'] ?>" class="<?= ui('card') ?> block p-4">
      <div class="flex items-center justify-between gap-2">
        <span class="font-bold"><?= e($u['username']) ?></span>
        <span class="<?= ui('chip') ?> <?= $u['role'] === 'admin' ? 'bg-ms text-white' : '' ?>"><?= e(t(ucfirst($u['role']))) ?></span>
      </div>
      <div class="mt-2 text-sm font-medium"><?= e(t($u['is_active'] ? 'Active' : 'Disabled')) ?> · <?= e(t('Last login')) ?>: <?= e(ago($u['last_login_at'])) ?></div>
    </a>
  <?php endforeach; ?>
</div>
<?php app_end();
