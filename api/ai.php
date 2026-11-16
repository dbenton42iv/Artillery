<?php
declare(strict_types=1);
/*
 * Start an unrated battle against computer players.
 *   POST {"map": "ranked" | map id, "bots": 1–3, "level": "rookie" | "veteran" | "ace", "items": "safe" | "all" | "off"}
 *   -> {"match_id"}
 * "ranked" picks a random map from the ranked pool. Ratings, wins and matches played never change.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/game.php';
require_once dirname(__DIR__) . '/includes/ai.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'post_only'], 405);
$u = api_user(true);
$res = ai_start($u, json_body());
json_out($res, isset($res['error']) ? 409 : 200);
