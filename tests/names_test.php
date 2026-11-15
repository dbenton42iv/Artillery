<?php
/* Command-line only: php tests/names_test.php — checks the name filter against tricky cases. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ART3D', true);
function cfg(string $k, $d = null) { return $k === 'blocked_names' ? ['badword'] : $d; }
require dirname(__DIR__) . '/includes/names.php';

$ok = ['Bob', 'Iron Maiden', 'Sgt. Pepper', 'Bass Player', 'Glasshopper', 'Peacock', 'Hancock', 'Cocktail Hour', 'Scunthorpe FC',
       'Grape Ape', 'Therapist', 'Shiitake', 'Essex Gunner', 'Dickens', 'Classic', 'Assassin', 'Titan', 'Analyst', 'Cumberland',
       'Spicy Shell', 'Raccoon', 'Cocoon', 'Pakistan', 'Japan', 'Hell Fire', 'Shellshock', 'Nigeria 88', 'Space Cadet 88', 'Kraken-9',
       'Mortar_Maria', 'Uranus Rover', 'Big Bertha', 'Ana Lee', 'Sussex', 'Matsushita', 'Mishit Mike'];
$bad = ['fuck', 'FuckFace', 'f_u_c_k', 'fuuuuck', 'Phuk Yeah', 'sh1t', '$hit lord', 'ShitHead', 's.h.i.t', 'BigAss', 'big_ass', 'a.s.s',
        'asshole99', 'Dumbass', 'cunt', 'Bitch', 'b1tch', 'n1gger', 'Faggot', 'Retard', 'Rapist', 'Rape Machine', 'Hitler', 'Nazi Tank',
        'Gas the Kike', 'Heil', 'xx1488xx', 'HH 88', 'Cock', 'Tits', 'Big Dick', 'p0rn star', 'Cum Shot', 'SexyTank', 'Pedo',
        'Badword', 'bad.word', 'admin', 'Site Admin', 'Moderator', 'Official Staff', 'Commander', 'cpu', 'Wanker', 'Twat', 'Piss Off'];
$fail = 0;
foreach ($ok as $n) if (($p = name_problem($n)) !== null) { $fail++; echo "WRONGLY BLOCKED  $n  ($p)\n"; }
foreach ($bad as $n) if (name_problem($n) === null) { $fail++; echo "WRONGLY ALLOWED  $n\n"; }
foreach (['ab', 'a  b', 'Hi!', '_bob', 'x__y', '12345', 'This name is much too long'] as $n) if (name_problem($n) === null) { $fail++; echo "FORMAT MISSED  $n\n"; }
$same = [['Bob', 'B0B'], ['Bob', 'b.o.b'], ['Iron Will', 'lron_Will'], ['Kraken', 'KRAKEN']];
foreach ($same as [$a, $b]) if (name_key($a) !== name_key($b)) { $fail++; echo "LOOK-ALIKE MISSED  $a / $b\n"; }
if (name_problem('Shithead', 'username') === null) { $fail++; echo "USERNAME NOT FILTERED\n"; }
printf("%d allowed, %d blocked, %d look-alike pairs: %s\n", count($ok), count($bad), count($same), $fail ? "$fail FAILED" : 'all correct');
exit($fail ? 1 : 0);
