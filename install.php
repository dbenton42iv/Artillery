<?php
declare(strict_types=1);
/*
 * One-time installer: checks your MySQL details, creates the tables and writes includes/config.php.
 * It switches itself off once config.php exists. You can delete this file after installing.
 */
define('ART3D_INSTALLER', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/schema.php';

$installed = is_file(__DIR__ . '/includes/config.php');
$errors = []; $done = false; $configText = null;
$v = ['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => '', 'pass' => '', 'prefix' => 'art_', 'turn' => '40'];

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $v['pass'] = (string)($_POST['pass'] ?? '');
    if (version_compare(PHP_VERSION, '8.1.0', '<')) $errors[] = 'PHP 8.1 or newer is required (this server has ' . PHP_VERSION . ').';
    if (!extension_loaded('pdo_mysql')) $errors[] = 'The PHP pdo_mysql extension is not enabled on this server.';
    if (!preg_match('/^[A-Za-z0-9_]{0,20}$/', $v['prefix'])) $errors[] = 'Table prefix may only contain letters, numbers and underscores.';
    if ($v['name'] === '' || $v['user'] === '') $errors[] = 'Database name and user are required.';
    if (!$errors) {
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $v['host'], (int)$v['port'], $v['name']), $v['user'], $v['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach (schema_sql($v['prefix']) as $sql) $pdo->exec($sql);
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    if (!$errors) {
        $cfg = [
            'db' => ['host' => $v['host'], 'port' => (int)$v['port'], 'name' => $v['name'], 'user' => $v['user'], 'pass' => $v['pass'], 'prefix' => $v['prefix']],
            'base_url' => null, 'session_name' => 'ART3DSESS', 'turn_seconds' => max(15, (int)$v['turn']),
            'max_strikes' => 3, 'wind_max' => 8, 'allow_register' => true,
        ];
        $configText = "<?php\n// Written by install.php on " . date('Y-m-d H:i') . "\nreturn " . var_export($cfg, true) . ";\n";
        if (@file_put_contents(__DIR__ . '/includes/config.php', $configText) !== false) { $done = true; $configText = null; }
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install · Artillery 3D</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Black+Ops+One&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=1"></head>
<body class="auth">
<main class="auth-card panel wide">
  <h1 class="logo">ARTILLERY <span>3D</span></h1>
  <?php if ($installed || $done): ?>
    <p class="ok">Installed. Tables are created and <code>includes/config.php</code> is written.</p>
    <p class="muted">For safety, delete <code>install.php</code> from the server now. It is already locked and will not run again while <code>config.php</code> exists.</p>
    <a class="btn" href="register.php">Create the first account</a>
  <?php elseif ($configText): ?>
    <p class="ok">The tables were created, but this folder is not writable, so the config file could not be saved.</p>
    <p class="muted">Create <code>includes/config.php</code> with exactly this content, then reload this page:</p>
    <textarea class="code" readonly rows="16"><?= h($configText) ?></textarea>
  <?php else: ?>
    <p class="muted">Enter the MySQL database to use. It can be the same database as the rest of your site: every table name starts with the prefix below.</p>
    <?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
    <form method="post" class="form">
      <div class="row2">
        <label>Host<input name="host" value="<?= h($v['host']) ?>" required></label>
        <label>Port<input name="port" value="<?= h($v['port']) ?>" inputmode="numeric"></label>
      </div>
      <label>Database name<input name="name" value="<?= h($v['name']) ?>" required></label>
      <div class="row2">
        <label>User<input name="user" value="<?= h($v['user']) ?>" required autocomplete="off"></label>
        <label>Password<input name="pass" type="password" autocomplete="new-password"></label>
      </div>
      <div class="row2">
        <label>Table prefix<input name="prefix" value="<?= h($v['prefix']) ?>"></label>
        <label>Seconds per turn<input name="turn" value="<?= h($v['turn']) ?>" inputmode="numeric"></label>
      </div>
      <button class="btn">Install</button>
    </form>
    <p class="muted small">Detected app URL: <code><?= h(app_base()) ?></code></p>
  <?php endif; ?>
</main>
</body></html>
