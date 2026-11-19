// Lot K5p : l'éditeur de la quête double, sur une campagne saisie. Rien n'est enregistré.
import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1600 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
await p.click('.cst-liste .cst-campagne:has-text("Kellar")');
await p.waitForTimeout(800);
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste .cst-campagne >> nth=0');
await p.waitForTimeout(500);
const choix = p.locator('label:has-text("Quête double avec") select');
await choix.selectOption({ index: 1 });
await p.waitForTimeout(1500);
console.log(JSON.stringify({
    ligne: await p.locator('label:has-text("Quête double avec")').locator('xpath=..').innerText(),
    refus: await p.$$eval('.cst-refus li', (l) => l.map((x) => x.innerText)),
}), JSON.stringify(erreurs));
await p.locator('label:has-text("Quête double avec")').locator('xpath=..').screenshot({ path: '/work/browser-shots/classique/constructeur-double.png' });
await p.click('.cst-quetes-liste .cst-campagne >> nth=1');
await p.waitForTimeout(800);
console.log(JSON.stringify({ seconde: await p.locator('label:has-text("Quête double avec")').locator('xpath=..').innerText(), fin: await p.locator('label:has-text("Fin")').count() }));
await b.close();
