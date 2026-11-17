// LOT K5d : l'outil piège du constructeur et ses deux drapeaux. Rien n'est enregistré.
import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 700 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
await p.click('button:has-text("Nouvelle campagne")');
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste button:has-text("Quête")');
const outils = await p.$$eval('.cst-outil', (l) => l.map((b) => b.title));
const piege = outils.find((t) => /piège/i.test(t));
await p.click(`.cst-outil[title="${piege}"]`);
await p.check('label:has-text("introuvable") input');
console.log(JSON.stringify({ piege, cases: await p.$$eval('.cst-case', (l) => l.map((x) => x.innerText.trim())) }), JSON.stringify(erreurs));
await p.screenshot({ path: '/work/browser-shots/classique/95-constructeur-pieges.png', clip: { x: 0, y: 0, width: 1440, height: 260 } });
await b.close();
