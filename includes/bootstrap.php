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
    $st = db()->prepare('SELECT id, username, alias, alias_changed_at, rating, games_played, wins FROM ' . T('users') . ' WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch() ?: null;
    if ($u) { require_once __DIR__ . '/names.php'; $u['name'] = display_name($u); }
    return $u;
}
function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}

/* ---------- JSON API helpers ---------- */
function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
/** For API endpoints: logged-in user + CSRF check on writes. Releases the session lock early. */
function api_user(bool $write = false): array
{
    $u = current_user();
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
    error_log('[artillery3d] ' . $e);
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) json_out(['error' => 'server_error'], 500);
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif">Something went wrong. Please try again.</p>';
});
