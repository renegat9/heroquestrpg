// MODE CLASSIQUE : le MARCHÉ d'un groupe classique — bourse par héros (`App\Partie\Bourses`).
//   node browser-shots/classique-marche.mjs <code> <ident du joueur> <préfixe des captures> [nom de l'article à acheter]
//
// Le marché doit être OUVERT avant (POST /api/groupes/{code}/marche). Prend : la manette sur l'onglet Marché, la même
// après un achat mis au panier, l'onglet Sac, et la table.
import { chromium } from 'playwright';
const base = 'http://localhost';
const [code, ident, prefixe, article = 'Bouclier'] = process.argv.slice(2);
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
    entete: document.querySelector('.turn-banner')?.innerText.replace(/\s+/g, ' ') ?? null,
    pied: [...document.querySelectorAll('.basket-foot .row')].map((r) => r.innerText.replace(/\s+/g, ' ')),
    erreurs: [...document.querySelectorAll('.mk-err')].map((e) => e.innerText),
    confirmer: document.querySelector('.basket-foot button')?.disabled ?? null,
    debordement: document.documentElement.scrollWidth - document.documentElement.clientWidth,
}));
console.log('marché :', JSON.stringify(await lire()));
await p.screenshot({ path: `${out}/${prefixe}-manette-marche.png` });

// Mettre l'article au panier : le « + » de sa ligne.
const ligne = p.locator('.item', { has: p.locator('.nm', { hasText: article }) }).first();
await ligne.locator('.qty-ctl button').last().click();
await p.waitForTimeout(1500);
console.log(`après « ${article} » au panier :`, JSON.stringify(await lire()));
await p.locator('.basket-foot').scrollIntoViewIfNeeded();
await p.screenshot({ path: `${out}/${prefixe}-manette-panier.png` });

await p.click('nav >> text=Sac').catch(() => p.click('text=Sac'));
await p.waitForTimeout(800);
console.log('sac :', JSON.stringify(await p.evaluate(() => document.querySelector('.sac-bourse')?.innerText ?? null)));
await p.screenshot({ path: `${out}/${prefixe}-manette-sac.png` });

// La TABLE : ouverte par son code, comme un narrateur.
const tableCtx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const table = await tableCtx.newPage();
ecoute(table, 'table');
await table.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await table.fill('#codeTable', code);
await table.click('button:has-text("Ouvrir la table")');
await table.waitForTimeout(6000);
console.log('table :', JSON.stringify(await table.evaluate(() => ({
    bourses: [...document.querySelectorAll('.mk-bourse')].map((e) => e.innerText.replace(/\s+/g, ' ')),
    orCommun: document.body.innerText.includes('or commun'),
}))));
await table.screenshot({ path: `${out}/${prefixe}-table-marche.png` });

console.log('erreurs :', JSON.stringify(erreurs));
await b.close();
