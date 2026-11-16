<?php
declare(strict_types=1);
/*
 * The garage: choose how your tank looks (cosmetics.php). Paint or camo, barrel, shell trail, blast and title.
 * Looks only: nothing here changes how a tank plays.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/game.php';
$u = require_login();
page_head('Garage', 'lobby'); topbar($u); ?>
<main class="garage" data-csrf="<?= h(csrf_token()) ?>">
  <section class="panel card garage-top">
    <div class="garage-preview">
      <svg id="gPrev" viewBox="0 0 360 210" role="img" aria-label="Your tank"></svg>
    </div>
    <div>
      <h1 class="hero-title">Garage</h1>
      <p class="muted">How your tank looks to everyone in battle. Looks only: nothing here changes how it plays. Your turret keeps your
        team colour in battle, so everyone can still tell the tanks apart.</p>
      <p class="muted small" id="gLevel"></p>
      <div class="room-btns"><button class="btn" id="gSave" type="button" disabled>Save</button><a class="btn ghost" href="lobby.php">Back to the lobby</a></div>
      <p class="muted small" id="gMsg" aria-live="polite"></p>
    </div>
  </section>
  <?php foreach (['paint' => 'Paint and camo', 'barrel' => 'Barrel', 'trail' => 'Shell trail', 'blast' => 'Blast', 'title' => 'Title'] as $slot => $label): ?>
  <section class="panel card">
    <h2><?= h($label) ?></h2>
    <div class="g-grid" data-slot="<?= h($slot) ?>"><span class="muted">Loading…</span></div>
  </section>
  <?php endforeach; ?>
</main>
<script src="assets/camo.js?v=1"></script>
<script src="assets/garage.js?v=1"></script>
<?php page_foot();
