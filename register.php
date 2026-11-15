<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/migrate.php';
ensure_schema();

if (current_user()) redirect('lobby.php');
if (!cfg('allow_register', true)) redirect('login.php');

$errors = []; $username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');
    if (!csrf_ok($_POST['csrf'] ?? null)) $errors[] = 'Your session expired. Please try again.';
    if ($p = name_problem($username, 'username')) $errors[] = $p;
    elseif (name_taken($username)) $errors[] = 'That username is taken, or looks too much like one that is.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if (!$errors) {
        try {
            db()->prepare('INSERT INTO ' . T('users') . ' (username, username_key, password_hash) VALUES (?, ?, ?)')
                ->execute([$username, name_key($username), password_hash($password, PASSWORD_DEFAULT)]);
            redirect('login.php?registered=1');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') $errors[] = 'That username is taken.'; else throw $e;
        }
    }
}

page_head('Create account', 'auth'); ?>
<main class="auth-card panel">
  <h1 class="logo">ARTILLERY <span>3D</span></h1>
  <?php if (!empty($_SESSION['invite'])): ?><p class="ok">Create your account, then log in to join your friend's battle.</p><?php endif; ?>
  <p class="muted">Every new commander starts at a rating of 1000 (Corporal). You log in with your username; you can pick a different name to show other players later.</p>
  <?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Username<input name="username" value="<?= h($username) ?>" maxlength="20" autocomplete="username" required autofocus></label>
    <label>Password<input name="password" type="password" minlength="8" autocomplete="new-password" required></label>
    <label>Confirm password<input name="confirm" type="password" minlength="8" autocomplete="new-password" required></label>
    <button class="btn">Create account</button>
  </form>
  <p class="muted small">Already enlisted? <a href="login.php">Log in</a></p>
</main>
<?php page_foot();
