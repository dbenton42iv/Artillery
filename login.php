<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (current_user()) redirect(!empty($_SESSION['invite']) ? 'join.php' : 'lobby.php');

$error = null; $username = '';
$invite = null;
if (!empty($_SESSION['invite'])) {                       // arrived through a friend's invite link
    require_once __DIR__ . '/includes/names.php';
    require_once __DIR__ . '/includes/rooms.php';
    $invite = room_invite_info((string)$_SESSION['invite']) ?? ['host' => null];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now = time();
    db()->prepare('DELETE FROM ' . T('login_attempts') . ' WHERE attempted_at < ?')->execute([$now - 3600]);
    $st = db()->prepare('SELECT COUNT(*) FROM ' . T('login_attempts') . ' WHERE ip = ? AND attempted_at > ?');
    $st->execute([$ip, $now - 900]);

    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ((int)$st->fetchColumn() >= 8) {
        $error = 'Too many failed attempts. Wait 15 minutes and try again.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM ' . T('users') . ' WHERE username = ?');
        $st->execute([$username]);
        $row = $st->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE ' . T('users') . ' SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            }
            db()->prepare('UPDATE ' . T('users') . ' SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            unset($_SESSION['csrf']);
            redirect(!empty($_SESSION['invite']) ? 'join.php' : 'lobby.php');      // came from an invite link: go to the room
        }
        db()->prepare('INSERT INTO ' . T('login_attempts') . ' (ip, attempted_at) VALUES (?, ?)')->execute([$ip, $now]);
        $error = 'Wrong username or password.';
    }
}

page_head('Log in', 'auth'); ?>
<main class="auth-card panel">
  <h1 class="logo">ARTILLERY <span>3D</span></h1>
  <?php if ($invite && $invite['host']): ?>
  <p class="ok"><b><?= h($invite['host']) ?></b> invited you to a battle (<?= (int)$invite['players'] ?> of 4 in the room). Log in<?= cfg('allow_register', true) ? ' or create an account' : '' ?> to join.</p>
  <?php elseif ($invite): ?>
  <p class="err">That invite has expired: the room was closed or the battle already started. Log in to play.</p>
  <?php else: ?>
  <p class="muted">Log in to join ranked matches.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <?php if (isset($_GET['registered'])): ?><p class="ok">Account created. Log in to play.</p><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Username<input name="username" value="<?= h($username) ?>" autocomplete="username" required autofocus></label>
    <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn">Log in</button>
  </form>
  <p class="muted small">
    <?php if (cfg('allow_register', true)): ?>New here? <a href="register.php">Create an account</a> · <?php endif; ?>
    <a href="practice.html">Practice offline vs CPU</a>
  </p>
</main>
<?php page_foot();
