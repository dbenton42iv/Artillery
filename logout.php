<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok($_POST['csrf'] ?? null)) {
    if (!empty($_SESSION['uid'])) {
        db()->prepare('DELETE FROM ' . T('queue') . ' WHERE user_id = ? AND match_id IS NULL')->execute([$_SESSION['uid']]);
    }
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
}
redirect('login.php');
