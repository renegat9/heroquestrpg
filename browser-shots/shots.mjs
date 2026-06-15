import { chromium } from 'playwright';

const base = 'http://localhost';
const shots = [
  { name: '1-accueil',      path: '/',             vw: 1280, vh: 860 },
  { name: '2-narrateur',    path: '/narrateur',    vw: 1280, vh: 860 },
  { name: '3-joueur',       path: '/joueur',       vw: 1280, vh: 860 },
  { name: '4-table-demo',   path: '/table/DEMO',   vw: 1440, vh: 900 },
  { name: '5-manette-demo', path: '/manette/DEMO', vw: 412,  vh: 915 },
];

const b = await chromium.launch();
for (const s of shots) {
  const ctx = await b.newContext({ viewport: { width: s.vw, height: s.vh } });
  const p = await ctx.newPage();
  try { await p.goto(base + s.path, { waitUntil: 'networkidle', timeout: 30000 }); } catch {}
  await p.waitForTimeout(2800);
  await p.screenshot({ path: `/work/browser-shots/${s.name}.png` });
  console.log('OK', s.name);
  await ctx.close();
}
await b.close();
console.log('DONE');
