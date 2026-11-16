# Artillery 3D: online edition

A PHP + MySQL web app: player accounts, a lobby, rank-based matchmaking, and online 3D artillery
battles for 2–4 players. It is built to live in a **sub-folder of an existing website**
(e.g. `https://yoursite.com/games/artillery/`) without touching the rest of the site.

## Requirements
- PHP **8.1+** with the `pdo_mysql` extension (standard on most hosts)
- MySQL 5.7+ or MariaDB 10.3+ (InnoDB)
- Any web server that runs PHP: Apache, LiteSpeed, Nginx + PHP-FPM, IIS
- No Docker, Composer, Node or build step needed

## Install (about 5 minutes)
1. **Create a database and user** in your hosting panel (cPanel → MySQL Databases, or similar).
   You can also reuse your site's existing database: every table this app creates starts with a
   prefix (`art_` by default), so nothing collides.
2. **Upload this whole folder** to wherever you want the game, e.g. `public_html/games/artillery/`.
3. **Visit** `https://yoursite.com/games/artillery/install.php`, enter the database details and
   click *Install*. It creates the tables and writes `includes/config.php`.
   - If the folder isn't writable, the installer shows the config file's contents so you can create
     `includes/config.php` yourself.
   - Prefer to do it by hand? Import `sql/schema.sql`, then copy `includes/config.sample.php` to
     `includes/config.php` and fill it in.
4. **Delete `install.php`** from the server. It already refuses to run once `config.php` exists,
   but removing it is tidier.
5. Open `https://yoursite.com/games/artillery/`, create an account, and play.

### Running inside another site
- **Any folder name or depth works.** Every link and API call is relative, and the app works out its
  own URL. If an unusual proxy or symlink setup confuses that, set `'base_url' => '/your/path/'` in
  `includes/config.php`.
- **Its own login.** The session cookie (`ART3DSESS`) is limited to the app's folder, so it never
  interferes with your main site's login. The game's user accounts are separate from your site's.
- **Its own tables.** All tables use the configured prefix.
- **Protected folders.** `includes/`, `sql/` and `tests/` each have an `.htaccess` that blocks web access on
  Apache/LiteSpeed. On **Nginx**, add this inside your `server {}` block (adjust the path):
  ```nginx
  location ~ ^/games/artillery/(includes|sql|tests)/ { deny all; }
  ```
  The PHP files in `includes/` also refuse to run if requested directly.

## How it plays
- **Lobby:** your rank, rating and position, a live leaderboard, your match history, and three tabs:
  - **Ranked:** rated matches, 1 v 1, 3 tanks or 4 tanks, matched by rating.
  - **Casual:** unrated, first come first served. Pick a map or *Any map*; every power-up can drop.
  - **Private:** unrated, by invite code. The host opens a room, sends friends the 6-letter code, picks the
    map (or Random), the power-ups (all, ranked-safe only, or off) and the shot clock (20–60 s), and starts
    once 2–4 players are in. If the host leaves, the room closes.
    **Invite friends** sends a link (`join.php?code=…`) through the phone's share sheet, or copies it on a
    computer. Tapping it joins the room; a friend who isn't logged in, or has no account yet, logs in or signs
    up first (the login page says who invited them) and then lands in the room. The room stays open for 3
    minutes without the host, so switching to Messages to send the link doesn't close it.
  - **vs AI:** practise against 1–3 computer players. Pick a random ranked map or any map, a skill
    (Rookie, Veteran or Ace) and the power-ups. Played to ranked rules by default (fair crates, ranked-safe
    power-ups) but always unrated: ratings, wins, matches played and the leaderboard never change.
- **Matchmaking by rank:** everyone starts at a rating of **1000**. A search first looks for opponents
  within **±100** rating, widening by 15 points per second you wait (up to ±800), so close ranks meet
  first and nobody waits forever.
- **Rank titles:** Recruit (<900) · Corporal · Sergeant (1050+) · Lieutenant (1200+) · Captain (1350+) ·
  Colonel (1500+) · General (1700+).
- **Battle:** turn-based with a 40-second shot clock. Aim the turret, angle and power, pick Shell,
  Heavy (×3) or Cluster (×2), and fire. When your turn comes round, the path of your own last shot is shown
  (not the opponent's that just landed), so you can adjust from it. Wind shifts every turn, craters reshape the ground, and tanks
  that lose the ground under them fall and take damage. Last tank standing wins.
- **Shot cards:** under the aiming controls, cards show your own last 3 shots: the turn, weapon, turret, angle and power,
  the wind then (as an arrow relative to that aim: ↑ with the shot, ← pushing it left) and what happened (a hit, a crate,
  the Spotter's range report, or a miss). Tap one on your turn to put your aim back exactly there, e.g. after a turn spent
  shooting at a crate. They are kept in the browser for that match, so they survive a reload.
- **Driving:** on your turn, before you fire, you can drive up to 10 m (`move_per_turn`), in one hop or several.
  Press **Drive** (or **G**): a ring shows how far you can go. With a mouse, hover to see the route and click to
  go; on a phone or tablet, tap the ground to see the route, then press **DRIVE**. The tank goes in a straight
  line and stops early at a slope too steep to climb or a drop too steep to go down, at water, the map edge,
  another tank or wreck, a building or a crate. Distance is measured along the ground, so climbing uses more of
  it. Driving doesn't end your turn and doesn't need a target: use it to get out of a fire, out of a crater, or
  off the spot an enemy has ranged. Esc (or the AIM pad) goes back to aiming. Everyone sees the tank drive.
- **Heal over time:** every living tank repairs 3 hp (`regen_per_turn`) at the start of its own turn, up to 100,
  after that turn's damage (fire, falls), so a tank destroyed at the start of its turn isn't healed. Repairs stop
  at sudden death (turn 30).
- **Maps:** every match is played on one of these (each has its own look, physics and rule):

  | Map | Ground | Rule | Gravity | Wind |
  |---|---|---|---|---|
  | Highlands | Island of hills, a main ridge, a second ridge and knolls; tanks on shelves 4–22 m up | none | 20 | 0–8 m/s |
  | Archipelago | A ring-shaped island (a torus) round a lagoon with rocky islets; the ring has hills and saddles, tanks on it 4.5–17 m up | Shells landing in water splash: blast ×0.6, no crater | 20 | 0–10 m/s |
  | Canyon | A 15–21 m plateau cut by wide winding canyons (floor about 19 m across, walls about 8–18 m high with a ledge); every tank starts on the floor of its own canyon, 4.5–11 m up, plus one canyon between them | Lob over the rims; drive along the floor | 20 | 4–12 m/s |
  | Volcano | Ring of hills round the cone, old lava domes; tanks 4.5–18 m up | Every 4th turn: 3 lava bombs and the cone grows | 20 | 0–6 m/s |
  | Arctic Shelf | Stepped ice shelves with ice cliffs; each tank on its own shelf, 3.5–15 m | Snow refills craters every turn; falls do ×1.5 damage | 20 | 4–12 m/s |
  | Ruined City | A city on hills; tanks 3.5–15 m up | Buildings (120 hp) stop shells until shot down to rubble | 20 | 0–6 m/s |
  | Lunar Base | Cratered plain with flat-topped mesas; tanks from a basin (2.5 m) to a mesa (15 m) | Low gravity, no wind | 7 | none |

  1 v 1 and 3-tank matches use a 240 m map; 4-tank matches use 360 m.
- **Different heights:** tanks never start level. On every map the tanks' heights are spread evenly between that
  map's low and high level and dealt out at random, so no seat is favoured. In computer-vs-computer tests the
  higher tank won about half of 1 v 1 matches (45–55% on every map, about 1,500 matches), so high ground is an
  option, not a win.
- **Cover on every map:** no map is flat with a straight line of sight between tanks. The open maps get their own
  features (sea stacks in Archipelago's lagoon, rock pillars on Canyon, pressure ridges on Arctic Shelf, rubble berms in
  Ruined City, mesas on Lunar Base). After any map is built, every pair of tanks is checked turret to turret, and
  any pair that could see each other gets a ridge of cover, in the map's style, raised between them until the
  ground stands at least 2 m above the line of sight. Buildings don't count, because they can be shot down.
- **Map rotation:** ranked picks at random from a pool of five (Highlands, Archipelago, Canyon, Volcano,
  Arctic Shelf by default; change it with `ranked_maps`). Within a ranked session no map comes back until
  you've played the whole pool, and never twice in a row. A session ends after 30 minutes without a
  ranked match. Ruined City and Lunar Base are played in Casual and Private matches, or add them to the pool.
- **Sudden death:** from turn 30, every tank loses 5 hp per turn and the wind gets 0.5 m/s stronger each turn,
  up to 15 m/s.
- **Supply crates (paired drops):** at the start of every 5th turn (`crate_every`), every tank gets its own crate.
  Each lands the same distance from its own tank (18 m, `crate_near`), always at least 10 m nearer to it than to
  any other tank, on dry ground the tank can drive to; a tank that still has a crate waiting gets no new one. Its
  crate has a ring in its colour and reads *Your crate*. **Drive over it** (within 2.5 m of your route) to open
  it: that's a turn or two of driving, and it doesn't cost your shot; the drive preview says when a route picks it up.
  You can also **shoot any crate**: a shell exploding within 4 m claims it for you, so you can steal an enemy's
  crate, and a crate further out but inside a blast is wrecked, so you can deny one. Driving doesn't stop at crates.
  Set `'crate_drops' => 'centre'` for the old rule (one crate in a 15 m circle between the tanks, `crate_max` and
  `crate_radius`; on Canyon, up on the plateaus); matches started before phase 4 keep it. Crates are
  sealed: what's inside is decided by the server only at the moment one is claimed, so nobody can know it in
  advance (not even by reading the game's network traffic). Once claimed, only the player who opened it learns what
  it was: everyone else sees that they opened a crate and hold a power-up (a ? by their name), not which one, until
  they use or fire it. You hold
  one power-up at a time; a new one replaces it. It sits in the 4th
  weapon-bar slot (key 4):

  | Power-up | Effect | Ranked |
  |---|---|---|
  | Repair Kit | +30 hp (up to 100) | yes |
  | Shield Bubble | absorbs the next 40 damage, for 2 enemy turns (not sudden-death drain) | yes |
  | Weather Report | shows you (only you) the exact wind for your next 2 turns | yes |
  | Bunker Buster | a shell that tunnels up to 8 m into the ground, then explodes (60 dmg, 7 m) | yes |
  | Napalm | a shell that leaves a 6 m fire: 8 damage a turn to tanks inside, for 3 turns | yes |
  | Earthmover | a shell that builds a mound (7 m wide, 5 m high) instead of a crater | yes |
  | Double Shot | fire two shells this turn, each with its own aim | no |
  | Rangefinder | your aim arc shows the whole flight, wind included, for 1 shot | no |
  | Relocate | moves your tank to a random spot at least 40 m away; uses your turn | no |
  | Flare | night maps only: arrives with Scenarios (never drops yet) | no |

  Using a power-up doesn't cost your turn, except Relocate. Shell power-ups are loaded from the slot and fired.
- **Ranked crates** only hold the six ranked-safe power-ups.
- **Names:** players log in with their username but can choose a different name for others to see
  (*Change name* in the lobby). Names are 3–20 characters and can change once a day, never during a battle.
  Usernames and names are filtered for profanity, slurs and hate symbols, including disguised spellings
  (`sh1t`, `f_u_c_k`, `fuuuck`), without blocking innocent words like *Bass*, *Peacock* or *Scunthorpe*. Names
  that pose as staff (*Admin*, *Moderator*) are reserved, and a name can't look like someone else's
  (*Bob*, *B0B* and *b.o.b* count as the same). Add your own words with `blocked_names` in config.
  Accounts created before the filter whose username fails it appear as *Commander #id* until they pick a name.
- **Phones and tablets:** the battle page detects touch screens and screen size. On phones the aiming
  controls fold into a bottom drawer with large −/+ buttons, the scoreboard shrinks to chips, and the
  *Aim* pad lets you hold and drag to aim (sideways turns the turret, up/down sets the angle).
- **Ratings:** ranked only (Casual, Private and vs AI never change rating, wins or matches played). Multiplayer Elo. Every pair of players counts as a head-to-head result based on
  finishing order (survivor first, then by how long each tank lasted).
- **Loadouts:** before a match, every player brings a loadout: **1 doctrine** and up to **2 perks**, set under
  *Loadout* in the lobby. Every skill has a cost, so a loadout changes how you play without making the tank stronger
  overall. Everyone sees everyone's loadout on the battle's intro screen and as icons on the scoreboard.

  | Doctrine | Gain | Cost | Level |
  |---|---|---|---|
  | Standard Issue | Heavy ×3, Cluster ×2 | none | 1 |
  | Heavy Gunner | Heavy ×4 | no Cluster rounds; your Heavy shells do 65 damage, not 70 | 3 |
  | Demolitions | craters 30% wider; fall damage you cause counts double | blast damage −10% | 5 |
  | Sharpshooter | aim arc twice as long | Heavy ×2, Cluster ×1 | 7 |
  | Fortified | start at 105 hp | max power 85 (shorter range) | 9 |
  | Meteorologist | see the wind of your own next turn | every shell −2 damage | 12 |

  | Perk | Gain | Cost | Level |
  |---|---|---|---|
  | Reinforced Tracks | fall damage on you halved | −5 starting hp | 2 |
  | Quick Loader | +10 s on your shot clock | none (clock only) | 2 |
  | Scavenger | claim crates from 6 m instead of 4 (3.5 m instead of 2.5 when driving over them) | your power-ups last 1 turn less (Shield, Napalm, Weather Report) | 4 |
  | Spotter | after each shot: how far it landed from the nearest enemy ("12 m short · 3 m left") | enemies see SPOTTING by your name | 6 |
  | Low Profile | smaller target: hit radius 2.1 m instead of 2.4 | −4 starting hp | 8 |
  | Bomblet Pack | Cluster splits into 7 bomblets instead of 5 | each bomblet −5 damage | 10 |
  | Dug In | blasts within 3 m of you do 10% less | can't use Relocate (and never finds it in a crate) | 14 |

  **Presets**, open to everyone in every mode: *Rifleman* (Standard Issue, Quick Loader, Reinforced Tracks), *Gunner*
  (Heavy Gunner, Quick Loader, Scavenger), *Sapper* (Demolitions, Reinforced Tracks, Scavenger). A custom build may only
  use skills you've unlocked. **Below level 14, ranked matches always use a preset** (you choose which), so new
  players never face builds they can't have yet; from level 14 every skill is unlocked and ranked takes custom builds.
  The server checks the loadout when the match starts and records it with the match.
- **XP and levels:** every finished match earns XP in every mode (rating stays ranked-only): 50 for finishing,
  2 per 10 damage dealt, 25 per kill, 50 for a win and 100 for your first win of the day. Leaving or being removed
  for missed turns loses the finishing and win XP. Level *n* needs 50 + 25×*n* XP to reach the next (levels run to 50;
  level 14, every skill, takes about 22 matches). The lobby shows your level and progress; the end of each battle shows
  what it earned and any skills a new level unlocked. Computer players earn nothing; a match the admin ends earns nothing.
- **Missed turns:** if your clock runs out, your turn is skipped. Three missed turns in a row and you
  are removed from the match. The *Leave* button forfeits the match.
- **Practice:** `practice.html` is the offline version (hot-seat and CPU opponents, no rating).

## How it works
- **The server is the referee.** The browser sends only your aim. The server flies the shell
  (`includes/engine.php`), digs the craters, applies damage and saves the result. Players can't fake
  hits by editing JavaScript.
- **Replays:** every shot is stored as an event with its exact flight path. Each player's browser polls
  `api/match.php` about once a second and replays new events, so everyone sees the same thing.
  No WebSockets are needed, so it works on ordinary shared hosting.
- **The terrain** is a 161×161 heightmap (241×241 on 4-tank maps) stored in the match row (~100 KB or ~230 KB)
  and updated after each shot. Every change is recorded as a list of terrain operations; `assets/terrain.js`
  applies them with exactly the same arithmetic as the server, so each browser's copy stays identical.
- **Turn effects** (eruptions, fire zones, shields wearing off, snowfall, sudden death, supply drops) run when the turn changes, which happens on a shot,
  a forfeit or a poll that notices the clock ran out. No cron job is needed.
- **Computer players** (`includes/ai.php`) aim with the real engine: they fly trial shells through the
  actual terrain and wind, search for an angle and power that land on target, then add aiming error by skill
  (Rookie hits an enemy with about 1 shot in 5, Veteran 2 in 5, Ace 3 in 5). They go for crates and use their
  power-ups. If one was hit since its last turn (or sits in a fire), it may drive away first, more often at
  higher skill: whoever hit it had its range. A decision takes well under 0.1 s. Their turns run when your page polls, like timeouts (no cron),
  and they wait until your page reports it has finished replaying the last shot. They play from three bot
  accounts (CPU 1–3) that can't log in and never appear on the leaderboard. A match against the AI ends as soon
  as no human tank is left.
- **Power-up log:** every crate drop, claim, wreck and use goes into the `item_log` table, so drop weights
  (in `includes/items.php`) can be tuned against real win rates.
- **Upgrading** from an earlier version: upload the new files. The first request adds the new columns and tables
  (`matches.game_mode`, `effects`; `match_players.item`; `queue.kind`, `map_pref`; `users.is_bot`; `rooms`,
  `room_members`, `item_log`; phase 3: `match_players.moved` and the `move` event type; phase 4: `users.xp`, `level`,
  `loadout`, `first_win_on` and `match_players.loadout`, `xp`, `xp_detail`) by itself. Matches from before phase 4
  finish without loadouts (100 hp, standard kit), and pay XP when they finish. Matches already
  in progress finish under the rules they started with (no crates); they do get driving, at the site's setting.
  If your host caches PHP (OPcache), the new files may take a few seconds to be picked up.

## Admin: "restarting" the game
There is no game server process to restart: every request runs PHP and reads the database. When something is stuck,
an admin opens **`admin.php`** (or the **Admin** link in the lobby's top bar). List admin usernames in
`includes/config.php`: `'admins' => ['yourname'],`. The page shows running matches (marked *stuck?* after 5 minutes
with no move, or a minute with nobody online), the queue, open rooms and the latest logged problems, and can:
- **End** one match, or **End all matches**: no result; ratings, wins and matches played don't change. Players on
  the battle page see "Battle ended by the site admin".
- **Clear the queue**, **Close open rooms**.
- **Reload code**: clears PHP's OPcache, so newly uploaded files are used straight away.
- **Restart everything**: all of the above at once.

**Banning and pausing players** (*Players: ban or pause* on the same page): find a player by username or name, then
**pause** them for 1 hour, 1 day, 1 week or 30 days, or **ban** them until you restore access, with an optional reason.
They are signed out on their next request (an open battle or lobby sends them to the login page), can't log in, and
are told why: "This account is paused by the site admin for another 2 h 59 min. Reason: …". Their tanks in running
battles are withdrawn as if they had left (opponents play on and are scored normally), and they leave the queue and
any private room. Rating, history and loadout are kept. Everyone currently banned or paused is listed with time left
and a **Restore access** button. Admins and computer players can't be banned. Stored in `users.banned_until`
(a ban is the year 9999), `ban_reason` and `banned_by`; each action is written to the PHP error log.

The page also lists **Loadouts in ranked**: how often each loadout wins finished ranked matches, so a build that wins
too often (more than 55% in 1 v 1, or 1.1× its fair share with more tanks, over 30+ matches) stands out. The skill
numbers live in `includes/skills.php`.

## When something goes wrong
- **The battle page never freezes.** If the connection drops, a *Connection lost. Reconnecting…* bar appears
  with a **Reload** button, and the page keeps retrying by itself. If something breaks while a shot is being
  replayed, the page reloads itself from the server (the match is always safe there) and play carries on.
- **The game's server replies are always clean JSON**, even on hosts that print PHP warnings on pages; a fatal
  error (out of time or memory) still answers with a proper error.
- **Problems are recorded** in the `art_errors` table: server exceptions and fatal errors, and script errors
  reported by players' browsers (with the match, the player and the browser). To see the latest:
  `SELECT created_at, source, match_id, message FROM art_errors ORDER BY id DESC LIMIT 20;`

## Settings (`includes/config.php`)
| key | default | meaning |
|---|---|---|
| `turn_seconds` | 40 | shot clock per turn |
| `max_strikes` | 3 | missed turns in a row before removal |
| `wind_max` | 8 | 0 turns wind off everywhere; otherwise each map uses its own wind range |
| `ranked_maps` | 5 maps | map ids that ranked matches rotate through |
| `crate_every` | 5 | a supply crate drops at the start of every Nth turn |
| `crate_max` | 1 | most crates on the field at once |
| `crate_radius` | 15 | centre drops: crates land anywhere inside a circle this wide (metres) around the point between the tanks |
| `crate_drops` | paired | `paired`: one crate per tank, `crate_near` metres from it; `centre`: the old single crate between the tanks |
| `crate_near` | 18 | paired drops: how far each tank's crate lands from it (metres) |
| `move_per_turn` | 10 | metres a tank may drive on its turn before firing; 0 turns driving off |
| `regen_per_turn` | 3 | hp a tank repairs at the start of its own turn (up to 100, until sudden death); 0 turns it off |
| `admins` | none | usernames that can open `admin.php`, e.g. `['david']` |
| `alias_cooldown_hours` | 24 | how often a player may change their shown name |
| `blocked_names` | none | extra words to block in usernames and names |
| `allow_register` | true | set false to close sign-ups |
| `session_name` | ART3DSESS | cookie name |
| `base_url` | null | force the app's URL path if auto-detection is wrong |

## Files
```
index.php            → sends you to the lobby or the login page
login.php / register.php / logout.php
lobby.php            → profile, matchmaking, leaderboard, history
play.php             → the 3D battle page
practice.html        → offline game (no account needed)
install.php          → one-time installer (delete after use)
api/queue.php        → join/leave the ranked or casual queue, matchmaking status
api/room.php         → private rooms: create, join by code, settings, start, leave
join.php             → invite links: joins the room, through login or sign-up if needed
api/item.php         → use the power-up you hold
api/ai.php           → start a battle against computer players
api/clientlog.php    → a browser reports a script error (stored in art_errors)
api/match.php        → match state and new events (polled)
api/shot.php         → fire (server simulates the shot)
api/move.php         → drive before you fire (server plans the route; doesn't end the turn)
admin.php            → admin page: end stuck matches, clear the queue, close rooms, reload code ("restart"); ban or pause players;
                       how often each loadout wins ranked matches
api/forfeit.php      → leave a match
api/loadout.php      → your level, XP and loadout; save a loadout (presets, or a custom build of unlocked skills)
includes/            → config, database, game engine, map recipes, power-ups (items.php), private rooms
                       (rooms.php), computer players (ai.php), skills, loadouts, XP and levels (skills.php),
                       matchmaking (web access blocked)
tests/               → php tests/names_test.php (name filter)
                       php tests/sim_matches.php > /tmp/s.json && node tests/replay_parity.js /tmp/s.json   (replays match the server;
                         the browser's drive preview plans exactly the route the server drives)
                       php tests/crate_gate.php /tmp/s.json   (paired crates: same distance from each tank, nearer its own, reachable;
                         centre crates: in the circle between the tanks)
                       php tests/ai_bench.php [--no-drive]    (computer players: speed, accuracy and driving by skill)
                       php tests/sim_matches.php 1 44 1 --skills > /tmp/k.json && node tests/replay_parity.js /tmp/k.json
                         (the same replay check with every tank on a random loadout)
                       php tests/loadout_bench.php pop 3000 1 > /tmp/b.jsonl && php tests/loadout_report.php /tmp/b.jsonl
                         (phase 4 gate: AI vs AI to ranked rules, win rate of every doctrine, perk, preset and build;
                          add -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M to run ~3× faster)
assets/              → CSS, JS, and a bundled copy of three.js (no CDN needed)
sql/schema.sql       → schema for manual install
```

## Security
- Passwords hashed with `password_hash()` (bcrypt/argon2, depending on your PHP build)
- Every query uses PDO prepared statements
- CSRF tokens on all forms and on every API write
- The session ID is regenerated at login; cookies are HttpOnly and SameSite=Lax, and Secure on HTTPS
- After 8 failed logins from the same IP, logins from it are locked for 15 minutes
- All aim values are clamped and checked on the server; turn order, ammo and held power-ups are enforced in a
  database transaction with row locking
- Invite codes come from `random_int()` (32 characters, 6 long) and only work while the room is open
