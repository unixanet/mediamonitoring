<?php
mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('UTC');
const UI_TZ = 'Asia/Yerevan';
$cfg = require __DIR__ . '/../config.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');

function connect_db(array $c): mysqli
{
    $db = new mysqli($c['host'], $c['user'], $c['pass'], $c['name']);
    if ($db->connect_errno) {
        http_response_code(500);
        exit('DB connection error');
    }
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '+00:00'");
    return $db;
}

// БД админки
function db(): mysqli
{
    static $db = null;
    global $cfg;
    return $db ??= connect_db($cfg['db']);
}

// БД краулера (sources, articles)
function cdb(): mysqli
{
    static $db = null;
    global $cfg;
    return $db ??= connect_db($cfg['crawler_db']);
}

// i18n: ключ = английская строка; переводы в lang/<код>.php (['English' => 'Перевод'])
function t(string $s): string
{
    static $tr = null;
    if ($tr === null) {
        $f = __DIR__ . '/../lang/' . ($_SESSION['lang'] ?? 'en') . '.php';
        $tr = is_file($f) ? require $f : [];
    }
    return $tr[$s] ?? $s;
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'] = [$type, $msg];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function fmt_dt(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(UI_TZ));
    return $d->format('d M Y, H:i');
}

function ago(?string $utc): string
{
    if (!$utc) {
        return t('Never');
    }
    $s = max(0, time() - (new DateTime($utc, new DateTimeZone('UTC')))->getTimestamp());
    if ($s < 60) return t('just now');
    if ($s < 3600) return intdiv($s, 60) . ' ' . t('min ago');
    if ($s < 86400) return intdiv($s, 3600) . ' ' . t('h ago');
    return intdiv($s, 86400) . ' ' . t('d ago');
}

function ui_to_utc(string $v): ?string
{
    try {
        $d = new DateTime($v, new DateTimeZone(UI_TZ));
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function utc_to_ui_input(string $utc): string
{
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(UI_TZ));
    return $d->format('Y-m-d\TH:i');
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $u = current_user();
    if ($u) {
        // роль и активность берём из БД на каждый запрос
        $st = db()->prepare('SELECT username, role FROM admin_users WHERE id=? AND is_active=1');
        $st->bind_param('i', $u['id']);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            $u = $_SESSION['user'] = ['id' => $u['id'], 'username' => $row['username'], 'role' => $row['role']];
            return $u;
        }
        $_SESSION = [];
    }
    header('Location: /login.php');
    exit;
}

function too_many_attempts(string $ip): bool
{
    global $cfg;
    $win = (int)$cfg['attempt_window'];
    $st = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND success=0 AND created_at > (NOW() - INTERVAL $win SECOND)");
    $st->bind_param('s', $ip);
    $st->execute();
    $st->bind_result($n);
    $st->fetch();
    $st->close();
    return $n >= $cfg['max_attempts'];
}

function log_attempt(string $ip, string $username, bool $ok): void
{
    $s = (int)$ok;
    $st = db()->prepare('INSERT INTO login_attempts (ip, username, success) VALUES (?,?,?)');
    $st->bind_param('ssi', $ip, $username, $s);
    $st->execute();
    $st->close();
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/crawler.php';
