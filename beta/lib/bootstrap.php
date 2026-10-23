<?php
mysqli_report(MYSQLI_REPORT_OFF);
$cfg = require __DIR__ . '/../config.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function db(): mysqli
{
    static $db = null;
    global $cfg;
    if ($db === null) {
        $c = $cfg['db'];
        $db = new mysqli($c['host'], $c['user'], $c['pass'], $c['name']);
        if ($db->connect_errno) {
            http_response_code(500);
            exit('DB connection error');
        }
        $db->set_charset('utf8mb4');
    }
    return $db;
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
    if (!$u) {
        header('Location: /login.php');
        exit;
    }
    return $u;
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
