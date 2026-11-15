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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Black+Ops+One&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=2">
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
    <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="link">Log out</button></form>
  </nav>
</header>
<?php }
