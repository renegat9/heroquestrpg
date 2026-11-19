// MODE CLASSIQUE, lot K6 : la VENTE D'UN JETON DE RÉPUTATION au marché (*Rise of the Dread Moon* p. 5).
//   node browser-shots/classique-jeton.mjs <code> <ident du joueur> <préfixe> [article]
// Le marché doit être OUVERT avant, et le groupe avoir un jeton. Capture la manette avant, après « + jeton », après
// l'achat de l'article ; puis la table.
import { chromium } from 'playwright';
const base = 'http://localhost';
const [code, ident, prefixe, article = 'Épée courte'] = process.argv.slice(2);
const out = '/work/browser-shots/classique';
const b = await chromium.launch();
const erreurs = [];
const ecoute = (page, qui) => {
    page.on('console', (m) => { if (m.type() === 'error') erreurs.push(`${qui} console : ${m.text()}`); });
    page.on('pageerror', (e) => erreurs.push(`${qui} page : ${e.message}`));
};
const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();
ecoute(p, 'manette');
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForTimeout(3000);
const lire = () => p.evaluate(() => ({
    jeton: [...document.querySelectorAll('.item')].find((e) => e.innerText.includes('Vendre un jeton'))?.innerText.replace(/\s+/g, ' ') ?? null,
    vendre: document.body.innerText.includes('Vendre (50 %)'),
    pied: [...document.querySelectorAll('.basket-foot .row')].map((r) => r.innerText.replace(/\s+/g, ' ')),
    erreurs: [...document.querySelectorAll('.mk-err')].map((e) => e.innerText),
    debordement: document.documentElement.scrollWidth - document.documentElement.clientWidth,
}));
const jeton = p.locator('.item', { has: p.locator('.nm', { hasText: 'Vendre un jeton' }) }).first();
console.log('avant :', JSON.stringify(await lire()));
await jeton.scrollIntoViewIfNeeded();
await p.screenshot({ path: `${out}/${prefixe}-avant.png` });
await jeton.locator('.qty-ctl button').last().click();
await p.waitForTimeout(1500);
console.log('après + jeton :', JSON.stringify(await lire()));
const ligne = p.locator('.item', { has: p.locator('.nm', { hasText: article }) }).first();
await ligne.locator('.qty-ctl button').last().click();
await p.waitForTimeout(1500);
console.log(`après « ${article} » :`, JSON.stringify(await lire()));
await jeton.scrollIntoViewIfNeeded();
await p.screenshot({ path: `${out}/${prefixe}-jeton.png` });
await p.locator('.basket-foot').scrollIntoViewIfNeeded();
await p.screenshot({ path: `${out}/${prefixe}-panier.png` });

const tableCtx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const table = await tableCtx.newPage();
ecoute(table, 'table');
await table.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await table.fill('#codeTable', code);
await table.click('button:has-text("Ouvrir la table")');
await table.waitForTimeout(6000);
await table.screenshot({ path: `${out}/${prefixe}-table.png` });
console.log('erreurs :', JSON.stringify(erreurs));
await b.close();
