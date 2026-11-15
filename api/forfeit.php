<?php
declare(strict_types=1);
/* Leave a match for good. Body: {"match_id"} */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'post_only'], 405);
$u = api_user(true);
$ok = forfeit((int)(json_body()['match_id'] ?? 0), (int)$u['id']);
json_out(['ok' => $ok]);
