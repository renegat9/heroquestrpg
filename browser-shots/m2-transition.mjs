// Lot M2 : la TRANSITION entre deux héros d'une même manette, échantillonnée après « Terminer le tour ».
//   node browser-shots/m2-transition.mjs <code> <ident> [capture]
import { chromium } from 'playwright';
const base = 'http://localhost';
const [code, ident, capture] = process.argv.slice(2);

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForTimeout(3000);

const etat = () => p.evaluate(() => ({
    affiche: document.querySelector('.bandeau-heros-pastille.est-courant .bandeau-heros-nom')?.textContent?.trim(),
    main: [...document.querySelectorAll('.bandeau-heros-pastille.a-la-main .bandeau-heros-nom')].map((n) => n.textContent.trim()).join(','),
    choix: document.querySelectorAll('button.choice').length,
    reflechit: document.body.innerText.includes('MJ réfléchit'),
}));
for (let i = 0; i < 25 && ((await etat()).reflechit || (await etat()).choix === 0); i++) await p.waitForTimeout(1000);
console.log('avant :', JSON.stringify(await etat()));

await p.locator('button.choice', { hasText: 'Terminer le tour' }).first().click({ timeout: 8000 });
const t0 = Date.now();
let dernier = '';
for (let i = 0; i < 40; i++) {
    const e = JSON.stringify(await etat());
    if (e !== dernier) { console.log(`+${String(Date.now() - t0).padStart(5)} ms`, e); dernier = e; }
    await p.waitForTimeout(300);
}
if (capture) await p.screenshot({ path: `/work/browser-shots/m2/${capture}.png` });
await b.close();
