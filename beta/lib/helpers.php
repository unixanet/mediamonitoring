<?php
// Права: admin = всё; editor = добавить источник, поиск, свой пароль
const PERMS = [
    'admin'  => ['*'],
    'editor' => ['dashboard', 'source.add', 'search', 'password'],
];

function can(string $perm): bool
{
    $l = PERMS[current_user()['role'] ?? ''] ?? [];
    return in_array('*', $l, true) || in_array($perm, $l, true);
}

function require_perm(string $perm): array
{
    $u = require_login();
    if (!can($perm)) {
        forbidden($u);
    }
    return $u;
}

function ui(string $k): string
{
    $b = 'inline-flex items-center justify-center gap-2 rounded-lg border border-black text-sm font-semibold px-4 py-2 transition active:translate-y-px ';
    static $u = null;
    $u ??= [
        'card' => 'bg-white border border-black rounded-2xl shadow-[0_1px_0_rgba(0,0,0,.06),0_14px_32px_-14px_rgba(0,120,212,.45)]',
        'btn'  => $b . 'bg-ms hover:bg-ms-dark text-white shadow-[0_2px_0_0_#000]',
        'btn2' => $b . 'bg-white hover:bg-ms-light text-black shadow-[0_2px_0_0_#000]',
        'btnd' => $b . 'bg-white hover:bg-red-700 hover:text-white text-red-800 border-red-700 shadow-[0_2px_0_0_#b91c1c]',
        'inp'  => 'w-full rounded-lg border border-black bg-white px-3.5 py-2.5 text-black placeholder:text-ms-dark/70 focus:outline-none focus:border-ms focus:ring-4 focus:ring-ms-mid',
        'lbl'  => 'block text-sm font-semibold text-black mb-1.5',
        'h1'   => 'text-2xl sm:text-3xl font-bold tracking-tight text-black',
        'th'   => 'px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-black',
        'td'   => 'px-4 py-3 align-middle',
        'chip' => 'inline-flex items-center rounded-full border border-black px-2.5 py-0.5 text-xs font-semibold',
        'link' => 'font-semibold text-ms hover:text-ms-dark hover:underline',
    ];
    return $u[$k];
}

// ---- сортировка и пагинация ----
function qs(array $over): string
{
    $q = array_filter(array_merge($_GET, $over), fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($q);
}

function sort_state(array $map, string $defKey, string $defDir = 'desc'): array
{
    $k = $_GET['sort'] ?? $defKey;
    if (!isset($map[$k])) {
        $k = $defKey;
    }
    $d = strtolower($_GET['dir'] ?? $defDir) === 'asc' ? 'asc' : 'desc';
    return ['key' => $k, 'dir' => $d, 'sql' => $map[$k] . ' ' . strtoupper($d)];
}

function sort_th(string $label, string $key, array $st): string
{
    $on = $st['key'] === $key;
    $nd = $on && $st['dir'] === 'asc' ? 'desc' : 'asc';
    $arrow = $on ? ($st['dir'] === 'asc' ? '▲' : '▼') : '↕';
    return '<a href="' . e(qs(['sort' => $key, 'dir' => $nd, 'page' => null])) . '" class="inline-flex items-center gap-1.5 hover:text-ms '
        . ($on ? 'text-ms' : 'text-black') . '">' . e(t($label)) . ' <span class="text-[10px]">' . $arrow . '</span></a>';
}

// Сортировка для мобильной версии (там нет заголовков таблицы)
function mobile_sort(array $labels, array $st): void
{
    echo '<div class="md:hidden flex flex-wrap items-center gap-2 mb-3"><span class="text-sm font-semibold">' . e(t('Sort')) . ':</span>';
    foreach ($labels as $key => $label) {
        $on = $st['key'] === $key;
        $nd = $on && $st['dir'] === 'asc' ? 'desc' : 'asc';
        echo '<a href="' . e(qs(['sort' => $key, 'dir' => $nd, 'page' => null])) . '" class="' . ui('chip') . ' ' . ($on ? 'bg-ms text-white' : 'bg-white text-black') . '">'
            . e(t($label)) . ($on ? ($st['dir'] === 'asc' ? ' ▲' : ' ▼') : '') . '</a>';
    }
    echo '</div>';
}

function page_no(): int
{
    return max(1, (int)($_GET['page'] ?? 1));
}

function pager(int $page, bool $hasNext): void
{
    if ($page === 1 && !$hasNext) {
        return;
    }
    echo '<div class="mt-5 flex items-center justify-between gap-3">';
    echo $page > 1 ? '<a class="' . ui('btn2') . '" href="' . e(qs(['page' => $page - 1])) . '">← ' . e(t('Previous')) . '</a>' : '<span></span>';
    echo '<span class="text-sm font-semibold">' . e(t('Page')) . ' ' . $page . '</span>';
    echo $hasNext ? '<a class="' . ui('btn2') . '" href="' . e(qs(['page' => $page + 1])) . '">' . e(t('Next')) . ' →</a>' : '<span></span>';
    echo '</div>';
}

// Экранирование + подсветка найденной подстроки
function hl(string $text, string $q): string
{
    $ql = mb_strlen($q);
    if ($ql === 0) {
        return e($text);
    }
    $out = '';
    $pos = 0;
    while (($i = mb_stripos($text, $q, $pos)) !== false) {
        $out .= e(mb_substr($text, $pos, $i - $pos)) . '<mark class="bg-ms-mid text-black rounded px-0.5">' . e(mb_substr($text, $i, $ql)) . '</mark>';
        $pos = $i + $ql;
    }
    return $out . e(mb_substr($text, $pos));
}

// Таблица статей (десктоп) + карточки (мобильный). $rows: id,title,published_at,source_id,source_name[,snip]
function render_articles(array $rows, array $st, bool $hasNext, int $page, string $q = ''): void
{
    if (!$rows) {
        echo '<div class="' . ui('card') . ' p-8 text-center font-semibold">' . e(t('No articles found.')) . '</div>';
        return;
    }
    mobile_sort(['date' => 'Date', 'title' => 'Title', 'source' => 'Source'], $st);
    $srcLink = can('articles.view');
    ?>
    <div class="<?= ui('card') ?> overflow-hidden hidden md:block">
      <table class="w-full">
        <thead class="bg-ms-light border-b border-black"><tr>
          <th class="<?= ui('th') ?>"><?= sort_th('Title', 'title', $st) ?></th>
          <th class="<?= ui('th') ?>"><?= sort_th('Source', 'source', $st) ?></th>
          <th class="<?= ui('th') ?> whitespace-nowrap"><?= sort_th('Published', 'date', $st) ?></th>
        </tr></thead>
        <tbody class="divide-y divide-black/10">
        <?php foreach ($rows as $a): ?>
          <tr class="hover:bg-ms-light/60">
            <td class="<?= ui('td') ?>">
              <a class="<?= ui('link') ?>" href="/article.php?id=<?= (int)$a['id'] ?>"><?= hl($a['title'], $q) ?></a> <?= status_chip($a['content_status'] ?? 'full') ?>
              <?php if (!empty($a['snip'])): ?><div class="mt-1 text-sm text-black"><?= hl($a['snip'], $q) ?>…</div><?php endif; ?>
            </td>
            <td class="<?= ui('td') ?> whitespace-nowrap font-medium">
              <?php if ($srcLink): ?><a class="hover:text-ms hover:underline" href="/articles.php?source=<?= (int)$a['source_id'] ?>"><?= e($a['source_name']) ?></a><?php else: echo e($a['source_name']); endif; ?>
            </td>
            <td class="<?= ui('td') ?> whitespace-nowrap font-medium"><?= e(fmt_dt($a['published_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="md:hidden space-y-3">
      <?php foreach ($rows as $a): ?>
        <a href="/article.php?id=<?= (int)$a['id'] ?>" class="<?= ui('card') ?> block p-4">
          <div class="font-semibold text-ms"><?= hl($a['title'], $q) ?> <?= status_chip($a['content_status'] ?? 'full') ?></div>
          <?php if (!empty($a['snip'])): ?><div class="mt-1 text-sm text-black"><?= hl($a['snip'], $q) ?>…</div><?php endif; ?>
          <div class="mt-2 flex flex-wrap gap-2 text-xs font-semibold">
            <span class="<?= ui('chip') ?>"><?= e($a['source_name']) ?></span>
            <span class="<?= ui('chip') ?>"><?= e(fmt_dt($a['published_at'])) ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
    pager($page, $hasNext);
}

function active_admin_count(int $excludeId = 0): int
{
    $r = db()->query("SELECT COUNT(*) FROM admin_users WHERE role='admin' AND is_active=1 AND id <> " . $excludeId);
    return (int)$r->fetch_row()[0];
}

function validate_password(string $p): ?string
{
    return mb_strlen($p) < 8 ? 'Password must be at least 8 characters.' : null;
}

function status_chip(string $status): string
{
    $m = ['pending' => ['Full text pending', 'bg-white text-black'], 'partial' => ['Incomplete', 'bg-red-50 text-red-900 border-red-700'],
          'title_only' => ['Title only', 'bg-red-50 text-red-900 border-red-700']];
    return isset($m[$status]) ? '<span class="' . ui('chip') . ' ' . $m[$status][1] . '">' . e(t($m[$status][0])) . '</span>' : '';
}

function until(?string $utc): string
{
    if (!$utc) return '—';
    $s = (new DateTime($utc, new DateTimeZone('UTC')))->getTimestamp() - time();
    if ($s <= 0) return t('due now');
    if ($s < 60) return t('in') . ' ' . $s . ' ' . t('sec');
    if ($s < 3600) return t('in') . ' ' . intdiv($s, 60) . ' ' . t('min') . ' ' . ($s % 60) . ' ' . t('sec');
    return t('in') . ' ' . intdiv($s, 3600) . ' ' . t('h') . ' ' . intdiv($s % 3600, 60) . ' ' . t('min');
}

// Кнопки действий источника; $back = 'list' | 'detail'
function src_actions(array $s, string $back = 'list'): void
{
    $id = (int)$s['id'];
    $f = fn(string $act, string $cls, string $label, string $confirm = '') =>
        '<form method="post" action="/source_action.php"' . ($confirm ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . $back . '">'
        . '<button name="act" value="' . $act . '" class="' . ui($cls) . ' !px-3 !py-1.5">' . e($label) . '</button></form>';
    echo '<div class="flex flex-wrap gap-2">';
    echo $s['is_active'] ? $f('fetch', 'btn', '▶ ' . t('Fetch now')) : $f('start', 'btn', '▶ ' . t('Start'));
    if ($s['is_active']) echo $f('pause', 'btn2', '❚❚ ' . t('Pause'));
    echo '<a class="' . ui('btn2') . ' !px-3 !py-1.5" href="/source_edit.php?id=' . $id . '">' . e(t('Edit')) . '</a>';
    echo '<button type="button" onclick="document.getElementById(\'del' . $id . '\').showModal()" class="' . ui('btnd') . ' !px-3 !py-1.5">' . e(t('Delete')) . '</button>';
    echo '<dialog id="del' . $id . '" class="rounded-2xl border border-black p-0 shadow-2xl backdrop:bg-black/50 w-[min(92vw,28rem)]"><form method="post" action="/source_action.php" class="p-6 space-y-3">'
        . '<input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . $back . '">'
        . '<h3 class="text-lg font-bold">' . e(t('Delete source')) . ' “' . e($s['name']) . '”</h3>'
        . '<p class="text-sm font-medium">' . e(t('Choose what to delete. This cannot be undone.')) . '</p>'
        . '<button name="act" value="delete_only" class="' . ui('btn2') . ' w-full !justify-start text-left">' . e(t('Delete only the source (keep its articles in the database)')) . '</button>'
        . '<button name="act" value="delete_all" class="' . ui('btnd') . ' w-full !justify-start text-left">' . e(t('Delete the source AND all its articles and logs')) . '</button>'
        . '<button type="button" onclick="this.closest(\'dialog\').close()" class="' . ui('btn2') . ' w-full">' . e(t('Cancel')) . '</button>'
        . '</form></dialog>';
    echo '</div>';
}

function source_article_row(array $a): string
{
    return '<li class="px-4 py-3 hover:bg-ms-light/60"><a class="' . ui('link') . '" href="/article.php?id=' . (int)$a['id'] . '">' . e($a['title']) . '</a> ' . status_chip($a['content_status'] ?? 'full')
        . '<div class="mt-0.5 text-xs font-semibold">' . e(fmt_dt($a['published_at'])) . '</div></li>';
}
