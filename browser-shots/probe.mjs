import { chromium } from 'playwright';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
const p = await ctx.newPage();
await p.goto('http://localhost/', { waitUntil: 'networkidle' });
// attendre que la police d'icônes soit chargée
await p.evaluate(() => document.fonts.ready);
await p.waitForTimeout(1500);
const info = await p.evaluate(() => {
  const seals = [...document.querySelectorAll('.acchoix-role-seal')].map(e => {
    const r = e.getBoundingClientRect(); return { cls: e.className, w: Math.round(r.width), h: Math.round(r.height) };
  });
  const bodies = [...document.querySelectorAll('.acchoix-role-body h2')].map(e => e.textContent.trim());
  return { seals, titles: bodies, fontStatus: document.fonts.check('24px "Material Symbols Outlined"') };
});
console.log(JSON.stringify(info, null, 2));
await p.screenshot({ path: '/work/browser-shots/1b-accueil.png' });
await b.close();
