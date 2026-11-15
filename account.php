<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/game.php';
/*
 * Your name: pick an alias that other players see instead of your username.
 * You still log in with your username. Aliases are filtered for profanity, must not look like anyone
 * else's name, can't change during a battle, and can change once per 'alias_cooldown_hours' (default 24).
 */
$u = require_login();
$errors = []; $done = null; $alias = (string)($u['alias'] ?? '');
$cool = max(0, (int)cfg('alias_cooldown_hours', 24)) * 3600;
$since = $u['alias_changed_at'] ? (int)db()->query('SELECT TIMESTAMPDIFF(SECOND, ' . db()->quote($u['alias_changed_at']) . ', NOW())')->fetchColumn() : null;
$wait = $since === null ? 0 : max(0, $cool - $since);
$inBattle = (bool)active_match_for((int)$u['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clear = isset($_POST['clear']);
    $alias = $clear ? '' : preg_replace('/\s+/', ' ', trim((string)($_POST['alias'] ?? '')));
    if ($alias !== '' && name_key($alias) === name_key($u['username'])) $alias = '';   // same as the username: just use that
    if (!csrf_ok($_POST['csrf'] ?? null)) $errors[] = 'Your session expired. Please try again.';
    elseif ($alias === (string)($u['alias'] ?? '')) $errors[] = 'That is already your name.';
    elseif ($inBattle) $errors[] = "You can't change your name during a battle. Finish or leave it first.";
    elseif ($wait > 0) $errors[] = 'You can change your name again in ' . wait_text($wait) . '.';
    elseif ($alias !== '') {
        if ($p = name_problem($alias, 'alias')) $errors[] = $p;
        elseif (name_taken($alias, (int)$u['id'])) $errors[] = 'Someone already uses that name, or one that looks too much like it.';
    } elseif (!name_is_clean($u['username'])) $errors[] = "Your username can't be shown to other players, so you need a name.";
    if (!$errors) {
        try {
            db()->prepare('UPDATE ' . T('users') . ' SET alias = ?, alias_key = ?, alias_changed_at = NOW() WHERE id = ?')
                ->execute([$alias === '' ? null : $alias, $alias === '' ? null : name_key($alias), $u['id']]);
            $_SESSION['flash'] = $alias === '' ? 'Other players now see your username.' : 'Other players now see you as ' . $alias . '.';
            redirect('account.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') $errors[] = 'Someone already uses that name, or one that looks too much like it.'; else throw $e;
        }
    }
}
$done = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

function wait_text(int $s): string
{
    $h = intdiv($s, 3600); $m = (int)ceil(($s % 3600) / 60);
    if ($m === 60) { $h++; $m = 0; }
    return $h ? $h . ' h' . ($m ? ' ' . $m . ' min' : '') : max(1, $m) . ' min';
}

page_head('Your name', 'lobby'); topbar($u); ?>
<main class="narrow">
  <div class="panel card">
    <h2>Your name</h2>
    <p>Other players see you as <b><?= h($u['name']) ?></b>. You log in as <b><?= h($u['username']) ?></b>.</p>
    <?php if ($done): ?><p class="ok"><?= h($done) ?></p><?php endif; ?>
    <?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
    <?php if ($inBattle): ?><p class="muted">You're in a battle right now. You can change your name when it's over.</p>
    <?php elseif ($wait > 0 && !$errors): ?><p class="muted">You can change your name again in <?= h(wait_text($wait)) ?>.</p><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Name shown to other players
        <input name="alias" value="<?= h($alias) ?>" maxlength="20" minlength="3" autocomplete="nickname" spellcheck="false"
               placeholder="<?= h($u['username']) ?>" pattern="[A-Za-z0-9][A-Za-z0-9 _.\-]{1,18}[A-Za-z0-9]" <?= $inBattle || $wait > 0 ? 'disabled' : '' ?>>
      </label>
      <div class="row">
        <button class="btn" <?= $inBattle || $wait > 0 ? 'disabled' : '' ?>>Save name</button>
        <?php if (!empty($u['alias']) && name_is_clean($u['username'])): ?>
          <button class="btn ghost" name="clear" value="1" <?= $inBattle || $wait > 0 ? 'disabled' : '' ?>>Use my username instead</button>
        <?php endif; ?>
      </div>
    </form>
    <ul class="muted small rules">
      <li>3–20 characters: letters, numbers, spaces, <code>_</code> <code>.</code> or <code>-</code>.</li>
      <li>No profanity, slurs or hateful references, and no names that pose as staff (Admin, Moderator…).</li>
      <li>It can't look like another player's name (Bob, B0B and b.o.b count as the same).</li>
      <li>You can change it once every <?= (int)round($cool / 3600) ?> hours, and not during a battle.</li>
    </ul>
    <p><a href="lobby.php">← Back to the lobby</a></p>
  </div>
</main>
<?php page_foot();
