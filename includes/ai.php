<?php
declare(strict_types=1);
defined('ART3D') || exit;
require_once __DIR__ . '/engine.php';
/*
 * AI opponents (computer players) for unrated "vs AI" matches.
 *
 * The AI aims the way the offline practice game's CPU does, but on the server and with the real engine:
 * it flies trial shells through the actual terrain and wind (without changing anything), searches for an
 * angle and power that land on its target, then adds aiming error by skill level. It picks up crates,
 * and uses the power-ups it holds.
 *
 * AI turns run when a player's browser polls the match (api/match.php), like turn timeouts: no cron job
 * or game server is needed. Each poll does at most one AI action, and only once the human's browser has
 * had time to finish replaying the previous event, so battles play at a watchable pace.
 */
const AI_LEVELS = [                         // aiming error (standard deviation): yaw degrees; elevation ×0.5; power ×0.7
    'rookie'  => ['name' => 'Rookie',  'err' => 5.0, 'crate' => .25, 'dodge' => .2],
    'veteran' => ['name' => 'Veteran', 'err' => 2.2, 'crate' => .4,  'dodge' => .55],
    'ace'     => ['name' => 'Ace',     'err' => 0.8, 'crate' => .55, 'dodge' => .8],
];
const AI_BOT_NAMES = ['CPU 1', 'CPU 2', 'CPU 3'];
const AI_THINK = 2.5;                      // seconds an AI "aims" after the previous event has played out

function ai_randn(): float
{
    do { $u = rnd(); } while ($u <= 0); $v = rnd();
    return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
}

/**
 * Fly a trial shell without touching the battlefield. Returns where it would burst, or null if it leaves the map.
 * Same physics as fly(): wind, gravity, tanks, buildings, crates, the sea on splash maps, the ground.
 */
function ai_trace(Terrain $t, array $players, array $objects, int $seat, float $yaw, float $elev, float $power, array $wind, array $rules): ?array
{
    $y = deg2rad($yaw); $e = deg2rad($elev); $me = $players[$seat];
    $d = [cos($e) * sin($y), sin($e), -cos($e) * cos($y)];
    $p = [$me['x'] + $d[0] * ENG_BARREL, $me['y'] + ENG_TURRET_H + $d[1] * ENG_BARREL, $me['z'] + $d[2] * ENG_BARREL];
    $sp = $power / 100 * ENG_VMAX; $v = [$d[0] * $sp, $d[1] * $sp, $d[2] * $sp];
    $g = (float)$rules['gravity']; $half = $t->half; $splash = !empty($rules['splash']);
    for ($n = 1; $n <= 2400; $n++) {
        $v[0] += $wind[0] * ENG_WIND_K * ENG_STEP; $v[2] += $wind[1] * ENG_WIND_K * ENG_STEP; $v[1] -= $g * ENG_STEP;
        $p[0] += $v[0] * ENG_STEP; $p[1] += $v[1] * ENG_STEP; $p[2] += $v[2] * ENG_STEP;
        if (abs($p[0]) > $half + 4 || abs($p[2]) > $half + 4 || $p[1] < -30) return null;
        if ($n % 2 === 0 && $p[1] < 60) {                     // collisions can't happen high up: check every other step there
            if (hit_tank($players, $p, $seat, $n * ENG_STEP)) return $p;
            if ($objects && (building_at($objects, $p) !== null || crate_at($objects, $p) !== null)) return $p;
        }
        $gh = $t->heightAt($p[0], $p[2]);
        if ($splash && $p[1] <= $t->water && $gh < $t->water) return [$p[0], $t->water, $p[2]];
        if ($p[1] <= $gh) return [$p[0], $gh, $p[2]];
    }
    return null;
}

/**
 * Find an aim that lands on target point $T. For a few elevations: bisect the power on the range along the aim
 * line, then turn the turret by the sideways miss (wind) and repeat. Returns [yaw, elev, power, miss in metres].
 */
function ai_solve(Terrain $t, array $players, array $objects, int $seat, array $T, array $wind, array $rules): array
{
    $me = $players[$seat]; $best = null;
    $dist = hypot($T[0] - $me['x'], $T[2] - $me['z']);
    $base = rad2deg(atan2($T[0] - $me['x'], -($T[2] - $me['z'])));
    foreach ([32.0, 45.0, 58.0, 72.0] as $elev) {
        $yaw = $base;
        for ($it = 0; $it < 3; $it++) {
            $lo = 10.0; $hi = 100.0; $L = null; $pw = 55.0;
            for ($k = 0; $k < 9; $k++) {
                $pw = ($lo + $hi) / 2;
                $L = ai_trace($t, $players, $objects, $seat, $yaw, $elev, $pw, $wind, $rules);
                if (!$L) { $hi = $pw; continue; }                     // flew off the map: too strong
                $ry = deg2rad($yaw);
                $along = ($L[0] - $me['x']) * sin($ry) - ($L[2] - $me['z']) * cos($ry);
                if ($along < $dist) $lo = $pw; else $hi = $pw;
            }
            if (!$L) break;
            $miss = sqrt(($L[0] - $T[0]) ** 2 + ($L[1] - $T[1]) ** 2 + ($L[2] - $T[2]) ** 2);
            $self = hypot($L[0] - $me['x'], $L[2] - $me['z']);
            $score = $miss + ($self < 12 ? 80 : 0);
            if (!$best || $score < $best[3]) $best = [$yaw, $elev, $pw, $score];
            if ($miss < 1.2) break;
            $landAng = rad2deg(atan2($L[0] - $me['x'], -($L[2] - $me['z'])));
            $yaw += fmod($base - $landAng + 540, 360) - 180;         // steer the landing point onto the target bearing
        }
    }
    return $best ?? [$base, 45.0, 60.0, 999.0];
}

/**
 * Should this AI drive before it fires, and where to? Only once per turn ($canMove), and only when it is sitting
 * in a napalm fire or was hit since its last turn (whoever hit it has its range: moving spoils that), the latter
 * with a chance that grows with skill. It tries 16 headings at full allowance and prefers going the whole way,
 * staying out of fires, not losing height and not ending up closer to the nearest enemy.
 * Returns [x, z] to drive toward, or null.
 */
function ai_drive_plan(Terrain $t, array $players, array $objects, int $seat, float $left, bool $wasHit, array $fx, array $L): ?array
{
    if ($left < 3) return null;
    $me = $players[$seat];
    $inFire = fn(float $x, float $z) => (bool)array_filter($fx['fires'] ?? [], fn($f) => hypot($x - $f['x'], $z - $f['z']) < $f['r'] + 1.5);
    $burning = $inFire($me['x'], $me['z']);
    if (!$burning && !($wasHit && rnd() < $L['dodge'])) return null;
    $foes = array_filter($players, fn($p) => $p['alive'] && $p['seat'] !== $seat);
    $near = fn(float $x, float $z) => min(array_map(fn($p) => hypot($p['x'] - $x, $p['z'] - $z), $foes) ?: [0.0]);
    $blk = drive_blockers($players, $objects, $seat); $best = null; $bestScore = -INF; $a0 = rnd() * M_PI * 2;
    for ($k = 0; $k < 16; $k++) {
        $a = $a0 + $k / 16 * M_PI * 2;
        $tx = $me['x'] + sin($a) * $left; $tz = $me['z'] - cos($a) * $left;
        $r = drive_path($t, $blk, (float)$me['x'], (float)$me['z'], $tx, $tz, $left);
        if ($r['used'] < 3) continue;
        $y = $t->heightAt($r['x'], $r['z']);
        $score = $r['used'] * 2 + min(0.0, $y - $me['y']) * .8 + min(0.0, $near($r['x'], $r['z']) - $near($me['x'], $me['z'])) * .5
               - ($inFire($r['x'], $r['z']) ? 40 : 0) + rnd() * 3;
        if ($score > $bestScore) { $bestScore = $score; $best = [$tx, $tz]; }
    }
    return $best;
}

/**
 * Decide this AI's next action. Returns ['use' => true] to use the held instant power-up first, or a shot:
 * ['yaw', 'elev', 'power', 'weapon', 'target' => 'tank'|'crate'|'mound'].
 */
function ai_decide(Terrain $t, array $players, array $objects, int $seat, array $wind, array $rules, string $level, array $fx, ?array $drive = null): array
{
    $me = $players[$seat]; $L = AI_LEVELS[$level] ?? AI_LEVELS['veteran']; $item = $me['item'] ?? null;
    if ($drive && ($to = ai_drive_plan($t, $players, $objects, $seat, $drive['left'], $drive['hit'], $fx, $L))) return ['move' => $to];
    $kind = $item ? (POWERUPS[$item]['kind'] ?? '') : '';
    $midDouble = $fx['double'] && $fx['double']['seat'] === $seat && $fx['double']['shots'] > 0;
    if ($item && !$midDouble && $kind !== 'shell') {
        $use = match ($item) {
            'repair' => $me['hp'] <= 70,
            'shield' => $me['hp'] <= 85 || rnd() < .5,
            'relocate' => $me['hp'] <= 35 && rnd() < .7,
            'flare' => false,
            default => true,                                    // weather, double, rangefinder: use straight away
        };
        if ($use) return ['use' => true];
    }
    $foes = array_values(array_filter($players, fn($p) => $p['alive'] && $p['seat'] !== $seat));
    usort($foes, fn($a, $b) => (hypot($a['x'] - $me['x'], $a['z'] - $me['z']) + $a['hp'] * .6) <=> (hypot($b['x'] - $me['x'], $b['z'] - $me['z']) + $b['hp'] * .6));
    $foe = $foes[0];
    $T = [$foe['x'], $foe['y'] + 1, $foe['z']]; $target = 'tank'; $wk = 'shell';
    $crates = array_values(array_filter($objects, fn($o) => $o['type'] === 'crate' && $o['state'] === 'live'));
    $foeD = hypot($foe['x'] - $me['x'], $foe['z'] - $me['z']);
    if ($crates && !$item && rnd() < $L['crate']) {
        usort($crates, fn($a, $b) => hypot($a['x'] - $me['x'], $a['z'] - $me['z']) <=> hypot($b['x'] - $me['x'], $b['z'] - $me['z']));
        $c = $crates[0];
        if (hypot($c['x'] - $me['x'], $c['z'] - $me['z']) < $foeD * 1.3) { $T = [$c['x'], $c['y'] + .5, $c['z']]; $target = 'crate'; }
    }
    if ($target === 'tank') {
        if ($kind === 'shell' && $item !== 'earthmover' && rnd() < .8) $wk = $item;
        elseif ($item === 'earthmover' && $me['hp'] <= 60) {    // throw up a wall between me and the nearest gun
            $k = 14 / max(1.0, $foeD);
            $tx = $me['x'] + ($foe['x'] - $me['x']) * $k; $tz = $me['z'] + ($foe['z'] - $me['z']) * $k;
            $T = [$tx, $t->heightAt($tx, $tz), $tz]; $wk = 'earthmover'; $target = 'mound';
        } else {
            $r = rnd();
            if ($me['ammo_heavy'] > 0 && ($r < .35 || ($foe['hp'] <= 70 && $foe['hp'] > 45))) $wk = 'heavy';
            elseif ($me['ammo_cluster'] > 0 && $r < .6) $wk = 'cluster';
        }
    }
    [$yaw, $elev, $pw] = ai_solve($t, $players, $objects, $seat, $T, $wind, $rules);
    $s = $L['err'];
    return ['yaw' => fmod(fmod($yaw + ai_randn() * $s, 360) + 360, 360), 'elev' => min(85.0, max(5.0, $elev + ai_randn() * $s * .5)),
            'power' => min(100.0, max(10.0, $pw + ai_randn() * $s * .7)), 'weapon' => $wk, 'target' => $target];
}

/* =====================================================================
 * Matches against the AI (database side)
 * ===================================================================== */

/** The bot accounts, created on first use. They can't log in (no valid password hash) and never appear on leaderboards. */
function ai_bot_ids(): array
{
    static $ids = null;
    if ($ids) return $ids;
    $pdo = db(); $ids = [];
    $sel = $pdo->prepare('SELECT id FROM ' . T('users') . ' WHERE username = ? AND is_bot = 1');
    foreach (AI_BOT_NAMES as $name) {
        $sel->execute([$name]); $id = $sel->fetchColumn();
        if (!$id) {
            $pdo->prepare('INSERT IGNORE INTO ' . T('users') . " (username, password_hash, is_bot, username_key) VALUES (?, '!', 1, ?)")->execute([$name, name_key($name)]);
            $sel->execute([$name]); $id = $sel->fetchColumn();
        }
        $ids[] = (int)$id;
    }
    return $ids;
}

/** Start a match against 1–3 AI tanks. $in: map ('ranked' = random from the ranked pool, or a map id), bots, level, items. */
function ai_start(array $u, array $in): array
{
    $uid = (int)$u['id'];
    if ($mid = active_match_for($uid)) return ['error' => 'in_match', 'match_id' => $mid];
    $n = max(1, min(3, (int)($in['bots'] ?? 1)));
    $level = isset(AI_LEVELS[$in['level'] ?? '']) ? $in['level'] : 'veteran';
    $items = in_array($in['items'] ?? 'safe', ['all', 'safe', 'off'], true) ? $in['items'] : 'safe';
    $map = (string)($in['map'] ?? 'ranked');
    if (!isset(MAPS[$map])) { $pool = ranked_pool(); $map = $pool[mt_rand(0, count($pool) - 1)]; }
    db()->prepare('DELETE FROM ' . T('queue') . ' WHERE user_id = ? AND match_id IS NULL')->execute([$uid]);
    $bots = array_slice(ai_bot_ids(), 0, $n);
    $group = array_merge([['user_id' => $uid, 'rating' => (int)$u['rating']]], array_map(fn($b) => ['user_id' => $b, 'rating' => 1000], $bots));
    $mid = create_match($group, $n + 1, $map, ['game_mode' => 'ai', 'items' => $items, 'bots' => array_fill_keys($bots, $level)]);
    return ['ok' => true, 'match_id' => $mid];
}

/** Has $seat lost hp (or shield) to anything since its own last action? */
function ai_was_hit(int $mid, int $seat): bool
{
    $st = db()->prepare('SELECT seat, type, payload FROM ' . T('match_events') . ' WHERE match_id = ? ORDER BY id DESC LIMIT 12');
    $st->execute([$mid]);
    foreach ($st->fetchAll() as $e) {
        if ((int)$e['seat'] === $seat && in_array($e['type'], ['shot', 'item', 'move'], true)) return false;
        $d = json_decode($e['payload'], true) ?: [];
        foreach (array_merge($d['projectiles'] ?? [], $d['bombs'] ?? []) as $p) foreach ($p['boom']['hits'] ?? [] as $h) if ((int)$h['seat'] === $seat) return true;
        foreach (array_merge($d['falls'] ?? [], $d['burn'] ?? []) as $h) if ((int)$h['seat'] === $seat && ($h['dmg'] || !empty($h['abs']))) return true;
    }
    return false;
}

/** Roughly how long a browser takes to replay an event (matches the pacing in assets/game.js). */
function ai_replay_secs(string $type, array $d): float
{
    if ($type === 'item') return !empty($d['to']) ? 2.8 : 1.6;
    if ($type === 'move') return max(.5, (float)($d['used'] ?? 0) / 7) + .6;
    if ($type !== 'shot' && $type !== 'turn') return 1.8;
    $fly = 0.0;
    foreach (array_merge($d['projectiles'] ?? [], $d['bombs'] ?? []) as $p) $fly = max($fly, ($p['t0'] + $p['dur']) / 1.45);
    $s = ($type === 'shot' ? .8 : 0) + $fly + 1.4;
    if ($type === 'turn') $s += !empty($d['crates']) ? 2.6 : (!empty($d['sudden']['first']) ? 1.8 : (count(array_diff(array_keys($d), ['turn', 'ops', 'falls', 'regen', 'deaths', 'wind'])) || $d['ops'] ? 1.0 : -.6));
    if (!empty($d['deaths'])) $s += 1.2;
    return $s;
}

/**
 * On every poll: if it's an AI tank's turn and the player's page has finished replaying the last event
 * ($shown: the last event id the page reports as shown), take one AI action. Without that report (older page),
 * or if the page seems stuck for 25 s, an estimate of the replay time is used instead.
 */
function ai_act(int $mid, ?int $shown = null): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT status, game_mode, rules, current_seat, turn_no, turn_deadline FROM ' . T('matches') . ' WHERE id = ?');
    $st->execute([$mid]);
    $m = $st->fetch();
    if (!$m || $m['status'] !== 'active' || $m['game_mode'] !== 'ai') return;
    $rules = json_decode((string)$m['rules'], true) ?: [];
    $seat = (int)$m['current_seat'];
    if (!isset($rules['bots'][$seat])) return;
    // keep the AI's own shot clock from running out while it waits for the replays
    if ((int)$m['turn_deadline'] < time() + 8) $pdo->prepare('UPDATE ' . T('matches') . ' SET turn_deadline = ? WHERE id = ? AND turn_no = ?')->execute([time() + 15, $mid, $m['turn_no']]);
    $ev = $pdo->prepare('SELECT id, type, payload, TIMESTAMPDIFF(SECOND, created_at, NOW()) ago FROM ' . T('match_events') . ' WHERE match_id = ? ORDER BY id DESC LIMIT 6');
    $ev->execute([$mid]);
    $recent = array_reverse($ev->fetchAll());
    if (!$recent) {
        if (time() < (int)$m['turn_deadline'] - turn_secs($rules) + AI_THINK) return;     // first turn: let the page load
    } elseif ($shown === null || ($shown < (int)end($recent)['id'] && (int)end($recent)['ago'] < 25)) {
        if ($shown !== null) return;                       // the page is still replaying: wait (at most 25 s)
        // no report from the page: estimate. Events replay one after another, each starting when it arrives or
        // when the one before ends; wait until the last few would all have played out, plus a moment to "aim".
        $end = -INF;                                       // seconds relative to now (negative = in the past)
        foreach ($recent as $e) $end = max($end, -(float)$e['ago']) + ai_replay_secs($e['type'], json_decode($e['payload'], true) ?: []);
        if ($end + AI_THINK > 0) return;
    }
    $lock = $pdo->quote('art3d_ai_' . cfg('db')['prefix'] . $mid);
    if ((int)$pdo->query("SELECT GET_LOCK($lock, 0)")->fetchColumn() !== 1) return;
    try {
        $st->execute([$mid]); $m = $st->fetch();
        if ($m['status'] !== 'active' || (int)$m['current_seat'] !== $seat) return;
        $full = $pdo->prepare('SELECT terrain, objects, effects, wind_ang, wind_spd FROM ' . T('matches') . ' WHERE id = ?');
        $full->execute([$mid]); $row = $full->fetch();
        $players = load_players($mid);
        $t = Terrain::unpack($row['terrain'], $rules['water']);
        $objects = json_decode((string)$row['objects'], true) ?: [];
        $fx = (json_decode((string)$row['effects'], true) ?: []) + ['double' => null, 'moved' => null, 'fires' => []];
        $turn = (int)$m['turn_no'];
        // drive at most once a turn, before anything else; "hit" = damaged by anything since this tank's own last action
        $drive = null;
        $mv = $fx['moved'];
        if (move_budget($rules) > 0 && !($mv && (int)$mv['seat'] === $seat && (int)$mv['turn'] === $turn) && !($fx['double']['shots'] ?? 0)) {
            $drive = ['left' => move_budget($rules), 'hit' => ai_was_hit($mid, $seat)];
        }
        $plan = ai_decide($t, $players, $objects, $seat, wind_vec((float)$row['wind_ang'], (float)$row['wind_spd']), $rules, (string)$rules['bots'][$seat], $fx, $drive);
        $uid = $players[$seat]['user_id'];
        if (!empty($plan['move'])) {
            $r = drive($mid, $uid, ['turn_no' => $turn, 'x' => $plan['move'][0], 'z' => $plan['move'][1]]);
            if (!isset($r['error'])) return;
            $plan = ai_decide($t, $players, $objects, $seat, wind_vec((float)$row['wind_ang'], (float)$row['wind_spd']), $rules, (string)$rules['bots'][$seat], $fx);
        }
        if (!empty($plan['use'])) {
            $r = use_item($mid, $uid, ['turn_no' => $turn]);
            if (isset($r['error'])) $plan = ai_decide($t, array_replace($players, [$seat => ['item' => null] + $players[$seat]]), $objects, $seat,
                                                      wind_vec((float)$row['wind_ang'], (float)$row['wind_spd']), $rules, (string)$rules['bots'][$seat], $fx);
            else return;
        }
        take_shot($mid, $uid, ['turn_no' => $turn, 'yaw' => $plan['yaw'], 'elev' => $plan['elev'], 'power' => $plan['power'], 'weapon' => $plan['weapon']]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK($lock)");
    }
}
