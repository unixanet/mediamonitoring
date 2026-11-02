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
    $b = 'inline-flex items-center justify-center gap-2 h-10 px-4 rounded-[10px] text-sm font-semibold transition active:translate-y-px whitespace-nowrap ';
    static $u = null;
    $u ??= [
        'card' => 'bg-white border border-line rounded-[14px] shadow-[0_1px_2px_rgba(15,27,45,.04)]',
        'btn'  => $b . 'bg-ms hover:bg-ms-dark text-white',
        'btn2' => $b . 'bg-white hover:bg-surface text-ink border border-line2',
        'btnd' => $b . 'bg-white hover:bg-dangerbg text-danger border border-[#E2A9AE]',
        'inp'  => 'w-full min-h-10 rounded-[10px] border border-line2 bg-white px-3.5 py-2 text-ink placeholder:text-muted/70 focus:outline-none focus:border-ms focus:ring-4 focus:ring-ms-light',
        'lbl'  => 'block text-sm font-semibold text-ink mb-1.5',
        'h1'   => 'text-2xl sm:text-[26px] font-bold tracking-tight text-ink',
        'th'   => 'px-4 py-2.5 text-left text-xs font-semibold text-muted whitespace-nowrap',
        'td'   => 'px-4 py-3.5 align-middle',
        'chip' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
        'link' => 'font-semibold text-[#0B5CAD] hover:text-[#084A8C] hover:underline',
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
        . ($on ? 'text-ms' : 'text-ink') . '">' . e(t($label)) . ' <span class="text-[10px]">' . $arrow . '</span></a>';
}

// Сортировка для мобильной версии (там нет заголовков таблицы)
function mobile_sort(array $labels, array $st): void
{
    echo '<div class="md:hidden flex flex-wrap items-center gap-2 mb-3"><span class="text-sm font-semibold">' . e(t('Sort')) . ':</span>';
    foreach ($labels as $key => $label) {
        $on = $st['key'] === $key;
        $nd = $on && $st['dir'] === 'asc' ? 'desc' : 'asc';
        echo '<a href="' . e(qs(['sort' => $key, 'dir' => $nd, 'page' => null])) . '" class="' . ui('chip') . ' ' . ($on ? 'bg-ms text-white' : 'bg-white text-ink') . '">'
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
        $out .= e(mb_substr($text, $pos, $i - $pos)) . '<mark class="bg-ms-mid text-ink rounded px-0.5">' . e(mb_substr($text, $i, $ql)) . '</mark>';
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
        <thead class="bg-[#F6F8FC] border-b border-line"><tr>
          <th class="<?= ui('th') ?>"><?= sort_th('Title', 'title', $st) ?></th>
          <th class="<?= ui('th') ?>"><?= sort_th('Source', 'source', $st) ?></th>
          <th class="<?= ui('th') ?> whitespace-nowrap"><?= sort_th('Published', 'date', $st) ?></th>
        </tr></thead>
        <tbody class="divide-y divide-line">
        <?php foreach ($rows as $a): ?>
          <tr class="hover:bg-surface">
            <td class="<?= ui('td') ?>">
              <a class="<?= ui('link') ?>" href="/article.php?id=<?= (int)$a['id'] ?>"><?= hl($a['title'], $q) ?></a> <?= status_chip($a['content_status'] ?? 'full') ?>
              <?php if (!empty($a['snip'])): ?><div class="mt-1 text-sm text-ink"><?= hl($a['snip'], $q) ?>…</div><?php endif; ?>
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
          <?php if (!empty($a['snip'])): ?><div class="mt-1 text-sm text-ink"><?= hl($a['snip'], $q) ?>…</div><?php endif; ?>
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
    $m = ['pending' => ['Full text pending', 'bg-white text-ink'], 'partial' => ['Incomplete', 'bg-dangerbg text-danger'],
          'title_only' => ['Title only', 'bg-dangerbg text-danger']];
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

// Статус источника: [подпись, классы]
function src_state(array $s): array
{
    if (!$s['is_active']) return ['Paused', 'bg-ink text-white'];
    if (!empty($s['silent_since'])) return ['Silent', 'bg-warnbg text-warn'];
    if ((int)$s['consecutive_errors'] > 0) return ['Error', 'bg-dangerbg text-danger'];
    return ['Active', 'bg-ms-light text-[#0B4F94]'];
}

// Кнопки действий источника; $back = 'list' (компактные значки) | 'detail' (с подписями)
function src_actions(array $s, string $back = 'list'): void
{
    $id = (int)$s['id'];
    $compact = $back === 'list';
    $ico = [
        'play' => '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>',
        'pause' => '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4h4v16H7zM13 4h4v16h-4z"/></svg>',
        'edit' => '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>',
        'trash' => '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>',
    ];
    $cls = fn(string $k) => ui($k) . ($compact ? ' !h-9 !w-9 !px-0' : '');
    $f = fn(string $act, string $k, string $icon, string $label) =>
        '<form method="post" action="/source_action.php">'
        . '<input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . $back . '">'
        . '<button name="act" value="' . $act . '" title="' . e($label) . '" aria-label="' . e($label) . '" class="' . $cls($k) . '">' . $ico[$icon] . ($compact ? '' : e($label)) . '</button></form>';
    echo '<div class="flex flex-wrap gap-2 ' . ($compact ? 'flex-nowrap' : '') . '">';
    echo $s['is_active'] ? $f('fetch', 'btn', 'play', t('Fetch now')) : $f('start', 'btn', 'play', t('Start'));
    if ($s['is_active']) echo $f('pause', 'btn2', 'pause', t('Pause'));
    echo '<a class="' . $cls('btn2') . '" href="/source_edit.php?id=' . $id . '" title="' . e(t('Edit')) . '" aria-label="' . e(t('Edit')) . '">' . $ico['edit'] . ($compact ? '' : e(t('Edit'))) . '</a>';
    echo '<button type="button" onclick="document.getElementById(\'del' . $id . '\').showModal()" title="' . e(t('Delete')) . '" aria-label="' . e(t('Delete')) . '" class="' . $cls('btnd') . '">' . $ico['trash'] . ($compact ? '' : e(t('Delete'))) . '</button>';
    echo '<dialog id="del' . $id . '" class="rounded-[14px] border border-line p-0 shadow-2xl backdrop:bg-ink/50 w-[min(92vw,28rem)]"><form method="post" action="/source_action.php" class="p-6 space-y-3">'
        . '<input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . $back . '">'
        . '<h3 class="text-lg font-bold">' . e(t('Delete source')) . ' “' . e($s['name']) . '”</h3>'
        . '<p class="text-sm text-muted">' . e(t('Choose what to delete. This cannot be undone.')) . '</p>'
        . '<button name="act" value="delete_only" class="' . ui('btn2') . ' w-full !h-auto !py-2.5 !justify-start text-left whitespace-normal">' . e(t('Delete only the source (keep its articles in the database)')) . '</button>'
        . '<button name="act" value="delete_all" class="' . ui('btnd') . ' w-full !h-auto !py-2.5 !justify-start text-left whitespace-normal">' . e(t('Delete the source AND all its articles and logs')) . '</button>'
        . '<button type="button" onclick="this.closest(\'dialog\').close()" class="' . ui('btn2') . ' w-full">' . e(t('Cancel')) . '</button>'
        . '</form></dialog>';
    echo '</div>';
}

function link_host_path(string $u): string
{
    $p = parse_url($u);
    return preg_replace('/^www\./', '', (string)($p['host'] ?? '')) . rtrim((string)($p['path'] ?? ''), '/');
}

function ext_icon(): string
{
    return '<svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg>';
}

function source_article_row(array $a): string
{
    $chip = ['full' => '', 'pending' => ['Pending', 'bg-warnbg text-warn'], 'partial' => ['Partial', 'bg-dangerbg text-danger'], 'title_only' => ['Title only', 'bg-dangerbg text-danger']][$a['content_status'] ?? 'full'] ?? '';
    return '<li class="px-5 py-3.5 border-b border-line last:border-0 hover:bg-surface"><div class="flex items-start justify-between gap-4"><div class="min-w-0">'
        . '<a class="block text-[15px] font-semibold text-ink leading-snug hover:text-[#0B5CAD]" href="/article.php?id=' . (int)$a['id'] . '">' . e($a['title']) . '</a>'
        . '<div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-muted"><span>' . e(fmt_dt($a['published_at'])) . '</span>'
        . (!empty($a['url']) ? '<a class="inline-flex items-center gap-1 text-[#0B5CAD] hover:underline" target="_blank" rel="noopener noreferrer" href="' . e($a['url']) . '">' . ext_icon() . e(link_host_path($a['url'])) . '</a>' : '')
        . '</div></div>' . ($chip ? '<span class="' . ui('chip') . ' shrink-0 ' . $chip[1] . '">' . e(t($chip[0])) . '</span>' : '') . '</div></li>';
}
