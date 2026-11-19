// Lots K5l à K5o : le bloc « Règles des livrets » sur une campagne saisie. Rien n'est enregistré.
import { chromium } from 'playwright';
const [slugTexte, ...indices] = process.argv.slice(2);
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1800 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
await p.click(`.cst-liste .cst-campagne:has-text("${slugTexte}")`);
await p.waitForTimeout(800);
await p.click('.cst-onglets button:has-text("Quêtes")');
for (const i of indices) {
    await p.click(`.cst-quetes-liste .cst-campagne >> nth=${i}`);
    await p.waitForTimeout(800);
    const outils = p.locator(".cst-livrets .cst-puce.on");
    if (await outils.count()) await outils.first().click();
    await p.waitForTimeout(300);
    console.log(i, JSON.stringify({
        ouvert: await p.$eval('.cst-livrets', (d) => d.open),
        refus: await p.$$eval('.cst-refus li', (l) => l.map((x) => x.innerText)),
    }));
    await p.locator('.cst-livrets').screenshot({ path: `/work/browser-shots/classique/livrets-${i}.png` });
}
console.log('erreurs', JSON.stringify(erreurs));
await b.close();
