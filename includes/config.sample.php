<?php
/*
 * Artillery 3D configuration.
 * The installer (install.php) writes includes/config.php for you. To configure by hand instead,
 * copy this file to includes/config.php and fill in your MySQL details.
 */
return [
    'db' => [
        'host'   => 'localhost',
        'port'   => 3306,
        'name'   => 'your_database',
        'user'   => 'your_db_user',
        'pass'   => 'your_db_password',
        // Every table name starts with this, so the game can share a database with the rest of your site.
        'prefix' => 'art_',
    ],
    // Leave null to detect the folder automatically (recommended). Set it, e.g. '/games/artillery/',
    // only if auto-detection gets it wrong (unusual symlink or proxy setups).
    'base_url'      => null,
    'session_name'  => 'ART3DSESS',  // its own cookie, so it never collides with your main site's login
    'turn_seconds'  => 40,           // time each player gets per shot
    'max_strikes'   => 3,            // missed turns in a row before a player is removed from the match
    'wind_max'      => 8,            // 0 turns wind off on every map; any other value lets each map use its own wind range
    // Maps that ranked matches rotate through (ids from includes/maps.php). Leave out to use the default pool of five.
    // 'ranked_maps' => ['highlands', 'archipelago', 'canyon', 'volcano', 'arctic'],
    'allow_register'=> true,         // set false to close sign-ups
    'alias_cooldown_hours' => 24,    // how often a player may change the name others see
    // Extra words to block in usernames and names, on top of the built-in list in includes/names.php.
    // 'blocked_names' => ['someword', 'another'],
];
