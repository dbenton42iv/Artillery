<?php
declare(strict_types=1);
/*
 * Invite link: join.php?code=ABC123 (shared from a private room's "Invite friends" button).
 * Logged in: joins the room and opens the lobby on it. Not logged in: remembers the code, sends the friend to
 * log in (or create an account first), then brings them back here.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/game.php';
require __DIR__ . '/includes/rooms.php';

$code = room_clean_code((string)($_GET['code'] ?? $_SESSION['invite'] ?? ''));
if (strlen($code) !== 6) { unset($_SESSION['invite']); redirect('lobby.php'); }
$u = current_user();
if (!$u) { $_SESSION['invite'] = $code; redirect('login.php?invite=1'); }
unset($_SESSION['invite']);
if ($mid = active_match_for((int)$u['id'])) redirect('play.php?id=' . $mid);
$r = room_join((int)$u['id'], $code);
redirect(isset($r['error']) ? 'lobby.php?invite_error=' . urlencode($r['error']) : 'lobby.php?joined=1');
