/* Camo patterns for tank paint (cosmetics): drawn on a canvas, the same way in the garage preview and in battle.
 * Seeded, so a pattern always looks the same. window.ARTCAMO.canvas(kind, colors, size) -> <canvas>. */
(function () {
  function rng(seed) { return function () { seed |= 0; seed = seed + 0x6D2B79F5 | 0; let t = Math.imul(seed ^ seed >>> 15, 1 | seed);
    t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
  function canvas(kind, colors, size) {
    size = size || 256;
    const c = document.createElement('canvas'); c.width = c.height = size; const g = c.getContext('2d'), R = rng(kind.length * 977 + 13), s = size / 256;
    g.fillStyle = colors[0]; g.fillRect(0, 0, size, size);
    const pick = () => colors[1 + Math.floor(R() * (colors.length - 1))];
    if (kind === 'blobs') {
      for (let i = 0; i < 46; i++) { g.fillStyle = pick(); g.beginPath();
        const x = R() * size, y = R() * size, rx = (14 + R() * 30) * s, ry = (8 + R() * 18) * s;
        for (let k = 0; k <= 10; k++) { const a = k / 10 * Math.PI * 2, j = .75 + R() * .5; g.lineTo(x + Math.cos(a) * rx * j, y + Math.sin(a) * ry * j); }
        g.fill(); }
    } else if (kind === 'waves') {
      for (let i = 0; i < 14; i++) { g.strokeStyle = pick(); g.lineWidth = (6 + R() * 12) * s; g.beginPath();
        const y0 = R() * size, A = (6 + R() * 10) * s, f = .02 + R() * .03, ph = R() * 6;
        for (let x = -10; x <= size + 10; x += 6) g.lineTo(x, y0 + Math.sin(x * f / s + ph) * A); g.stroke(); }
    } else if (kind === 'shards') {
      for (let i = 0; i < 60; i++) { g.fillStyle = pick(); g.beginPath(); const x = R() * size, y = R() * size, r = (10 + R() * 26) * s;
        for (let k = 0; k < 3; k++) { const a = R() * Math.PI * 2; g.lineTo(x + Math.cos(a) * r, y + Math.sin(a) * r); } g.fill(); }
    } else if (kind === 'blocks') {
      const q = 16 * s;
      for (let i = 0; i < 140; i++) { g.fillStyle = pick(); const x = Math.floor(R() * 16) * q, y = Math.floor(R() * 16) * q;
        g.fillRect(x, y, q * (1 + Math.floor(R() * 3)), q * (1 + Math.floor(R() * 2))); }
    } else if (kind === 'cracks') {
      g.fillStyle = colors[1]; for (let i = 0; i < 30; i++) { g.beginPath(); g.arc(R() * size, R() * size, (10 + R() * 22) * s, 0, 7); g.fill(); }
      for (let i = 0; i < 22; i++) { g.strokeStyle = i % 3 ? colors[2] : colors[3]; g.lineWidth = (1.5 + R() * 2.5) * s; g.beginPath();
        let x = R() * size, y = R() * size; g.moveTo(x, y);
        for (let k = 0; k < 6; k++) { x += (R() - .5) * 50 * s; y += (R() - .5) * 50 * s; g.lineTo(x, y); } g.stroke(); }
    } else if (kind === 'craters') {
      for (let i = 0; i < 34; i++) { const x = R() * size, y = R() * size, r = (5 + R() * 20) * s;
        g.fillStyle = colors[1]; g.beginPath(); g.arc(x, y, r, 0, 7); g.fill();
        g.strokeStyle = colors[2]; g.lineWidth = 2 * s; g.beginPath(); g.arc(x - r * .15, y - r * .15, r, 3.4, 5.6); g.stroke(); }
    }
    return c;
  }
  window.ARTCAMO = { canvas };
})();
