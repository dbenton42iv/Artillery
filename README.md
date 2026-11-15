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
- **Maps:** every match is played on one of these (each has its own look, physics and rule):

  | Map | Rule | Gravity | Wind |
  |---|---|---|---|
  | Highlands | Island with a central ridge | 20 | 0–8 m/s |
  | Archipelago | Shells landing in the sea splash: blast ×0.6, no crater | 20 | 0–10 m/s |
  | Canyon | Wind always blows along the canyon | 20 | 6–14 m/s |
  | Volcano | Every 4th turn: 3 lava bombs and the cone grows | 20 | 0–6 m/s |
  | Arctic Shelf | Snow refills craters every turn; falls do ×1.5 damage | 20 | 4–12 m/s |
  | Ruined City | Buildings (120 hp) stop shells until shot down to rubble | 20 | 0–6 m/s |
  | Lunar Base | Low gravity, no wind | 7 | none |

  1 v 1 and 3-tank matches use a 240 m map; 4-tank matches use 360 m.
- **Cover on every map:** no map is flat with a straight line of sight between tanks. The open maps get their own
  features (sea stacks on Archipelago, rock pillars on Canyon, pressure ridges on Arctic Shelf, rubble berms in
  Ruined City, mesas on Lunar Base). After any map is built, every pair of tanks is checked turret to turret, and
  any pair that could see each other gets a ridge of cover, in the map's style, raised between them until the
  ground stands at least 2 m above the line of sight. Buildings don't count, because they can be shot down.
- **Map rotation:** ranked picks at random from a pool of five (Highlands, Archipelago, Canyon, Volcano,
  Arctic Shelf by default; change it with `ranked_maps`). Within a ranked session no map comes back until
  you've played the whole pool, and never twice in a row. A session ends after 30 minutes without a
  ranked match. Ruined City and Lunar Base are played in Casual and Private matches, or add them to the pool.
- **Sudden death:** from turn 30, every tank loses 5 hp per turn and the wind gets 0.5 m/s stronger each turn,
  up to 15 m/s.
- **Supply crates:** a crate parachutes in at the start of every 5th turn, with at most 1 on the field at a time.
  It lands at a random spot inside a 15 m circle around the point between the living tanks: halfway between
  two tanks, or with three or four, the point the same distance from all of them (every match spawns its tanks
  on one shared circle, so that point exists). Change all three with `crate_every`, `crate_max` and
  `crate_radius`. Shoot it:
  a shell exploding within 4 m claims it, and a crate further out but inside a blast is wrecked. Crates are
  sealed: what's inside is decided by the server only at the moment one is claimed, so nobody can know it in
  advance (not even by reading the game's network traffic). Once claimed, everyone sees what it was. You hold
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
  power-ups. A decision takes well under 0.1 s. Their turns run when your page polls, like timeouts (no cron),
  and they wait until your page reports it has finished replaying the last shot. They play from three bot
  accounts (CPU 1–3) that can't log in and never appear on the leaderboard. A match against the AI ends as soon
  as no human tank is left.
- **Power-up log:** every crate drop, claim, wreck and use goes into the `item_log` table, so drop weights
  (in `includes/items.php`) can be tuned against real win rates.
- **Upgrading** from an earlier version: upload the new files. The first request adds the new columns and tables
  (`matches.game_mode`, `effects`; `match_players.item`; `queue.kind`, `map_pref`; `users.is_bot`; `rooms`,
  `room_members`, `item_log`) by itself. Matches already in progress finish under the rules they started with (no crates).
  If your host caches PHP (OPcache), the new files may take a few seconds to be picked up.

## Settings (`includes/config.php`)
| key | default | meaning |
|---|---|---|
| `turn_seconds` | 40 | shot clock per turn |
| `max_strikes` | 3 | missed turns in a row before removal |
| `wind_max` | 8 | 0 turns wind off everywhere; otherwise each map uses its own wind range |
| `ranked_maps` | 5 maps | map ids that ranked matches rotate through |
| `crate_every` | 5 | a supply crate drops at the start of every Nth turn |
| `crate_max` | 1 | most crates on the field at once |
| `crate_radius` | 15 | crates land anywhere inside a circle this wide (metres) around the point between the tanks |
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
api/item.php         → use the power-up you hold
api/ai.php           → start a battle against computer players
api/match.php        → match state and new events (polled)
api/shot.php         → fire (server simulates the shot)
api/forfeit.php      → leave a match
includes/            → config, database, game engine, map recipes, power-ups (items.php), private rooms
                       (rooms.php), computer players (ai.php), matchmaking (web access blocked)
tests/               → php tests/names_test.php (name filter)
                       php tests/sim_matches.php > /tmp/s.json && node tests/replay_parity.js /tmp/s.json   (replays match the server)
                       php tests/crate_gate.php /tmp/s.json   (crates land in the circle between the tanks)
                       php tests/ai_bench.php                 (computer players: speed and accuracy by skill)
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
