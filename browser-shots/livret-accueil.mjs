import { chromium } from 'playwright';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 980 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();
p.on('console', m => { if (m.type() === 'error') console.log('CONSOLE', m.text().slice(0, 140)); });
await p.goto('http://localhost/', { waitUntil: 'networkidle' });
await p.waitForTimeout(2000);
await p.screenshot({ path: '/work/browser-shots/livret/40-accueil-livret.png' });
const lien = await p.locator('a.is-livret').count();
console.log('carte livret présente :', lien);
if (lien) console.log('href :', await p.locator('a.is-livret').getAttribute('href'));
// mobile
const m = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const pm = await m.newPage();
await pm.goto('http://localhost/', { waitUntil: 'networkidle' });
await pm.waitForTimeout(1800);
await pm.screenshot({ path: '/work/browser-shots/livret/41-accueil-livret-mobile.png' });
console.log('OK mobile');
await b.close();
