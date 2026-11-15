// MODE CLASSIQUE : la CARTE DE DÉPLACEMENT de la manette (`DeplacementSheet`) sur une quête construite.
//   node browser-shots/classique-deplacement.mjs <code> <ident> <nom de la capture>
import { chromium } from 'playwright';
const base = 'http://localhost';
const [code, ident, nom] = process.argv.slice(2);
const erreurs = [];
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();
p.on('pageerror', (e) => erreurs.push(`pageerror: ${e.message}`));
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
for (let i = 0; i < 25; i++) {
    const pret = await p.evaluate(() => !document.body.innerText.includes('MJ réfléchit') && document.querySelectorAll('button.choice').length > 0);
    if (pret) break;
    await p.waitForTimeout(1000);
}
await p.locator('button.choice', { hasText: 'Se déplacer' }).first().click({ timeout: 8000 });
await p.waitForTimeout(2500);
console.log('carte de déplacement :', JSON.stringify(await p.evaluate(() => ({
    murs: document.querySelectorAll('.dg-mur').length,
    portes: document.querySelectorAll('.dg-door').length,
    portesSurArete: document.querySelectorAll('.dg-door.sur-arete').length,
    acces: [...document.querySelectorAll('.dg-acces')].map((n) => n.className.replace('dg-acces ', '')),
    debordement: document.documentElement.scrollWidth - document.documentElement.clientWidth,
}))));
await p.screenshot({ path: `/work/browser-shots/classique/${nom}.png` });
console.log('erreurs :', JSON.stringify(erreurs));
await b.close();
