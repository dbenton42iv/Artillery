<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/game.php';

$u = require_login();
$mid = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT 1 FROM ' . T('match_players') . ' WHERE match_id = ? AND user_id = ?');
$st->execute([$mid, $u['id']]);
if (!$st->fetchColumn()) redirect('lobby.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#1b1f18">
<title>Battle · Artillery 3D</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Black+Ops+One&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/game.css?v=3">
</head>
<body>
<div id="game" data-match="<?= $mid ?>" data-csrf="<?= h(csrf_token()) ?>" data-user="<?= (int)$u['id'] ?>"></div>
<canvas id="c"></canvas>
<div id="labels"></div>

<div id="board" class="panel"><h3 id="roundLbl">MATCH</h3><div id="mapLine"></div><div id="boardStatus"></div><div id="rows"></div></div>

<div id="tr">
  <div id="btns">
    <button class="ibtn" id="bView" title="Overview (V)" aria-label="Overview">⛰<span> Overview</span></button>
    <button class="ibtn" id="bCam" title="Behind tank (C)" aria-label="Behind tank">🎯<span> Behind tank</span></button>
    <button class="ibtn" id="bMute" title="Sound (M)" aria-label="Sound">🔊<span> Sound</span></button>
    <button class="ibtn danger" id="bLeave" aria-label="Leave">🏳<span> Leave</span></button>
  </div>
  <div id="windBox" class="panel"><div class="lbl">WIND</div><div id="windDial"><div id="windArrow"></div></div><div id="windSpd">Calm</div><div id="forecast"></div></div>
</div>

<div id="panel" class="panel off">
  <div id="who"><span id="whoName">…</span><span class="tag" id="whoTag"></span><span id="timer"></span>
    <button id="drawerBtn" type="button" aria-expanded="true" aria-controls="sliders" aria-label="Show or hide the aiming controls"><i></i></button></div>
  <div id="weps" class="ctl"></div>
  <div id="sliders" class="ctl">
    <label for="sYaw">TURRET</label><button class="step" data-k="yaw" data-d="-1" aria-label="Turret left">−</button><input type="range" id="sYaw" min="0" max="359" step="1"><button class="step" data-k="yaw" data-d="1" aria-label="Turret right">+</button><output id="oYaw">0°</output>
    <label for="sElev">ANGLE</label><button class="step" data-k="elev" data-d="-1" aria-label="Lower angle">−</button><input type="range" id="sElev" min="5" max="85" step="0.5"><button class="step" data-k="elev" data-d="1" aria-label="Raise angle">+</button><output id="oElev">45°</output>
    <label for="sPow">POWER</label><button class="step" data-k="power" data-d="-1" aria-label="Less power">−</button><input type="range" id="sPow" class="pow" min="10" max="100" step="0.5"><button class="step" data-k="power" data-d="1" aria-label="More power">+</button><output id="oPow">55</output>
  </div>
  <button id="aimPad" class="ctl" type="button" aria-label="Hold and drag to aim: sideways turns the turret, up and down sets the angle"><b>✥ AIM</b><small>hold &amp; drag</small></button>
  <button id="fire" class="ctl" disabled>FIRE!</button>
</div>

<div id="banner"></div>
<div id="mapIntro" hidden></div>
<div id="hint" class="panel" hidden><span>Turn your phone sideways to see more of the battlefield.</span><button id="hintOk" type="button">OK</button></div>

<div class="ov show" id="ovLoad"><div class="card panel" style="text-align:center">
  <h1>ARTILLERY <span>3D</span></h1><p id="loadMsg">Moving into position…</p>
</div></div>

<div class="ov" id="ovEnd"><div class="card panel">
  <h2 id="eTitle">Battle over</h2>
  <table class="st" id="eTable"></table>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="big" href="lobby.php">BACK TO LOBBY</a>
    <button class="big alt" id="bStay">VIEW BATTLEFIELD</button>
  </div>
</div></div>

<script src="assets/vendor/three.min.js"></script>
<script src="assets/terrain.js?v=3"></script>
<script src="assets/game.js?v=7"></script>
</body>
</html>
