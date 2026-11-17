// LOT K5i : l'éditeur des embranchements (campagne ramifiée). Rien n'est enregistré.
import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1600 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
await p.click('button:has-text("Nouvelle campagne")');
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste button:has-text("Quête")');
await p.click('.cst-quetes-liste button:has-text("Quête") >> nth=-1');
await p.click('.cst-quetes-liste .cst-campagne >> nth=0').catch(() => {});
await p.click('button:has-text("embranchement")');
await p.click('button:has-text("embranchement")');
await p.locator('.cst-suite input').nth(0).fill('raccourci');
await p.locator('.cst-suite input').nth(0).dispatchEvent('change');
await p.locator('.cst-suite select').nth(1).selectOption('__fin__');
await p.waitForTimeout(1200);
console.log(JSON.stringify({
    lignes: await p.$$eval('.cst-suite .cst-ligne', (l) => l.map((x) => x.innerText.replace(/\s+/g, ' ').trim())),
    refus: await p.$$eval('.cst-refus li', (l) => l.map((x) => x.innerText)),
}), JSON.stringify(erreurs));
await p.locator('.cst-suite').screenshot({ path: '/work/browser-shots/classique/96-constructeur-suite.png' });
await b.close();
