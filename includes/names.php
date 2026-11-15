<?php
declare(strict_types=1);
defined('ART3D') || exit;
/*
 * Player names: usernames (for logging in) and aliases (the name other players see).
 *
 * name_problem() rejects names that contain profanity or slurs, impersonate staff, or break the
 * format rules. Names are "folded" first: lower case, look-alike digits and symbols turned into
 * letters (sh1t, $hit, @ss), separators dropped (s_h-i.t) and repeated letters squeezed (shiiit),
 * so the usual tricks don't get through.
 *
 * Two lists, to avoid blocking innocent names (the "Scunthorpe problem"):
 *   BAD_ANYWHERE  rejected wherever they appear inside a name
 *   BAD_WORDS     rejected only as a whole word, because they hide inside ordinary words
 *                 (ass in "Bass", cock in "Peacock", rape in "Grape")
 * Site owners can add their own blocked words with 'blocked_names' in config.php.
 */

const BAD_ANYWHERE = [
    'fuck', 'fuk', 'fck', 'phuk', 'phuck', 'motherf', 'shit', 'cunt', 'bitch', 'biatch', 'whore', 'slut', 'bastard',
    'asshole', 'arsehole', 'dumbass', 'jackass', 'fatass', 'smartass', 'dickhead', 'dickface', 'cocksuck', 'douche',
    'piss', 'wank', 'twat', 'bollock', 'jizz', 'penis', 'vagina', 'pussies', 'porn', 'blowjob', 'handjob', 'dildo',
    'molest', 'pedophil', 'paedophil', 'incest', 'rapist', 'nigger', 'nigga', 'faggot', 'fagot', 'tranny',
    'retard', 'wetback', 'beaner', 'kike', 'hitler', 'nazi', 'kkk', 'whitepower', 'heilhitler', 'siegheil', 'bigass', 'kissmyass',
];
const BAD_WORDS = [
    'ass', 'arse', 'asses', 'cock', 'cocks', 'dick', 'dicks', 'pussy', 'tit', 'tits', 'titty', 'titties', 'boob', 'boobs',
    'cum', 'cumming', 'anal', 'anus', 'sex', 'sexy', 'semen', 'nude', 'nudes', 'horny', 'rape', 'raped', 'raping',
    'pedo', 'prick', 'damn', 'goddamn', 'hoe', 'hoes', 'fag', 'fags', 'spic', 'spick', 'chink', 'coon', 'gook', 'dyke',
    'paki', 'jap', 'negro', 'heil', 'sieg',
];
/** Innocent words that contain a BAD_ANYWHERE entry; removed before that check. */
const NAME_ALLOW = [
    'scunthorpe', 'shitake', 'shiitake', 'retardant', 'cockpit', 'cockatoo', 'cocktail', 'peacock', 'hancock',
    'dickens', 'dickson', 'pissarro', 'mishit', 'matsushita', 'niger', 'nigeria', 'bassist', 'passion', 'grape', 'drape', 'therapist',
];
/** Names that would let someone pass as staff or the game itself. */
const NAME_RESERVED = ['admin', 'administrator', 'moderator', 'mod', 'staff', 'support', 'system', 'server', 'official',
                       'owner', 'developer', 'dev', 'cpu', 'bot', 'anonymous', 'deleted', 'commander', 'null', 'undefined'];
const NAME_RESERVED_ANYWHERE = ['admin', 'moderator', 'official'];

/** Lower case with look-alikes mapped to letters. Keeps separators (they mark word edges). */
function name_letters(string $s): string
{
    return strtr(strtolower($s), ['0' => 'o', '1' => 'i', '!' => 'i', '|' => 'i', '3' => 'e', '4' => 'a', '@' => 'a',
                                  '5' => 's', '$' => 's', '7' => 't', '+' => 't', '8' => 'b', '9' => 'g', '6' => 'g', '2' => 'z']);
}
/** Letters only: "S_h-1.t" -> "shit". */
function name_fold(string $s): string { return preg_replace('/[^a-z]/', '', name_letters($s)); }
/** Repeated letters squeezed: "shiiiit" -> "shit". */
function name_squeeze(string $s): string { return preg_replace('/(.)\1+/', '$1', $s); }

/**
 * Comparison key for uniqueness: two names with the same key look alike ("Bob", "B0B", "b.o.b").
 * l and i are merged because they are hard to tell apart in many fonts.
 */
function name_key(string $s): string { return substr(strtr(name_fold($s), ['l' => 'i']), 0, 24); }

/** The words in a name: split on separators, digits and camelCase ("BigAss_99" -> big, ass). */
function name_words(string $s): array
{
    $s = preg_replace('/([a-z])([A-Z])/', '$1 $2', $s);
    $out = [];
    foreach (preg_split('/[^A-Za-z0-9@$!|+]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
        foreach (preg_split('/(?<=[A-Za-z])(?=\d{2,})|(?<=\d)(?=[A-Za-z]{2,})/', $chunk) as $w) {
            $f = name_fold($w);
            if ($f !== '') $out[] = $f;
        }
    }
    return $out;
}

function name_is_clean(string $name): bool
{
    $extra = array_map('name_fold', (array)cfg('blocked_names', []));
    $folded = name_fold($name);
    $strip = fn(string $s) => str_replace(NAME_ALLOW, '', $s);
    foreach ([$folded, name_squeeze($folded)] as $f) {
        $f = $strip($f);
        foreach ([...BAD_ANYWHERE, ...array_filter($extra)] as $bad) if (str_contains($f, $bad)) return false;
    }
    if (preg_match('/14\W?88|88\W?14|hh\W?88|88\W?hh/i', $name)) return false;      // hate-group numbers (plain "88" is fine)
    $words = name_words($name);
    $words[] = $folded;                               // "a.s.s" as a whole
    foreach ($words as $w) {
        foreach ([$w, name_squeeze($w)] as $v) {
            if (in_array($v, BAD_WORDS, true)) return false;
            if (strlen($v) > 1 && str_ends_with($v, 's') && in_array(substr($v, 0, -1), BAD_WORDS, true)) return false;
        }
    }
    return true;
}

function name_is_reserved(string $name): bool
{
    $folded = name_fold($name);
    foreach (NAME_RESERVED_ANYWHERE as $r) if (str_contains($folded, $r)) return true;
    foreach ([...name_words($name), $folded] as $w) if (in_array($w, NAME_RESERVED, true)) return true;
    return false;
}

/**
 * Why a name can't be used, or null if it's fine. $kind is 'username' or 'alias'.
 * Messages never repeat the word that was caught.
 */
function name_problem(string $name, string $kind = 'alias'): ?string
{
    if ($kind === 'username') {
        if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $name)) return 'Username must be 3–20 letters, numbers or underscores.';
    } else {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{1,18}[A-Za-z0-9]$/', $name)) return 'Names are 3–20 characters: letters, numbers, spaces, _ . or -, starting and ending with a letter or number.';
        if (preg_match('/(?!\. )[ _.\-]{2}/', $name)) return "Names can't have two spaces or symbols in a row.";
    }
    if (!preg_match('/[A-Za-z].*[A-Za-z]/', $name)) return 'Names need at least two letters.';
    if (!name_is_clean($name)) return "That name isn't allowed. Please keep names free of profanity and slurs.";
    if (name_is_reserved($name)) return 'That name is reserved. Please choose another.';
    return null;
}

/** Is the name (by its look-alike key) already used by someone else, as a username or an alias? */
function name_taken(string $name, int $exceptUserId = 0): bool
{
    $key = name_key($name);
    $st = db()->prepare('SELECT 1 FROM ' . T('users') . ' WHERE id <> ? AND (username = ? OR alias = ? OR username_key = ? OR alias_key = ?) LIMIT 1');
    $st->execute([$exceptUserId, $name, $name, $key, $key]);
    return (bool)$st->fetchColumn();
}

/**
 * The name other players see: the alias if set, otherwise the username. A username that fails today's
 * filter (registered before it existed) is shown as "Commander #id" until its owner picks an alias.
 */
function display_name(array $u): string
{
    if (!empty($u['alias'])) return (string)$u['alias'];
    $name = (string)($u['username'] ?? '');
    return name_is_clean($name) ? $name : 'Commander #' . (int)($u['id'] ?? $u['user_id'] ?? 0);
}
