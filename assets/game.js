/*
 * Artillery 3D: online client.
 * The server is the referee. This page renders the battlefield, sends your aim when you fire,
 * and replays each event exactly as the server computed it (flight paths, craters, damage).
 * Terrain changes arrive as an ordered "ops" list and are applied with assets/terrain.js, the
 * twin of the server's Terrain class, so this page's heightmap always equals the server's.
 * Physics constants must match includes/engine.php.
 */
(() => {
'use strict';
const $ = s => document.querySelector(s);
const AT = window.ArtTerrain;
const ROOT = $('#game'), MID = +ROOT.dataset.match, CSRF = ROOT.dataset.csrf;
const COLORS = [
  { hex: 0xe5484d, css: '#e5484d' }, { hex: 0x3e8ef7, css: '#3e8ef7' },
  { hex: 0xf5b400, css: '#f5b400' }, { hex: 0xb26cf5, css: '#b26cf5' }];
const LAVA = { hex: 0xff6a1a, css: '#ff8a3a' };
const WEAPONS = {
  shell:   { name: 'Shell',   key: '1', size: .45 },
  heavy:   { name: 'Heavy',   key: '2', size: .7 },
  cluster: { name: 'Cluster', key: '3', size: .5 }};
const WKEYS = Object.keys(WEAPONS);
const VMAX = 75, STEP = 1 / 120, PATH_EVERY = 4, TIME_SCALE = 1.45, TURRET_H = 2.05, BARREL = 3.2, D2R = Math.PI / 180;
// set from the match's map when it loads
let G = 20, N = 160, SIZE = 240, HALF = 120, WATER = 1.6, MS = 1, RULES = null, MAP = 'highlands';

/* =====================================================================
 * Device: phone, tablet or desktop, touch or mouse. Re-checked on rotate/resize.
 * ===================================================================== */
const mqCoarse = matchMedia('(pointer: coarse)'), mqHover = matchMedia('(hover: hover)');
const DEV = { layout: 'desktop', touch: false, portrait: false, phone: false };
function detectDevice() {
  const w = innerWidth, h = innerHeight, short = Math.min(w, h);
  DEV.touch = mqCoarse.matches || (!mqHover.matches && 'ontouchstart' in window);
  DEV.portrait = h > w;
  DEV.layout = w < 640 || (DEV.touch && short < 500) ? 'phone' : w <= 1024 ? 'tablet' : 'desktop';
  DEV.phone = DEV.layout === 'phone';
  const de = document.documentElement.dataset;
  de.layout = DEV.layout; de.input = DEV.touch ? 'touch' : 'mouse'; de.orient = DEV.portrait ? 'portrait' : 'landscape';
}
detectDevice();

/* ---------- renderer / scene ---------- */
const canvas = $('#c');
const renderer = new THREE.WebGLRenderer({ canvas, antialias: !DEV.phone, powerPreference: DEV.phone ? 'low-power' : 'default' });
renderer.shadowMap.enabled = true; renderer.shadowMap.type = THREE.PCFSoftShadowMap;
const scene = new THREE.Scene();
const camera = new THREE.PerspectiveCamera(55, 1, 0.5, 2400);
const skyMat = new THREE.ShaderMaterial({
  side: THREE.BackSide, depthWrite: false, fog: false,
  uniforms: { top: { value: new THREE.Color(0x3f78bd) }, bot: { value: new THREE.Color(0xd9e4e0) } },
  vertexShader: 'varying vec3 vP;void main(){vP=normalize(position);gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.0);}',
  fragmentShader: 'uniform vec3 top;uniform vec3 bot;varying vec3 vP;void main(){float h=clamp(vP.y*1.8+0.05,0.0,1.0);gl_FragColor=vec4(mix(bot,top,h),1.0);}' });
const sky = new THREE.Mesh(new THREE.SphereGeometry(1400, 32, 16), skyMat); scene.add(sky);
const hemi = new THREE.HemisphereLight(0xe4f0ff, 0x4a5530, 0.72); scene.add(hemi);
const sun = new THREE.DirectionalLight(0xfff0d0, 1.0);
sun.position.set(-90, 150, 70); sun.castShadow = true; sun.shadow.bias = -0.0006; scene.add(sun);
const flash = new THREE.PointLight(0xffa040, 0, 80, 2); scene.add(flash);
const water = new THREE.Mesh(new THREE.PlaneGeometry(4000, 4000), new THREE.MeshStandardMaterial({ color: 0x2c6a8a, roughness: .25, metalness: .1, transparent: true, opacity: .86 }));
water.rotation.x = -Math.PI / 2; water.receiveShadow = true; scene.add(water);

function applyQuality() {
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, DEV.phone ? 1.5 : 2));
  const sm = DEV.phone || (DEV.touch && DEV.layout === 'tablet') ? 1024 : 2048;
  if (sun.shadow.mapSize.x !== sm) { sun.shadow.mapSize.set(sm, sm); if (sun.shadow.map) { sun.shadow.map.dispose(); sun.shadow.map = null; } }
}
applyQuality();

/* =====================================================================
 * Map looks: sky, light, water, ground palette and props. All chosen by map id.
 * ===================================================================== */
const THEMES = {
  highlands:   { top: 0x3f78bd, hor: 0xd9e4e0, fog: [260, 700], sun: 0xfff0d0, sunI: 1.0, hemi: [0xe4f0ff, 0x4a5530, .72], water: 0x2c6a8a, waterOp: .86, pal: 'green',  props: 'pines' },
  archipelago: { top: 0x2b84d8, hor: 0xd3f0f2, fog: [280, 760], sun: 0xfff6dc, sunI: 1.1, hemi: [0xeaf8ff, 0x3f6a40, .75], water: 0x1d8aa0, waterOp: .84, pal: 'tropic', props: 'palms' },
  canyon:      { top: 0x4f8bd2, hor: 0xf2dcbc, fog: [240, 680], sun: 0xffdcae, sunI: 1.1, hemi: [0xfff0da, 0x7a4a2a, .7],  water: 0x3a6d6a, waterOp: .85, pal: 'desert', props: 'rocks' },
  volcano:     { top: 0x2f272b, hor: 0x8a6656, fog: [170, 560], sun: 0xffb088, sunI: .85, hemi: [0xd8b8a8, 0x3a2420, .62], water: 0x283f48, waterOp: .9,  pal: 'ash',    props: 'deadtrees' },
  arctic:      { top: 0x6b9dcf, hor: 0xe9f2f7, fog: [220, 640], sun: 0xffffff, sunI: .95, hemi: [0xeaf4ff, 0x8aa0b0, .85], water: 0x1f4b68, waterOp: .9,  pal: 'ice',    props: 'shards' },
  city:        { top: 0x667888, hor: 0xcdc6b8, fog: [200, 620], sun: 0xfff0dc, sunI: .9,  hemi: [0xdde3ea, 0x55504a, .7],  water: 0x34525c, waterOp: .9,  pal: 'urban',  props: 'wrecks' },
  lunar:       { top: 0x000000, hor: 0x08090d, fog: null,       sun: 0xffffff, sunI: 1.15, hemi: [0x9aa4b8, 0x101014, .22], water: null,     waterOp: 0,   pal: 'moon',   props: 'base', stars: true },
};
let TH = THEMES.highlands;
function applyTheme() {
  TH = THEMES[MAP] || THEMES.highlands;
  skyMat.uniforms.top.value.setHex(TH.top); skyMat.uniforms.bot.value.setHex(TH.hor);
  scene.fog = TH.fog ? new THREE.Fog(TH.hor, TH.fog[0] * MS, TH.fog[1] * MS) : null;
  sun.color.setHex(TH.sun); sun.intensity = TH.sunI;
  hemi.color.setHex(TH.hemi[0]); hemi.groundColor.setHex(TH.hemi[1]); hemi.intensity = TH.hemi[2];
  const ext = HALF + 25;
  Object.assign(sun.shadow.camera, { left: -ext, right: ext, top: ext, bottom: -ext, near: 10, far: 450 * MS });
  sun.position.set(-90 * MS, 150 * MS, 70 * MS); sun.shadow.camera.updateProjectionMatrix();
  water.visible = WATER !== null; if (water.visible) { water.position.y = WATER; water.material.color.setHex(TH.water); water.material.opacity = TH.waterOp; }
  if (TH.stars) {
    const n = 1400, p = new Float32Array(n * 3);
    for (let i = 0; i < n; i++) { const u = Math.random()*2 - 1, a = Math.random()*Math.PI*2, r = Math.sqrt(1 - u*u);
      p[i*3] = Math.cos(a)*r*1300; p[i*3+1] = Math.abs(u)*1300 + 20; p[i*3+2] = Math.sin(a)*r*1300; }
    const g = new THREE.BufferGeometry(); g.setAttribute('position', new THREE.BufferAttribute(p, 3));
    scene.add(new THREE.Points(g, new THREE.PointsMaterial({ color: 0xffffff, size: 1.6, sizeAttenuation: false, fog: false })));
    const earth = new THREE.Mesh(new THREE.SphereGeometry(60, 32, 16), new THREE.MeshBasicMaterial({ color: 0x3d78c0, fog: false }));
    earth.position.set(700, 520, -900); scene.add(earth);
  }
}

/* ---------- terrain ---------- */
let VT = null, AUTH = null, H = null, scorch = null, jit = null, tGeo = null, terrain = null;
const clamp = (x, a, b) => Math.min(b, Math.max(a, x));
const heightAt = (x, z) => VT ? AT.heightAt(VT, x, z) : 0;
function mixc(c, t, b) { t = clamp(t, 0, 1); c[0] += (b[0]-c[0])*t; c[1] += (b[1]-c[1])*t; c[2] += (b[2]-c[2])*t; }
const BURN = [.13, .11, .09];
const PALETTES = {
  green(c, h, ny, j) { const W = WATER;
    c[0] = .29; c[1] = .43; c[2] = .19;
    mixc(c, (h-4)/22 + j*4, [.44,.48,.26]); mixc(c, (h-26)/10, [.52,.49,.45]); mixc(c, (h-46)/5, [.93,.94,.96]);
    mixc(c, (.93-ny)/.2, h > 30 ? [.52,.49,.45] : [.45,.37,.27]); mixc(c, (W+1.1-h)/.8, [.78,.71,.52]); mixc(c, (W-h)/1.1, [.12,.27,.33]); },
  tropic(c, h, ny, j) { const W = WATER;
    c[0] = .25; c[1] = .52; c[2] = .2;
    mixc(c, (h-6)/8 + j*4, [.36,.5,.22]); mixc(c, (.9-ny)/.2, [.5,.42,.3]);
    mixc(c, (W+2.2-h)/1.2, [.93,.86,.66]); mixc(c, (W-h)/1.4, [.35,.75,.72]); },
  desert(c, h, ny, j) { const W = WATER, band = .5 + .5*Math.sin(h*.85 + j*12);
    c[0] = .78; c[1] = .57; c[2] = .37;
    mixc(c, band*.55, [.66,.4,.27]); mixc(c, (.9-ny)/.25, [.56,.32,.22]);
    mixc(c, (W+1.6-h)/1.2, [.62,.55,.42]); mixc(c, (W-h)/1.1, [.22,.36,.33]); },
  ash(c, h, ny, j) { const W = WATER;
    c[0] = .3; c[1] = .27; c[2] = .25;
    mixc(c, (h-10)/14 + j*3, [.18,.16,.16]); mixc(c, (.9-ny)/.25, [.12,.11,.11]);
    mixc(c, (W+1.2-h)/.9, [.36,.33,.3]); mixc(c, (W-h)/1.1, [.14,.2,.24]); },
  ice(c, h, ny, j) { const W = WATER;
    c[0] = .93; c[1] = .95; c[2] = .98;
    mixc(c, (4.2-h)/1.6 - j*3, [.76,.87,.95]); mixc(c, (.92-ny)/.18, [.6,.72,.84]);
    mixc(c, (W+1.4-h)/1.0, [.5,.66,.78]); mixc(c, (W-h)/1.0, [.12,.25,.38]); },
  urban(c, h, ny, j) { const W = WATER;
    c[0] = .47; c[1] = .47; c[2] = .46;
    mixc(c, .3 + j*7, [.52,.48,.41]); mixc(c, (.92-ny)/.2, [.38,.36,.33]);
    mixc(c, (W+1.2-h)/.9, [.46,.44,.4]); mixc(c, (W-h)/1.1, [.16,.24,.27]); },
  moon(c, h, ny, j) {
    c[0] = .44; c[1] = .44; c[2] = .45;
    mixc(c, (4.2-h)/3 + j*3, [.3,.3,.32]); mixc(c, (.9-ny)/.25, [.56,.56,.58]); mixc(c, (h-8)/4, [.5,.5,.52]); },
};
function initTerrain(heights) {
  VT = AT.make(N); H = VT.H; H.set(heights);
  AUTH = AT.make(N); AUTH.H.set(heights);
  scorch = new Float32Array(H.length); jit = new Float32Array(H.length);
  for (let k = 0; k < jit.length; k++) jit[k] = Math.random() * .08 - .04;
  tGeo = new THREE.PlaneGeometry(SIZE, SIZE, N, N); tGeo.rotateX(-Math.PI / 2);
  tGeo.setAttribute('color', new THREE.BufferAttribute(new Float32Array(H.length * 3), 3));
  terrain = new THREE.Mesh(tGeo, new THREE.MeshStandardMaterial({ vertexColors: true, roughness: .95, metalness: 0 }));
  terrain.receiveShadow = true; terrain.castShadow = true; scene.add(terrain);
  updateTerrainMesh();
}
function updateTerrainMesh() {
  const pos = tGeo.attributes.position.array, pal = PALETTES[TH.pal] || PALETTES.green;
  for (let k = 0; k < H.length; k++) pos[k*3+1] = H[k];
  tGeo.attributes.position.needsUpdate = true; tGeo.computeVertexNormals();
  const nrm = tGeo.attributes.normal.array, col = tGeo.attributes.color.array, c = [0, 0, 0];
  for (let k = 0; k < H.length; k++) {
    pal(c, H[k], nrm[k*3+1], jit[k]);
    mixc(c, Math.min(.88, scorch[k]), BURN);
    const jj = jit[k]*.6; col[k*3] = c[0]+jj; col[k*3+1] = c[1]+jj; col[k*3+2] = c[2]+jj*.5;
  }
  tGeo.attributes.color.needsUpdate = true; tGeo.computeBoundingSphere();
}
/** Visual crater as a shell lands (the exact heightmap comes from AUTH at the end of the event). */
function crater(cx, cz, R) {
  AT.crater(VT, cx, cz, R);
  const w = N + 1, Rs = R * 1.5;
  const i0 = Math.max(0, Math.floor((cx-Rs+HALF)/AT.CELL)), i1 = Math.min(N, Math.ceil((cx+Rs+HALF)/AT.CELL));
  const j0 = Math.max(0, Math.floor((cz-Rs+HALF)/AT.CELL)), j1 = Math.min(N, Math.ceil((cz+Rs+HALF)/AT.CELL));
  for (let j = j0; j <= j1; j++) for (let i = i0; i <= i1; i++) {
    const d = Math.hypot(-HALF + i*AT.CELL - cx, -HALF + j*AT.CELL - cz);
    if (d < Rs) scorch[j*w+i] = Math.min(1, scorch[j*w+i] + (1 - d/Rs) * 1.1);
  }
  updateTerrainMesh();
}
/** Copy the exact (server-equal) heightmap into the visible one. */
function syncTerrain() { H.set(AUTH.H); updateTerrainMesh(); props.reground(); }

/* ---------- props: decoration only, chosen by map, never affect play ---------- */
const props = (() => {
  const items = [], meshes = []; let parts = [];
  const m4 = new THREE.Matrix4(), q = new THREE.Quaternion(), e = new THREE.Euler(), v = new THREE.Vector3(), sv = new THREE.Vector3();
  const std = (color, o = {}) => new THREE.MeshStandardMaterial({ color, roughness: .85, ...o });
  const KINDS = {
    pines:  () => [{ geo: new THREE.CylinderGeometry(.18, .25, 1.6, 6), mat: std(0x5a3d26), y: .8 },
                   { geo: new THREE.ConeGeometry(1.3, 3.4, 7), mat: std(0x2f5a2a), y: 2.9 },
                   { geo: new THREE.ConeGeometry(.95, 2.4, 7), mat: std(0x386a30), y: 4.1 }],
    palms:  () => [{ geo: new THREE.CylinderGeometry(.14, .22, 5, 6), mat: std(0x8a6a44), y: 2.5, rz: .12 },
                   { geo: new THREE.ConeGeometry(2.2, .9, 6), mat: std(0x3f8a3a), y: 5.1, x: .3 }],
    rocks:  () => [{ geo: new THREE.DodecahedronGeometry(1.2, 0), mat: std(0x8a5a3e, { flatShading: true }), y: .5, sy: .7 }],
    deadtrees: () => [{ geo: new THREE.CylinderGeometry(.1, .2, 3.2, 5), mat: std(0x241c18), y: 1.6, rz: .1 },
                      { geo: new THREE.CylinderGeometry(.05, .09, 1.4, 4), mat: std(0x241c18), y: 2.6, x: .4, rz: .9 }],
    shards: () => [{ geo: new THREE.ConeGeometry(.6, 4.2, 5), mat: std(0xbfe4f5, { roughness: .2, metalness: .1, transparent: true, opacity: .85, flatShading: true }), y: 1.6, rz: .18 },
                   { geo: new THREE.ConeGeometry(.4, 2.6, 5), mat: std(0xd6effa, { roughness: .2, transparent: true, opacity: .85, flatShading: true }), y: 1.0, x: .8, rz: -.35 }],
    wrecks: () => [{ geo: new THREE.BoxGeometry(4.2, 1.6, .7), mat: std(0x6d6860), y: .6, rx: .05 },
                   { geo: new THREE.BoxGeometry(1.6, 1.1, 3.2), mat: std(0x3a2e28), y: .55, x: 3.4, ry: .5 }],
    base:   () => [{ geo: new THREE.SphereGeometry(2.6, 16, 8, 0, Math.PI*2, 0, Math.PI/2), mat: std(0xdfe2e8, { roughness: .4, metalness: .3 }), y: 0 },
                   { geo: new THREE.CylinderGeometry(.08, .08, 5, 5), mat: std(0x9aa0a8, { metalness: .6 }), y: 2.5, x: 3.5 },
                   { geo: new THREE.CylinderGeometry(1.2, 1.2, .15, 12), mat: std(0x9aa0a8, { metalness: .6 }), y: 5, x: 3.5, rx: .6 }],
  };
  const RULE = {   // where each kind may stand: [count per 240 m map, min height above water, max height, min flatness]
    pines: [150, 1.5, 32, .86], palms: [70, .6, 9, .85], rocks: [70, 4, 60, .7], deadtrees: [70, 1.5, 22, .8],
    shards: [90, 1.2, 20, .8], wrecks: [40, 1, 20, .9], base: [9, 1, 30, .93] };
  function build(kind, avoid) {
    const r = RULE[kind]; if (!r) return;
    parts = KINDS[kind]();
    const want = Math.round(r[0] * MS * MS * (DEV.phone ? .6 : 1)), W = WATER ?? -100;
    for (let tries = 0; items.length < want && tries < want * 40; tries++) {
      const x = (Math.random()*2 - 1)*HALF*.92, z = (Math.random()*2 - 1)*HALF*.92, y = heightAt(x, z);
      if (y < W + r[1] || y > r[2]) continue;
      const nx = heightAt(x + 1, z) - heightAt(x - 1, z), nz = heightAt(x, z + 1) - heightAt(x, z - 1);
      if (2 / Math.hypot(nx, 2, nz) < r[3]) continue;
      if (avoid.some(a => Math.hypot(a[0] - x, a[1] - z) < a[2])) continue;
      if (kind === 'base' && items.some(it => Math.hypot(it.x - x, it.z - z) < 30)) continue;
      items.push({ x, z, y, s: .7 + Math.random()*.6, ry: Math.random()*Math.PI*2, alive: true });
    }
    for (const p of parts) {
      const im = new THREE.InstancedMesh(p.geo, p.mat, items.length); im.castShadow = !DEV.phone; im.receiveShadow = true;
      scene.add(im); meshes.push({ im, p });
    }
    refresh();
  }
  function refresh() {
    for (const { im, p } of meshes) {
      items.forEach((it, k) => {
        const s = it.alive ? it.s : 0;
        e.set(p.rx || 0, it.ry + (p.ry || 0), p.rz || 0); q.setFromEuler(e);
        v.set(p.x || 0, p.y, 0).multiplyScalar(s).applyAxisAngle(new THREE.Vector3(0, 1, 0), it.ry).add(sv.set(it.x, it.y, it.z));
        m4.compose(v, q, sv.set(s, s * (p.sy || 1), s)); im.setMatrixAt(k, m4);
      });
      im.instanceMatrix.needsUpdate = true;
    }
  }
  return {
    build,
    blast(x, z, R) { let ch = false; for (const it of items) if (it.alive && Math.hypot(it.x - x, it.z - z) < R) { it.alive = false; ch = true; } if (ch) refresh(); },
    reground() { if (!items.length) return; for (const it of items) it.y = heightAt(it.x, it.z) - .1; refresh(); },
  };
})();

/* ---------- buildings (Ruined City) ---------- */
let OBJ = [];
const winTex = (() => {
  const c = document.createElement('canvas'); c.width = 64; c.height = 64; const g = c.getContext('2d');
  g.fillStyle = '#8e8a82'; g.fillRect(0, 0, 64, 64);
  for (let y = 0; y < 4; y++) for (let x = 0; x < 4; x++) { g.fillStyle = Math.random() < .3 ? '#2a2724' : '#3b4148'; g.fillRect(4 + x*16, 5 + y*16, 9, 9); }
  const t = new THREE.CanvasTexture(c); t.wrapS = t.wrapT = THREE.RepeatWrapping; return t;
})();
function buildObjects(list) {
  OBJ = list.map(o => {
    const tex = winTex.clone(); tex.needsUpdate = true; tex.repeat.set(Math.max(1, Math.round(o.hw*2/4)), Math.max(1, Math.round(o.h/4)));
    const tint = new THREE.Color().setHSL(.08, .08, .55 + Math.random()*.15);
    const mat = new THREE.MeshStandardMaterial({ map: tex, color: tint, roughness: .9 });
    const g = new THREE.Group();
    const box = new THREE.Mesh(new THREE.BoxGeometry(o.hw*2, o.h, o.hd*2), mat); box.position.y = o.h/2; g.add(box);
    const roof = new THREE.Mesh(new THREE.BoxGeometry(o.hw*2 + .4, .5, o.hd*2 + .4), new THREE.MeshStandardMaterial({ color: 0x4d4943, roughness: 1 }));
    roof.position.y = o.h + .25; g.add(roof);
    g.position.set(o.x, o.y, o.z);
    g.traverse(m => { if (m.isMesh) { m.castShadow = true; m.receiveShadow = true; } });
    scene.add(g);
    const O = { ...o, group: g, mat, tint, gone: false };
    if (o.hp <= 0) { g.visible = false; O.gone = true; } else shade(O);
    return O;
  });
}
function shade(O) { const k = O.hp / O.max; O.mat.color.copy(O.tint).multiplyScalar(.45 + .55*k); O.group.rotation.z = (1 - k) * .03; }
function damageObject(r) {
  const O = OBJ[r.id]; if (!O || O.gone) return;
  O.hp = Math.max(0, O.hp - r.dmg);
  popup(`-${r.dmg}`, new THREE.Vector3(O.x, O.y + O.h + 2, O.z), '#d8d2c4');
  if (O.hp > 0) { shade(O); return; }
  O.gone = true; O.group.visible = false;
  const pos = new THREE.Vector3(O.x, O.y + O.h*.4, O.z);
  spawnSmoke(pos, O.h*1.2, 3.5); spawnSmoke(pos.clone().setY(O.y + 2), O.h, 4.5); sndBoom(.8);
  spawnDebris(pos, Math.max(O.hw, O.hd)*2, 0x7a746a);
  AT.raise(VT, O.x, O.z, Math.max(O.hw, O.hd)*1.6, Math.min(3, O.h*.18), 2); updateTerrainMesh();
  banner('Building down!', 1200);
}

/* ---------- volcano vent ---------- */
let vent = null;
function buildVent() {
  if (!RULES.vent) return;
  const [x, z] = RULES.vent;
  const glow = new THREE.Mesh(new THREE.CircleGeometry(3.2*MS, 20), new THREE.MeshBasicMaterial({ color: 0xff5a14, transparent: true, opacity: .9 }));
  glow.rotation.x = -Math.PI/2; scene.add(glow);
  const light = new THREE.PointLight(0xff6a2a, 2.2, 60*MS, 2); scene.add(light);
  vent = { x, z, glow, light, t: 0 };
  placeVent();
}
function placeVent() { if (!vent) return; const y = heightAt(vent.x, vent.z) + .25; vent.glow.position.set(vent.x, y, vent.z); vent.light.position.set(vent.x, y + 4, vent.z); }
function updateVent(dt, time) {
  if (!vent) return;
  vent.glow.material.opacity = .75 + Math.sin(time*3)*.15; vent.light.intensity = 2 + Math.sin(time*2.3)*.5;
  vent.t -= dt;
  if (vent.t <= 0) { vent.t = DEV.phone ? 1.2 : .6; spawnSmoke(new THREE.Vector3(vent.x + (Math.random()-.5)*3, heightAt(vent.x, vent.z) + 3, vent.z + (Math.random()-.5)*3), 6*MS, 7, 0x3e3836, 4); }
}

/* ---------- tanks / markers / trails ---------- */
function makeTank(color) {
  const g = new THREE.Group();
  const mat = new THREE.MeshStandardMaterial({ color, roughness: .55, metalness: .25 });
  const dark = new THREE.MeshStandardMaterial({ color: 0x2a2b28, roughness: .9 });
  const hull = new THREE.Group(); g.add(hull);
  const body = new THREE.Mesh(new THREE.BoxGeometry(3.1, 1.05, 4.2), mat); body.position.y = .95; hull.add(body);
  const front = new THREE.Mesh(new THREE.BoxGeometry(3.1, .6, .9), mat); front.position.set(0, 1.0, -2.3); front.rotation.x = .5; hull.add(front);
  for (const sx of [-1, 1]) { const t = new THREE.Mesh(new THREE.BoxGeometry(.85, .95, 4.7), dark); t.position.set(sx*1.65, .48, 0); hull.add(t); }
  const turret = new THREE.Group(); turret.position.y = 1.5; g.add(turret);
  turret.add(new THREE.Mesh(new THREE.SphereGeometry(1.15, 22, 12, 0, Math.PI*2, 0, Math.PI/2), mat));
  const pivot = new THREE.Group(); pivot.position.y = .55; turret.add(pivot);
  const barrel = new THREE.Mesh(new THREE.CylinderGeometry(.19, .24, BARREL, 12), dark); barrel.rotation.x = -Math.PI/2; barrel.position.z = -BARREL/2; pivot.add(barrel);
  g.traverse(o => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; } });
  scene.add(g);
  return { group: g, hull, turret, pivot, mat };
}
const marker = new THREE.Mesh(new THREE.ConeGeometry(.9, 1.8, 4), new THREE.MeshBasicMaterial({ color: 0xffffff }));
marker.rotation.x = Math.PI; marker.visible = false; scene.add(marker);
class Trail {
  constructor(color, opacity = .85) { this.max = 5000; this.arr = new Float32Array(this.max*3); this.n = 0;
    this.geo = new THREE.BufferGeometry(); this.geo.setAttribute('position', new THREE.BufferAttribute(this.arr, 3)); this.geo.setDrawRange(0, 0);
    this.line = new THREE.Line(this.geo, new THREE.LineBasicMaterial({ color, transparent: true, opacity })); this.line.frustumCulled = false; scene.add(this.line); }
  push(x, y, z) { if (this.n >= this.max) return; const i = this.n*3; this.arr[i] = x; this.arr[i+1] = y; this.arr[i+2] = z; this.n++;
    this.geo.attributes.position.needsUpdate = true; this.geo.setDrawRange(0, this.n); }
  dispose() { scene.remove(this.line); this.geo.dispose(); this.line.material.dispose(); }
}
const guide = new Trail(0xffffff, .75);
let envTrails = [];

/* ---------- audio ---------- */
let actx = null, muted = false;
function ac() { if (!actx) { try { actx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {} } return actx; }
function noise(dur, freq, gain, curve = 2.5) {
  const a = ac(); if (!a || muted || a.state !== 'running') return;
  try { const len = Math.floor(a.sampleRate*dur), buf = a.createBuffer(1, len, a.sampleRate), d = buf.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = (Math.random()*2-1) * Math.pow(1 - i/len, curve);
    const s = a.createBufferSource(); s.buffer = buf; const f = a.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = freq;
    const g = a.createGain(); g.gain.value = gain; s.connect(f); f.connect(g); g.connect(a.destination); s.start(); } catch (e) {}
}
addEventListener('pointerdown', () => { const a = ac(); if (a && a.state === 'suspended') a.resume(); });
const sndFire = () => noise(.35, 1400, .45, 3), sndBoom = s => noise(.5 + s*.9, 260 + s*380, .7 + s*.3, 2.2), sndSplash = () => noise(.7, 3200, .35, 1.6);

/* ---------- effects ---------- */
const effects = [], pops = [];
const fireGeo = new THREE.SphereGeometry(1, 20, 14), debrisGeo = new THREE.BoxGeometry(.5, .5, .5), colGeo = new THREE.CylinderGeometry(.6, 1, 1, 14, 1, true);
const debrisAmount = () => DEV.phone ? .45 : 1;
function addFlash(pos, s) { flash.position.copy(pos); flash.position.y += 2; flash.intensity = Math.max(flash.intensity, 3*s); }
function spawnSmoke(pos, R, dur, color = 0x5a5a55, rise = 2.2) {
  const m = new THREE.Mesh(fireGeo, new THREE.MeshStandardMaterial({ color, transparent: true, opacity: .35, depthWrite: false, roughness: 1 }));
  m.position.copy(pos); scene.add(m); effects.push({ kind: 'smoke', mesh: m, t: 0, dur, R: R*.6, rise });
}
function spawnDebris(pos, R, color = 0x4a3a28, up = 8) {
  const dm = new THREE.MeshStandardMaterial({ color, roughness: 1 });
  for (let k = 0; k < Math.round((10 + R) * debrisAmount()); k++) { const b = new THREE.Mesh(debrisGeo, dm); b.position.copy(pos); b.scale.setScalar(.5 + Math.random()*R*.12);
    const a = Math.random()*Math.PI*2, s = 4 + Math.random()*R*1.4; scene.add(b);
    effects.push({ kind: 'debris', mesh: b, t: 0, dur: 2.5, vel: new THREE.Vector3(Math.cos(a)*s, up + Math.random()*R*1.6, Math.sin(a)*s), spin: Math.random()*8 }); }
}
function spawnExplosion(pos, R, hot = 0xffc04a) {
  const m = new THREE.Mesh(fireGeo, new THREE.MeshBasicMaterial({ color: hot, transparent: true, opacity: 1, depthWrite: false }));
  m.position.copy(pos); scene.add(m); effects.push({ kind: 'fire', mesh: m, t: 0, dur: .55 + R*.03, R });
  addFlash(pos, 1 + R/8); spawnSmoke(pos, R*.8, 2.4);
  spawnDebris(pos, R, MAP === 'arctic' ? 0xdfe8ee : MAP === 'lunar' ? 0x8a8a8e : 0x4a3a28);
}
function spawnSplash(pos, R) {
  const m = new THREE.Mesh(colGeo, new THREE.MeshStandardMaterial({ color: 0xeaf6fb, transparent: true, opacity: .85, depthWrite: false, roughness: .3, side: THREE.DoubleSide }));
  m.position.copy(pos); scene.add(m); effects.push({ kind: 'splash', mesh: m, t: 0, dur: 1.3, R });
  const ring = new THREE.Mesh(new THREE.RingGeometry(.8, 1.2, 28), new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: .8, depthWrite: false }));
  ring.rotation.x = -Math.PI/2; ring.position.set(pos.x, WATER + .05, pos.z); scene.add(ring); effects.push({ kind: 'ring', mesh: ring, t: 0, dur: 1.6, R });
  spawnDebris(pos, R*.8, 0xd8eef6, 12);
}
function spawnSnow() {
  const n = DEV.phone ? 500 : 1400, p = new Float32Array(n*3);
  for (let i = 0; i < n; i++) { p[i*3] = (Math.random()*2 - 1)*HALF; p[i*3+1] = 10 + Math.random()*60; p[i*3+2] = (Math.random()*2 - 1)*HALF; }
  const g = new THREE.BufferGeometry(); g.setAttribute('position', new THREE.BufferAttribute(p, 3));
  const pts = new THREE.Points(g, new THREE.PointsMaterial({ color: 0xffffff, size: .5, transparent: true, opacity: .9, depthWrite: false }));
  scene.add(pts); effects.push({ kind: 'snow', mesh: pts, t: 0, dur: 2.6 });
}
function updateEffects(dt) {
  flash.intensity *= Math.exp(-dt*8);
  for (const e of effects) {
    e.t += dt; const u = e.t / e.dur;
    if (e.kind === 'fire') { e.mesh.scale.setScalar(e.R*(.35 + .9*(1 - Math.pow(1 - Math.min(u, 1), 3)))); e.mesh.material.opacity = 1 - u; e.mesh.material.color.setHSL(.11 - .08*u, 1, .6 - .3*u); }
    else if (e.kind === 'smoke') { e.mesh.scale.setScalar(e.R*(.6 + u*.9)); e.mesh.position.y += dt*e.rise; e.mesh.material.opacity = .35*(1-u)*(1-u); }
    else if (e.kind === 'splash') { const k = Math.sin(Math.min(u, 1)*Math.PI); e.mesh.scale.set(e.R*.35*(1 + u), e.R*2.2*k + .1, e.R*.35*(1 + u)); e.mesh.position.y = WATER + e.mesh.scale.y/2; e.mesh.material.opacity = .85*(1 - u); }
    else if (e.kind === 'ring') { e.mesh.scale.setScalar(1 + u*e.R*1.6); e.mesh.material.opacity = .8*(1 - u); }
    else if (e.kind === 'snow') { const a = e.mesh.geometry.attributes.position; for (let i = 1; i < a.array.length; i += 3) a.array[i] -= dt*9; a.needsUpdate = true; e.mesh.material.opacity = .9*(1 - u); }
    else { e.vel.y -= G*dt; e.mesh.position.addScaledVector(e.vel, dt); e.mesh.rotation.x += e.spin*dt; e.mesh.rotation.z += e.spin*dt;
      const gh = heightAt(e.mesh.position.x, e.mesh.position.z); if (e.mesh.position.y < gh) { e.mesh.position.y = gh; e.vel.multiplyScalar(.3); e.vel.y = Math.abs(e.vel.y)*.3; } }
    if (u >= 1) { scene.remove(e.mesh); if (e.kind !== 'debris') e.mesh.material.dispose(); if (e.kind === 'snow' || e.kind === 'ring') e.mesh.geometry.dispose(); e.done = true; }
  }
  for (let i = effects.length - 1; i >= 0; i--) if (effects[i].done) effects.splice(i, 1);
}
function popup(text, pos, color) { const el = document.createElement('div'); el.className = 'pop'; el.textContent = text; el.style.color = color; $('#labels').appendChild(el); pops.push({ el, pos, t: 0 }); }

/* ---------- camera ---------- */
const cam = { yaw: .6, pitch: .9, dist: 220, tDist: 220, tPitch: null, tYaw: null, target: new THREE.Vector3(), tTarget: new THREE.Vector3() };
const maxDist = () => 260 * MS * (DEV.portrait && DEV.phone ? 1.35 : 1);
function camBehind(p) { if (!p) return; cam.tYaw = -p.yaw*D2R; cam.tDist = DEV.phone && DEV.portrait ? 46 : 34; cam.tPitch = .36; cam.tTarget.set(p.x, p.y + 3, p.z); }
function camOverview() { cam.tTarget.set(0, 0, 0); cam.tDist = 230 * MS * (DEV.phone && DEV.portrait ? 1.3 : 1); cam.tPitch = 1.0; cam.tYaw = null; }
function camAt(x, y, z, dist) { cam.tTarget.set(x, y, z); cam.tDist = dist; cam.tPitch = .6; }
function angDiff(a, b) { let d = (b - a) % (Math.PI*2); if (d > Math.PI) d -= Math.PI*2; if (d < -Math.PI) d += Math.PI*2; return d; }
function updateCamera(dt) {
  const k = 1 - Math.exp(-dt*4);
  if (cam.tYaw != null) { cam.yaw += angDiff(cam.yaw, cam.tYaw)*k; if (Math.abs(angDiff(cam.yaw, cam.tYaw)) < .002) cam.tYaw = null; }
  if (cam.tPitch != null) { cam.pitch += (cam.tPitch - cam.pitch)*k; if (Math.abs(cam.tPitch - cam.pitch) < .002) cam.tPitch = null; }
  cam.dist += (cam.tDist - cam.dist)*k; cam.target.lerp(cam.tTarget, k);
  const cp = Math.cos(cam.pitch);
  camera.position.set(cam.target.x + Math.sin(cam.yaw)*cp*cam.dist, cam.target.y + Math.sin(cam.pitch)*cam.dist, cam.target.z + Math.cos(cam.yaw)*cp*cam.dist);
  const gh = heightAt(camera.position.x, camera.position.z); if (camera.position.y < gh + 2) camera.position.y = gh + 2;
  camera.lookAt(cam.target);
}
const ptrs = new Map(); let pinch0 = 0;
canvas.addEventListener('pointerdown', e => { canvas.setPointerCapture(e.pointerId); ptrs.set(e.pointerId, { x: e.clientX, y: e.clientY }); if (ptrs.size === 2) { const [a, b] = [...ptrs.values()]; pinch0 = Math.hypot(a.x-b.x, a.y-b.y); } });
canvas.addEventListener('pointermove', e => { const p = ptrs.get(e.pointerId); if (!p) return;
  if (ptrs.size === 1) { cam.yaw -= (e.clientX - p.x)*.006; cam.pitch = clamp(cam.pitch + (e.clientY - p.y)*.005, .06, 1.45); cam.tYaw = null; cam.tPitch = null; }
  p.x = e.clientX; p.y = e.clientY;
  if (ptrs.size === 2) { const [a, b] = [...ptrs.values()]; const d = Math.hypot(a.x-b.x, a.y-b.y); if (pinch0) cam.tDist = clamp(cam.tDist*pinch0/d, 10, maxDist()); pinch0 = d; } });
const ptrUp = e => { ptrs.delete(e.pointerId); pinch0 = 0; };
canvas.addEventListener('pointerup', ptrUp); canvas.addEventListener('pointercancel', ptrUp);
canvas.addEventListener('wheel', e => { e.preventDefault(); cam.tDist = clamp(cam.tDist*Math.exp(e.deltaY*.0012), 10, maxDist()); }, { passive: false });

/* ---------- state ---------- */
const S = {
  loaded: false, me: -1, P: [], match: null, map: null, since: 0, queue: [], playing: null, snap: null,
  aim: { yaw: 0, elev: 45, power: 55, weapon: 'shell' }, sent: false, deadline: 0, wind: { ang: 0, spd: 0 },
  endShown: false, stayed: false, lastTurnSeen: -1,
};
const myTurn = () => S.match && S.match.status === 'active' && S.match.seat === S.me && !S.playing && !S.queue.length && !S.sent;
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function decodeTerrain(b64) {
  const bin = atob(b64), bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new Float32Array(bytes.buffer);
}
function init(d) {
  RULES = d.map.rules; MAP = d.map.id; S.map = d.map;
  N = RULES.n; SIZE = N * AT.CELL; HALF = SIZE / 2; MS = HALF / 120; G = RULES.gravity; WATER = RULES.water;
  applyTheme();
  initTerrain(decodeTerrain(d.terrain));
  buildObjects(d.objects || []);
  buildVent();
  S.me = d.me; S.since = d.match.last_event_id;
  S.P = d.players.map(p => {
    const c = COLORS[p.seat], label = document.createElement('div');
    label.className = 'tl'; label.innerHTML = `<div style="color:${c.css}"></div><div class="bar"><i style="background:${c.css}"></i></div>`;
    $('#labels').appendChild(label);
    const P = { ...p, color: c, tank: makeTank(c.hex), label, trails: [], yaw: ((Math.atan2(-p.x, p.z) / D2R) + 360) % 360, elev: 45, dead: false };
    P.tank.hull.rotation.y = -P.yaw*D2R;
    return P;
  });
  props.build(TH.props, [...S.P.map(p => [p.x, p.z, 9]), ...OBJ.map(o => [o.x, o.z, Math.max(o.hw, o.hd) + 3]), ...(RULES.vent ? [[RULES.vent[0], RULES.vent[1], 26*MS]] : [])]);
  const me = S.P[S.me];
  S.aim.yaw = me.yaw;
  applySnap(d);
  camBehind(me); cam.yaw = cam.tYaw; cam.target.copy(cam.tTarget); cam.dist = 120 * MS;
  S.loaded = true; $('#ovLoad').classList.remove('show');
  buildWeaponButtons(); syncUI();
  showMapIntro();
  portraitHint();
}
function showMapIntro() {
  const m = S.map; if (!m) return;
  $('#mapLine').textContent = m.blurb;
  if (S.match.turn > 1) return;
  const el = $('#mapIntro'); el.innerHTML = `<div class="mi-name">${esc(m.name)}</div><div class="mi-blurb">${esc(m.blurb)}</div>`;
  el.hidden = false; el.classList.add('show'); setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.hidden = true, 500); }, 3600);
}
function setDead(P, boom) {
  if (P.dead) return; P.dead = true; P.alive = false;
  P.tank.mat.color.setHex(0x2e2c29); P.tank.pivot.rotation.x = -.25;
  if (boom) { const pos = new THREE.Vector3(P.x, P.y + 1.5, P.z); spawnExplosion(pos, 7); spawnSmoke(pos, 5, 4); sndBoom(.9); }
}
/** Bring local state in line with the server (only between replays). */
function applySnap(d) {
  S.match = d.match;
  S.deadline = performance.now()/1000 + d.match.time_left;
  S.wind = { ang: d.match.wind_ang, spd: d.match.wind_spd };
  for (const p of d.players) {
    const P = S.P[p.seat]; if (!P) continue;
    Object.assign(P, { hp: p.hp, ammo: p.ammo, online: p.online, strikes: p.strikes, placement: p.placement, damage: p.damage,
                       kills: p.kills, rating: p.rating, rating_after: p.rating_after, rank: p.rank, y: p.y });
    if (!p.alive && !P.dead) setDead(P, false);
    P.alive = p.alive;
  }
  const me = S.P[S.me];
  if (me && me.ammo && me.ammo[S.aim.weapon] === 0) S.aim.weapon = 'shell';
  if (S.match.status === 'active' && S.lastTurnSeen !== S.match.turn) {
    S.lastTurnSeen = S.match.turn;
    const cur = S.P[S.match.seat];
    if (S.match.seat === S.me) { banner('Your turn!'); camBehind({ ...me, yaw: S.aim.yaw }); setDrawer(true); }
    else if (cur) { banner(`${cur.name}'s turn`); camBehind(cur); }
  }
  syncUI();
}

/* ---------- networking ---------- */
async function poll() {
  try {
    const r = await fetch(`api/match.php?id=${MID}&since=${S.since}${S.loaded ? '' : '&load=1'}`, { credentials: 'same-origin', cache: 'no-store' });
    if (r.status === 401) { location.href = 'login.php'; return; }
    if (r.status === 404) { location.href = 'lobby.php'; return; }
    const d = await r.json();
    if (!S.loaded) init(d);
    else {
      for (const e of d.events) if (e.id > S.since) { S.queue.push(e); S.since = e.id; if (e.seat === S.me && e.type === 'shot') S.sent = false; }
      S.snap = d;
      if (!S.playing && !S.queue.length) applySnap(d);
    }
  } catch (e) { console.error(e); $('#loadMsg') && ($('#loadMsg').textContent = 'Connection problem. Retrying…'); }
  finally {
    const done = S.match && S.match.status === 'finished' && !S.queue.length && !S.playing;
    if (!done) setTimeout(poll, S.sent || (S.match && S.match.seat !== S.me) ? 700 : 1000);
  }
}
async function post(url, body) {
  const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) });
  return r.json();
}
const ERR = { not_your_turn: "It isn't your turn.", stale_turn: 'That turn already ended.', too_late: 'Out of time: your turn was skipped.',
              no_ammo: 'No ammo left for that weapon.', not_active: 'This battle is over.', bad_csrf: 'Session expired. Reload the page.' };
async function fire() {
  if (!myTurn()) return;
  ac();
  S.sent = true; syncUI();
  try {
    const res = await post('api/shot.php', { match_id: MID, turn_no: S.match.turn, yaw: S.aim.yaw, elev: S.aim.elev, power: S.aim.power, weapon: S.aim.weapon });
    if (res.error) { S.sent = false; banner(ERR[res.error] || 'Shot rejected', 2200); syncUI(); }
  } catch (e) { S.sent = false; banner('Network error. Try again.', 2000); syncUI(); }
}

/* ---------- event replay ---------- */
const prepProjs = list => (list || []).map(pr => {
  const L = pr.path.length, ts = pr.path.map((_, k) => k === L - 1 ? pr.dur : Math.min(k*PATH_EVERY*STEP, pr.dur));
  return { ...pr, ts, seg: 0, pushed: 0, started: false, done: false, mesh: null, trail: null };
});
function startEvent(e) {
  const P = S.P[e.seat];
  if (e.type === 'skip') { banner(`${P.name} ran out of time (${e.data.strikes} strike${e.data.strikes > 1 ? 's' : ''})`, 1800); S.playing = { kind: 'msg', t: 0, dur: 1.6 }; return; }
  if (e.type === 'forfeit') {
    banner(e.data.reason === 'quit' ? `${P.name} left the battle` : `${P.name} was removed for inactivity`, 2000);
    P.hp = 0; setDead(P, true); syncUI(); S.playing = { kind: 'msg', t: 0, dur: 1.8 }; return;
  }
  const d = e.data;
  AT.applyAll(AUTH, d.ops);                         // the exact terrain, in server order
  if (d.wind) S.wind = { ang: Math.atan2(d.wind[0], -d.wind[1]), spd: Math.hypot(d.wind[0], d.wind[1]) };
  if (e.type === 'turn') { startTurnEvent(e); return; }
  P.trails.forEach(t => t.dispose()); P.trails = [];
  for (const Q of S.P) Q.trails.forEach(t => t.line.visible = Q === P);
  S.playing = { kind: 'shot', e, P, owner: P, aim: d.aim, phase: 'turn', t: 0, T: 0, from: { yaw: P.yaw, elev: P.elev },
                projs: prepProjs(d.projectiles), falls: d.falls, deaths: d.deaths, ops: d.ops.length };
  if (e.seat !== S.me) camBehind(P);
  syncUI();
}
/** Start-of-turn effects: eruption, snowfall, sudden death. */
function startTurnEvent(e) {
  const d = e.data, msgs = [];
  envTrails.forEach(t => t.dispose()); envTrails = [];
  if (d.snow) {
    for (const op of d.ops) if (op[0] === 'f') AT.apply(VT, op);
    for (let k = 0; k < scorch.length; k++) scorch[k] *= .82;
    updateTerrainMesh(); spawnSnow();
  }
  if (d.erupt) { msgs.push('Eruption!'); camAt(RULES.vent[0], heightAt(RULES.vent[0], RULES.vent[1]), RULES.vent[1], 120*MS); noise(1.6, 180, .9, 1.4);
    addFlash(new THREE.Vector3(RULES.vent[0], heightAt(RULES.vent[0], RULES.vent[1]) + 4, RULES.vent[1]), 3); }
  if (d.sudden) {
    if (d.sudden.first) msgs.push('Sudden death!');
    for (const h of d.sudden.drain) { const Q = S.P[h.seat]; Q.hp = Math.max(0, Q.hp - h.dmg); popup(`-${h.dmg}`, new THREE.Vector3(Q.x, Q.y + 5, Q.z), '#ff8a8a'); }
  }
  if (msgs.length) banner(msgs.join(' '), 2200);
  const projs = prepProjs(d.bombs);
  S.playing = { kind: 'shot', e, P: null, owner: null, aim: null, phase: projs.length ? 'fly' : 'pause', t: 0, T: 0,
                projs, falls: d.falls, deaths: d.deaths, ops: d.ops.length, wait: d.sudden && d.sudden.first ? 1.6 : d.snow && !projs.length ? .5 : .9 };
  syncUI();
}
function lerpAngle(a, b, k) { const d = ((b - a + 540) % 360) - 180; return (a + d*k + 360) % 360; }
function updateEvent(dt) {
  const pl = S.playing;
  if (pl.kind === 'msg') { pl.t += dt; if (pl.t > pl.dur) endEvent(); return; }
  const P = pl.P;
  if (pl.phase === 'pause') { pl.t += dt; if (pl.t > pl.wait) { pl.phase = 'fly'; } return; }
  if (pl.phase === 'turn') {
    pl.t += dt; const k = Math.min(1, pl.t / (P.seat === S.me ? .15 : .7)), s = k*k*(3 - 2*k);
    P.yaw = lerpAngle(pl.from.yaw, pl.aim.yaw, s); P.elev = pl.from.elev + (pl.aim.elev - pl.from.elev)*s;
    if (k >= 1) {
      pl.phase = 'fly'; pl.T = 0; sndFire();
      const o = pl.projs[0].path[0], v = new THREE.Vector3(o[0], o[1], o[2]); addFlash(v, .6); spawnSmoke(v, 1.2, .6);
    }
    return;
  }
  if (pl.phase === 'fly') {
    pl.T += dt*TIME_SCALE;
    let lead = null;
    const col = P ? P.color : LAVA;
    for (const pr of pl.projs) {
      if (pr.done) continue;
      if (!pr.started) {
        if (pl.T < pr.t0) continue;
        pr.started = true;
        const sz = pr.bomblet ? .3 : P ? WEAPONS[pl.aim.weapon].size : .75;
        pr.mesh = new THREE.Mesh(new THREE.SphereGeometry(sz, 12, 8), new THREE.MeshStandardMaterial({ color: P ? 0x1d1d1d : 0x5a1a00, emissive: col.hex, emissiveIntensity: P ? .5 : 1.4 }));
        pr.mesh.castShadow = true; scene.add(pr.mesh);
        pr.trail = new Trail(col.hex, .8); (P ? P.trails : envTrails).push(pr.trail);
        if (!P) { const o = pr.path[0]; spawnSmoke(new THREE.Vector3(o[0], o[1], o[2]), 4*MS, 2.5, 0x4a3a34, 5); }
      }
      const local = pl.T - pr.t0, path = pr.path, ts = pr.ts, L = path.length;
      while (pr.seg < L - 1 && ts[pr.seg + 1] <= local) pr.seg++;
      while (pr.pushed <= pr.seg) { const q = path[pr.pushed]; pr.trail.push(q[0], q[1], q[2]); pr.pushed++; }
      if (local >= pr.dur || pr.seg >= L - 1) {
        const q = path[L - 1]; pr.mesh.position.set(q[0], q[1], q[2]);
        pr.done = true; scene.remove(pr.mesh); pr.mesh.geometry.dispose();
        if (pr.end === 'split') { addFlash(pr.mesh.position, .4); noise(.2, 2000, .3); }
        else if (pr.end === 'oob' && !pr.bomblet && P) banner('Out of range!', 1200);
        if (pr.boom) boom(pr.boom, !P);
        continue;
      }
      const a = path[pr.seg], b = path[pr.seg + 1], span = ts[pr.seg + 1] - ts[pr.seg], u = span > 0 ? (local - ts[pr.seg]) / span : 1;
      pr.mesh.position.set(a[0] + (b[0]-a[0])*u, a[1] + (b[1]-a[1])*u, a[2] + (b[2]-a[2])*u);
      if (!lead) lead = pr.mesh.position;
    }
    if (lead && P) { cam.tTarget.copy(lead); cam.tDist = Math.max(cam.tDist, 48); }
    if (pl.projs.every(pr => pr.done) && !effects.some(e => e.kind === 'fire')) { pl.phase = 'settle'; pl.t = 0; for (const f of pl.falls) S.P[f.seat].fallV = 0; }
    return;
  }
  if (pl.phase === 'settle') {
    let moving = false;
    for (const f of pl.falls) {
      const Q = S.P[f.seat];
      if (f.y > Q.y) { Q.y = f.y; continue; }        // lifted (rubble, snow, the growing cone)
      if (Q.y > f.y) { Q.fallV += G*dt; Q.y = Math.max(f.y, Q.y - Q.fallV*dt); moving = true;
        if (Q.y <= f.y && f.dmg > 0) { Q.hp = Math.max(0, Q.hp - f.dmg); popup(`-${f.dmg}`, new THREE.Vector3(Q.x, Q.y + 5, Q.z), Q.color.css); syncUI(); } }
    }
    if (moving) return;
    for (const s of pl.deaths) { const Q = S.P[s]; banner(`${Q.name} destroyed!`, 1500); setDead(Q, true); }
    pl.phase = 'end'; pl.t = 0; pl.wait = pl.deaths.length ? 1.4 : .8; syncUI();
    return;
  }
  if (pl.phase === 'end') { pl.t += dt; if (pl.t > pl.wait) endEvent(); }
}
function boom(b, lava) {
  const pos = new THREE.Vector3(b.x, b.y, b.z);
  if (b.splash) { spawnSplash(pos, b.vr); sndSplash(); }
  else { crater(b.x, b.z, b.r); spawnExplosion(pos, b.vr, lava ? 0xff7a2a : 0xffc04a); sndBoom(b.vr/11); props.blast(b.x, b.z, b.vr * 1.1); }
  for (const h of b.hits) { const Q = S.P[h.seat]; Q.hp = Math.max(0, Q.hp - h.dmg); popup(`-${h.dmg}`, new THREE.Vector3(Q.x, Q.y + 5, Q.z), Q.color.css); }
  for (const r of b.obj || []) damageObject(r);
  syncUI();
}
function endEvent() {
  const pl = S.playing;
  S.playing = null;
  if (pl && pl.ops) { syncTerrain(); placeVent(); }
  if (!S.queue.length && S.snap) applySnap(S.snap);
}

/* ---------- UI ---------- */
let bannerT = null;
function banner(t, ms = 1500) { const b = $('#banner'); b.textContent = t; b.classList.add('show'); clearTimeout(bannerT); bannerT = setTimeout(() => b.classList.remove('show'), ms); }
function buildWeaponButtons() {
  const w = $('#weps'); w.innerHTML = '';
  WKEYS.forEach(k => { const b = document.createElement('button'); b.className = 'wep'; b.dataset.k = k; b.onclick = () => selectWeapon(k); w.appendChild(b); });
}
function selectWeapon(k) { const me = S.P[S.me]; if (!me || !myTurn() || me.ammo[k] === 0) return; S.aim.weapon = k; syncUI(); }
function boardStatus() {
  const m = S.match, r = RULES; if (!m || !r) return '';
  if (m.status === 'finished') return '';
  const out = [];
  if (m.turn >= S.map.sudden_turn) out.push('<b class="sd">SUDDEN DEATH</b> −5 hp a turn');
  else if (m.turn >= S.map.sudden_turn - 5) out.push(`Sudden death in ${S.map.sudden_turn - m.turn} turn${S.map.sudden_turn - m.turn > 1 ? 's' : ''}`);
  if (r.erupt_every) { const k = r.erupt_every - (m.turn % r.erupt_every); out.push(`Eruption in ${k} turn${k > 1 ? 's' : ''}`); }
  return out.join(' · ');
}
function syncUI() {
  if (!S.loaded && !S.match) return;
  const m = S.match, me = S.P[S.me];
  $('#roundLbl').textContent = m && m.status === 'finished' ? 'BATTLE OVER' : `RANKED · ${S.map ? S.map.name.toUpperCase() : ''} · TURN ${m ? m.turn : ''}`;
  $('#boardStatus').innerHTML = boardStatus();
  $('#rows').innerHTML = S.P.map(q => `<div class="prow${m && q.seat === m.seat && m.status === 'active' ? ' cur' : ''}${q.dead ? ' dead' : ''}">
    <div class="sw" style="background:${q.color.css}"></div>
    <div class="nm" style="color:${q.color.css}">${esc(q.name)}${q.seat === S.me ? '<small>YOU</small>' : ''}<i class="on${q.online ? '' : ' off'}" title="${q.online ? 'online' : 'offline'}"></i></div>
    <div class="wins"><span class="rk" style="color:${q.rank ? q.rank.color : ''}">${q.rank ? q.rank.name : ''}</span> ${q.rating ?? ''}</div>
    <div class="hp"><i style="width:${q.hp}%;background:${q.hp > 50 ? '#7bd389' : q.hp > 25 ? '#f0b429' : '#e5484d'}"></i></div></div>`).join('');
  if (!me) return;
  const cur = m ? S.P[m.seat] : null, mine = myTurn();
  const name = S.playing && S.playing.P ? S.playing.P : cur;
  $('#whoName').textContent = mine ? 'Your shot' : name ? name.name : '';
  $('#whoName').style.color = mine ? me.color.css : name ? name.color.css : '';
  $('#whoTag').textContent = !m ? '' : m.status === 'finished' ? 'Battle over' : me.dead ? 'You were destroyed. Watching the battle.'
    : S.sent ? 'Firing…' : S.playing ? (S.playing.P ? 'Shot away…' : '') : mine ? '' : 'is aiming…';
  $('#panel').classList.toggle('off', !mine);
  $('#fire').disabled = !mine;
  document.querySelectorAll('.wep').forEach(b => { const k = b.dataset.k, a = me.ammo ? me.ammo[k] : null;
    b.classList.toggle('on', S.aim.weapon === k); b.disabled = a === 0;
    b.innerHTML = `<span>${WEAPONS[k].name}</span><kbd>${a == null ? '∞' : '×' + a}${DEV.touch ? '' : ' · ' + WEAPONS[k].key}</kbd>`; });
  syncSliders();
  const bonus = m && RULES ? Math.max(0, m.turn - S.map.sudden_turn + 1) * 2 * (RULES.wind_max > 0 ? 1 : 0) : 0;
  $('#windSpd').innerHTML = RULES && RULES.wind_max <= 0 ? 'None' : S.wind.spd < .5 ? 'Calm' : `${S.wind.spd.toFixed(1)} m/s${bonus ? '<small class="sd"> +storm</small>' : ''}`;
  if (m && m.status === 'finished' && !S.playing && !S.queue.length && !S.endShown) showEnd();
}
function syncSliders() {
  const a = S.aim;
  $('#sYaw').value = Math.round(a.yaw) % 360; $('#oYaw').textContent = `${Math.round(a.yaw) % 360}°`;
  $('#sElev').value = a.elev; $('#oElev').textContent = `${a.elev.toFixed(1)}°`;
  $('#sPow').value = a.power; $('#oPow').textContent = a.power.toFixed(1);
}
function showEnd() {
  S.endShown = true;
  const rows = [...S.P].sort((a, b) => (a.placement || 9) - (b.placement || 9));
  const me = S.P[S.me], w = rows.filter(r => r.placement === 1);
  $('#eTitle').innerHTML = me.placement === 1 && w.length === 1 ? 'Victory!' : w.length === 1 ? `<span style="color:${w[0].color.css}">${esc(w[0].name)}</span> wins` : 'Draw';
  const ord = n => ['', '1st', '2nd', '3rd', '4th'][n] || n;
  $('#eTable').innerHTML = '<tr><th>PLACE</th><th>COMMANDER</th><th>KILLS</th><th>DAMAGE</th><th>RATING</th></tr>' + rows.map(p => {
    const d = (p.rating_after ?? p.rating) - p.rating;
    return `<tr><td class="place">${ord(p.placement)}</td><td style="color:${p.color.css};font-weight:700">${esc(p.name)}${p.seat === S.me ? ' <small style="color:var(--dim)">YOU</small>' : ''}</td>
      <td>${p.kills}</td><td>${p.damage}</td><td>${p.rating_after ?? p.rating} <span class="${d >= 0 ? 'up' : 'down'}">(${d >= 0 ? '+' : ''}${d})</span></td></tr>`; }).join('');
  $('#ovEnd').classList.add('show');
  marker.visible = false;
}
$('#bStay').onclick = () => { $('#ovEnd').classList.remove('show'); camOverview(); };
const setAim = (k, v) => {
  const a = S.aim;
  if (k === 'yaw') { a.yaw = ((v % 360) + 360) % 360; cam.tYaw = -a.yaw*D2R; }
  else if (k === 'elev') a.elev = clamp(v, 5, 85);
  else a.power = clamp(v, 10, 100);
  syncSliders();
};
$('#sYaw').addEventListener('input', e => { if (!myTurn()) return syncSliders(); setAim('yaw', +e.target.value); });
$('#sElev').addEventListener('input', e => { if (!myTurn()) return syncSliders(); setAim('elev', +e.target.value); });
$('#sPow').addEventListener('input', e => { if (!myTurn()) return syncSliders(); setAim('power', +e.target.value); });
$('#fire').onclick = fire;
$('#bView').onclick = camOverview;
$('#bCam').onclick = () => { const p = S.P[S.me]; if (p) camBehind({ ...p, yaw: S.aim.yaw }); };
$('#bMute').onclick = () => { muted = !muted; $('#bMute').innerHTML = muted ? '🔇<span> Muted</span>' : '🔊<span> Sound</span>'; };
$('#bLeave').onclick = async () => {
  if (S.match && S.match.status === 'finished') { location.href = 'lobby.php'; return; }
  const me = S.P[S.me];
  if (me && me.dead) { location.href = 'lobby.php'; return; }
  if (!confirm('Leave this battle? Your tank is destroyed and you place last among the players still fighting.')) return;
  try { await post('api/forfeit.php', { match_id: MID }); } catch (e) {}
  location.href = 'lobby.php';
};

/* ---------- touch controls: hold-to-repeat −/+ steppers, the aim pad, the drawer ---------- */
const STEPS = { yaw: 1, elev: .5, power: .5 };
document.querySelectorAll('.step').forEach(b => {
  let timer = null, n = 0;
  const tick = () => { if (!myTurn()) return stop(); const k = b.dataset.k, d = +b.dataset.d * STEPS[k] * (n > 8 ? 4 : n > 3 ? 2 : 1); n++;
    setAim(k, S.aim[k] + d); timer = setTimeout(tick, n === 1 ? 380 : 70); };
  const stop = () => { clearTimeout(timer); timer = null; n = 0; };
  b.addEventListener('pointerdown', e => { e.preventDefault(); stop(); tick(); });
  ['pointerup', 'pointerleave', 'pointercancel'].forEach(t => b.addEventListener(t, stop));
  b.addEventListener('contextmenu', e => e.preventDefault());
});
(() => {
  const pad = $('#aimPad'); let last = null;
  pad.addEventListener('pointerdown', e => { if (!myTurn()) return; e.preventDefault(); pad.setPointerCapture(e.pointerId); last = { x: e.clientX, y: e.clientY }; pad.classList.add('on'); });
  pad.addEventListener('pointermove', e => { if (!last) return;
    const dx = e.clientX - last.x, dy = e.clientY - last.y; last = { x: e.clientX, y: e.clientY };
    if (!myTurn()) return;
    setAim('yaw', S.aim.yaw + dx*.25); setAim('elev', S.aim.elev - dy*.15); });
  const up = () => { last = null; pad.classList.remove('on'); };
  pad.addEventListener('pointerup', up); pad.addEventListener('pointercancel', up);
})();
function setDrawer(open) { $('#panel').classList.toggle('collapsed', !open); $('#drawerBtn').setAttribute('aria-expanded', String(open)); }
$('#drawerBtn').onclick = () => setDrawer($('#panel').classList.contains('collapsed'));
function portraitHint() {
  let seen = false; try { seen = localStorage.getItem('art3d_landscape_hint') === '1'; } catch (e) {}
  if (seen || !DEV.phone || !DEV.portrait) return;
  const h = $('#hint'); h.hidden = false;
  const close = () => { h.hidden = true; try { localStorage.setItem('art3d_landscape_hint', '1'); } catch (e) {} };
  $('#hintOk').onclick = close; setTimeout(close, 9000);
}

const keys = new Set();
addEventListener('keydown', e => {
  const k = e.key.toLowerCase();
  if (['arrowleft', 'arrowright', 'arrowup', 'arrowdown', ' '].includes(k)) e.preventDefault();
  keys.add(k);
  if (k === ' ' || k === 'enter') fire();
  else if (k === '1' || k === '2' || k === '3') selectWeapon(WKEYS[+k - 1]);
  else if (k === 'v') camOverview(); else if (k === 'c') $('#bCam').click(); else if (k === 'm') $('#bMute').click();
});
addEventListener('keyup', e => keys.delete(e.key.toLowerCase()));
addEventListener('blur', () => keys.clear());
function handleKeys(dt) {
  if (!myTurn()) return; const a = S.aim, fine = keys.has('shift') ? .2 : 1; let ch = false;
  const has = (...x) => x.some(y => keys.has(y));
  if (has('arrowleft', 'a')) { a.yaw = (a.yaw - 60*fine*dt + 360) % 360; ch = true; cam.tYaw = -a.yaw*D2R; }
  if (has('arrowright', 'd')) { a.yaw = (a.yaw + 60*fine*dt) % 360; ch = true; cam.tYaw = -a.yaw*D2R; }
  if (has('arrowup', 'w')) { a.elev = clamp(a.elev + 25*fine*dt, 5, 85); ch = true; }
  if (has('arrowdown', 's')) { a.elev = clamp(a.elev - 25*fine*dt, 5, 85); ch = true; }
  if (has('e', '=', '+')) { a.power = clamp(a.power + 25*fine*dt, 10, 100); ch = true; }
  if (has('q', '-', '_')) { a.power = clamp(a.power - 25*fine*dt, 10, 100); ch = true; }
  if (ch) syncSliders();
}

/* ---------- per-frame ---------- */
const v3 = new THREE.Vector3();
function project(pos) { v3.copy(pos).project(camera); return { x: (v3.x*.5 + .5)*innerWidth, y: (-v3.y*.5 + .5)*innerHeight, vis: v3.z < 1 && v3.z > -1 }; }
function launch(P, yaw, elev, power) {
  const y = yaw*D2R, e = elev*D2R, d = new THREE.Vector3(Math.cos(e)*Math.sin(y), Math.sin(e), -Math.cos(e)*Math.cos(y));
  return { o: new THREE.Vector3(P.x, P.y + TURRET_H, P.z).addScaledVector(d, BARREL), v: d.multiplyScalar(power/100*VMAX) };
}
let lastTimerText = '';
function updateVisuals(dt, time) {
  const me = S.P[S.me], mine = myTurn();
  if (mine && me) { me.yaw = S.aim.yaw; me.elev = S.aim.elev; }
  for (const P of S.P) {
    const t = P.tank; t.group.position.set(P.x, P.y, P.z);
    t.turret.rotation.y = -P.yaw*D2R; if (!P.dead) t.pivot.rotation.x = P.elev*D2R;
    const s = project(v3.set(P.x, P.y + 4.6, P.z));
    if (!s.vis) P.label.style.display = 'none';
    else { P.label.style.display = ''; P.label.style.left = s.x + 'px'; P.label.style.top = s.y + 'px';
      P.label.firstChild.textContent = P.dead ? `${P.name} ✕` : P.name; P.label.querySelector('i').style.width = P.hp + '%'; }
  }
  const m = S.match, cur = m && m.status === 'active' && !S.playing && !S.queue.length ? S.P[m.seat] : null;
  if (cur) { marker.visible = true; marker.material.color.setHex(cur.color.hex); marker.position.set(cur.x, cur.y + 7 + Math.sin(time*4)*.4, cur.z); marker.rotation.y += dt*2; }
  else marker.visible = false;
  if (mine) {
    const { o, v } = launch(me, S.aim.yaw, S.aim.elev, S.aim.power); guide.n = 0; const pos = o.clone(), vel = v.clone();
    for (let i = 0; i < 48; i++) { guide.push(pos.x, pos.y, pos.z); for (let k = 0; k < 4; k++) { vel.y -= G*STEP; pos.addScaledVector(vel, STEP); } }
    guide.line.visible = true;
  } else guide.line.visible = false;
  for (const q of pops) { q.t += dt; q.pos.y += dt*3; const s = project(q.pos); q.el.style.left = s.x + 'px'; q.el.style.top = s.y + 'px'; q.el.style.opacity = String(1 - q.t/1.4); if (q.t > 1.4) { q.el.remove(); q.done = true; } }
  for (let i = pops.length - 1; i >= 0; i--) if (pops[i].done) pops.splice(i, 1);
  $('#windArrow').style.transform = `rotate(${(S.wind.ang + cam.yaw)/D2R}deg)`;
  updateVent(dt, time);
  let txt = '';
  if (cur) { const left = Math.max(0, Math.ceil(S.deadline - performance.now()/1000)); txt = `${Math.floor(left/60)}:${String(left % 60).padStart(2, '0')}`;
    $('#timer').classList.toggle('low', left <= 10); }
  if (txt !== lastTimerText) { $('#timer').textContent = txt; lastTimerText = txt; }
}

function resize() {
  const was = DEV.layout + DEV.touch + DEV.portrait;
  detectDevice();
  if (was !== DEV.layout + DEV.touch + DEV.portrait) { applyQuality(); if (S.loaded) syncUI(); }
  renderer.setSize(innerWidth, innerHeight, false); camera.aspect = innerWidth/innerHeight;
  camera.fov = DEV.phone && DEV.portrait ? 68 : 55; camera.updateProjectionMatrix();
}
addEventListener('resize', resize); addEventListener('orientationchange', () => setTimeout(resize, 120));
mqCoarse.addEventListener && mqCoarse.addEventListener('change', resize);
resize();
if (DEV.phone) setDrawer(false);
const clock = new THREE.Clock(); let time = 0;
function loop() {
  const dt = Math.min(clock.getDelta(), .05); time += dt;
  if (S.loaded) {
    if (!S.playing && S.queue.length) startEvent(S.queue.shift());
    if (S.playing) updateEvent(dt);
    handleKeys(dt);
  }
  updateEffects(dt); updateCamera(dt); if (S.loaded) updateVisuals(dt, time);
  renderer.render(scene, camera); requestAnimationFrame(loop);
}
loop(); poll();
})();
