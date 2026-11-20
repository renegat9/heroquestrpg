// RELECTURE DES CAMPAGNES CONSTRUITES contre leurs livrets : capture la grille de chaque quête dans le constructeur.
// Lecture seule — rien n'est enregistré.   node browser-shots/relecture-cartes.mjs "<titre de campagne>" <préfixe>
import { chromium } from 'playwright';
const [titre, prefixe] = process.argv.slice(2);
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1600, height: 1400 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
await p.click(`.cst-liste .cst-campagne:has-text("${titre}")`);
await p.waitForTimeout(800);
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.waitForTimeout(500);
const n = await p.locator('.cst-quetes-liste .cst-campagne').count();
for (let i = 0; i < n; i++) {
    await p.click(`.cst-quetes-liste .cst-campagne >> nth=${i}`);
    await p.waitForTimeout(700);
    await p.locator('svg.cst-grille').screenshot({ path: `/work/browser-shots/relecture/${prefixe}-q${String(i + 1).padStart(2, '0')}.png` });
}
console.log(n, 'quêtes', 'erreurs', JSON.stringify(erreurs));
await b.close();
