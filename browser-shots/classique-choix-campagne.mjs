// MODE CLASSIQUE (fin du lot S1) : la campagne du groupe au hub et son CHOIX, grisé pour une classe absente.
//   node browser-shots/classique-choix-campagne.mjs <code> <ident> <préfixe>
import { chromium } from 'playwright';
const base = 'http://localhost';
const [code, ident, prefixe = 'choix-'] = process.argv.slice(2);
const erreurs = [];
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();
p.on('pageerror', (e) => erreurs.push(`pageerror: ${e.message}`));
p.on('console', (m) => { if (m.type() === 'error') erreurs.push(`console: ${m.text().slice(0, 200)}`); });
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForTimeout(3000);
const bloc = p.locator('.campagne-hub');
console.log('bloc :', (await bloc.innerText()).replace(/\s+/g, ' '));
await bloc.screenshot({ path: `/work/browser-shots/classique/${prefixe}resume.png` });
const btn = p.locator('.campagne-hub button:has-text("Choisir une campagne")');
if (await btn.count()) {
    await btn.click();
    await p.waitForTimeout(1500);
    console.log('liste :', (await bloc.innerText()).replace(/\s+/g, ' '));
    await bloc.screenshot({ path: `/work/browser-shots/classique/${prefixe}liste.png` });
}
console.log('debordement :', await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth));
await p.screenshot({ path: `/work/browser-shots/classique/${prefixe}page.png`, fullPage: false });
console.log('erreurs :', JSON.stringify(erreurs));
await b.close();
