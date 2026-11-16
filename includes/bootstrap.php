<?php
declare(strict_types=1);
/*
 * Loaded by every page and API endpoint. Works from any sub-directory: all URLs are built from the
 * folder the app is installed in, and the session cookie is scoped to that folder only.
 */
define('ART3D', true);
define('APP_DIR', dirname(__DIR__));

/** URL path of the app folder, e.g. "/games/artillery/" (always ends in "/"). */
function app_base(): string
{
    static $base = null;
    if ($base !== null) return $base;
    $cfg = $GLOBALS['ART_CFG'] ?? null;
    if (is_array($cfg) && !empty($cfg['base_url'])) {
        return $base = rtrim((string)$cfg['base_url'], '/') . '/';
    }
    $appDir = str_replace('\\', '/', (string)realpath(APP_DIR));
    $script = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $rel    = trim(substr(dirname($script), strlen($appDir)), '/');   // "" for root pages, "api" for API
    $depth  = $rel === '' ? 0 : substr_count($rel, '/') + 1;
    $url    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    for ($i = 0; $i < $depth; $i++) $url = rtrim(str_replace('\\', '/', dirname($url)), '/');
    return $base = $url . '/';
}
function app_url(string $path = ''): string { return app_base() . ltrim($path, '/'); }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}
function redirect(string $path): never { header('Location: ' . app_url($path)); exit; }

/* ---------- config ---------- */
$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) {
    if (!defined('ART3D_INSTALLER')) redirect('install.php');
    $GLOBALS['ART_CFG'] = null;
    return;
}
$GLOBALS['ART_CFG'] = require $cfgFile;
function cfg(string $key, $default = null) { return $GLOBALS['ART_CFG'][$key] ?? $default; }

/* ---------- database ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $d = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], (int)($d['port'] ?? 3306), $d['name']);
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
/** Prefixed, quoted table name: T('users') -> `art_users` */
function T(string $name): string { return '`' . cfg('db')['prefix'] . $name . '`'; }

/* ---------- session ---------- */
session_name((string)cfg('session_name', 'ART3DSESS'));
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => app_base(),
    'secure'   => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_ok(?string $token): bool { return is_string($token) && hash_equals(csrf_token(), $token); }

/* ---------- auth ---------- */
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return $u = null;
    try {   // the ban columns arrive with an upgrade; until the first request has run it, read without them
        $st = db()->prepare('SELECT id, username, alias, alias_changed_at, rating, games_played, wins, banned_until, ban_reason,
                COALESCE(banned_until > NOW(), 0) AS blocked, TIMESTAMPDIFF(SECOND, NOW(), banned_until) AS ban_left FROM ' . T('users') . ' WHERE id = ?');
        $st->execute([$id]);
    } catch (PDOException $e) {
        $st = db()->prepare('SELECT id, username, alias, alias_changed_at, rating, games_played, wins FROM ' . T('users') . ' WHERE id = ?');
        $st->execute([$id]);
    }
    $u = $st->fetch() ?: null;
    if ($u && !empty($u['blocked'])) {               // banned or paused by an admin: signed out on their next request
        $GLOBALS['ART_BLOCKED'] = $_SESSION['blocked'] = ban_message($u);
        unset($_SESSION['uid']);
        return $u = null;
    }
    if ($u) { require_once __DIR__ . '/names.php'; $u['name'] = display_name($u); }
    return $u;
}

/** What a banned or paused player is told (login page, and when an open page signs them out). */
function ban_message(array $u): string
{
    $left = (int)($u['ban_left'] ?? 0);
    $msg = $left > 3 * 365 * 86400 ? 'This account has been banned by the site admin.'
        : 'This account is paused by the site admin for another ' . ($left >= 172800 ? floor($left / 86400) . ' d ' . floor($left % 86400 / 3600) . ' h'
            : ($left >= 3600 ? floor($left / 3600) . ' h ' . floor($left % 3600 / 60) . ' min' : max(1, (int)ceil($left / 60)) . ' min')) . '.';
    return $msg . (!empty($u['ban_reason']) ? ' Reason: ' . $u['ban_reason'] : '');
}
function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}

/* ---------- JSON API helpers ---------- */
/* API replies must be clean JSON: a PHP warning printed into one (hosts often show errors on the page) would
 * break it, and the game would see a "network error". So API requests never display errors, and anything
 * printed before the reply is thrown away. */
define('IS_API', str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/'));
if (IS_API) { ini_set('display_errors', '0'); ob_start(); }

function json_out($data, int $code = 200): never
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    echo $json === false ? '{"error":"server_error"}' : $json;
    exit;
}

/**
 * Record a problem in the errors table (and the PHP error log): server exceptions and fatal errors, and errors
 * reported by players' browsers (api/clientlog.php). Read them with: SELECT * FROM art_errors ORDER BY id DESC
 */
function log_problem(string $source, string $message, ?int $matchId = null): void
{
    static $busy = false;
    error_log("[artillery3d] $source: $message");
    if ($busy || empty($GLOBALS['ART_CFG'])) return;
    $busy = true;
    try {
        $uid = $_SESSION['uid'] ?? null;
        $matchId ??= isset($_GET['id']) ? (int)$_GET['id'] : null;
        db()->prepare('INSERT INTO ' . T('errors') . ' (source, user_id, match_id, url, message) VALUES (?,?,?,?,?)')
            ->execute([$source, $uid, $matchId, substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 250), substr($message, 0, 4000)]);
    } catch (Throwable $e) { /* the database itself may be the problem */ }
    $busy = false;
}
/** For API endpoints: logged-in user + CSRF check on writes. Releases the session lock early. */
function api_user(bool $write = false): array
{
    $u = current_user();
    if (!$u && isset($GLOBALS['ART_BLOCKED'])) json_out(['error' => 'suspended', 'message' => $GLOBALS['ART_BLOCKED']], 401);   // pages go to the login page, which says why
    if (!$u) json_out(['error' => 'not_logged_in'], 401);
    if ($write && !csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) json_out(['error' => 'bad_csrf'], 403);
    session_write_close();
    return $u;
}
function json_body(): array
{
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

/* ---------- ranks ---------- */
function rank_for(int $rating): array
{
    $tiers = [[1700, 'General', '#ffd34d'], [1500, 'Colonel', '#e8a33c'], [1350, 'Captain', '#c88cff'],
              [1200, 'Lieutenant', '#5fb3ff'], [1050, 'Sergeant', '#7bd389'], [900, 'Corporal', '#c9cbbf'], [0, 'Recruit', '#9a9d90']];
    foreach ($tiers as [$min, $name, $color]) if ($rating >= $min) return ['name' => $name, 'color' => $color];
    return ['name' => 'Recruit', 'color' => '#9a9d90'];
}

set_exception_handler(function (Throwable $e) {
    log_problem('server', (string)$e);
    if (IS_API) json_out(['error' => 'server_error'], 500);
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif">Something went wrong. Please try again.</p>';
});

/* Fatal errors (out of time or memory, a parse error) bypass the exception handler: still log them, and still
 * answer API calls with JSON so the game can say "server problem" and carry on polling. */
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    log_problem('server', "fatal: {$e['message']} in {$e['file']}:{$e['line']}");
    if (IS_API && !headers_sent()) json_out(['error' => 'server_error'], 500);
});
