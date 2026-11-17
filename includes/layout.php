<?php
defined('ART3D') || exit;

function page_head(string $title, string $bodyClass = ''): void
{ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> · Artillery 3D</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Black+Ops+One&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=12">
</head>
<body class="<?= h($bodyClass) ?>">
<?php }

function page_foot(): void
{ ?>
</body>
</html>
<?php }

function topbar(array $u): void
{
    $r = rank_for((int)$u['rating']); ?>
<header class="topbar">
  <a class="brand" href="lobby.php">ARTILLERY <span>3D</span></a>
  <nav>
    <span class="me"><b style="color:<?= h($r['color']) ?>"><?= h($r['name']) ?></b> <a href="account.php" class="me-name" title="Change your name"><?= h($u['name']) ?></a> · <?= (int)$u['rating'] ?></span>
    <?php if (function_exists('is_admin') ? is_admin($u) : in_array(strtolower((string)$u['username']), array_map('strtolower', (array)cfg('admins', [])), true)): ?><a href="admin.php" class="admin-link">Admin</a> · <?php endif; ?>
    <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="link">Log out</button></form>
  </nav>
</header>
<?php }
