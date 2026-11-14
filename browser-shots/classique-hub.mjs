// MODE CLASSIQUE : la manette d'un groupe classique AU HUB.
//   node browser-shots/classique-hub.mjs <code> <ident> <nom de la capture>
import { chromium } from 'playwright';
const base = 'http://localhost';
const [code, ident, nom] = process.argv.slice(2);
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForTimeout(3000);
console.log('hub :', JSON.stringify(await p.evaluate(() => ({
    recrutement: document.body.innerText.includes('Recruter un allié') || document.body.innerText.includes('RECRUTER UN ALLIÉ'),
    bourseCommune: document.body.innerText.includes('Bourse commune'),
    pret: document.body.innerText.includes('Prêt pour la quête'),
    debordement: document.documentElement.scrollWidth - document.documentElement.clientWidth,
}))));
await p.screenshot({ path: `/work/browser-shots/classique/${nom}.png` });
await b.close();
