// 2026-11-27 : les règles de Jungles of Delthrak (lot E2) dans l'écran du constructeur — le pont de corde, les relèvements,
// les étapes « en ordre » d'une note, les compagnons animaux de la campagne —, sur la campagne d'essai essai-delthrak.
// Rien n'est enregistré.
import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1100 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
p.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text().slice(0, 200)); });
await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
await p.click('.cst-liste .cst-campagne:has-text("Essai Crypte")');
await p.waitForTimeout(1000);
const compagnons = p.locator('.cst-lbl:has-text("Compagnons animaux offerts")').first();
await compagnons.scrollIntoViewIfNeeded().catch((e) => erreurs.push('compagnons ' + e.message.slice(0, 80)));
await p.screenshot({ path: '/work/browser-shots/constructeur-essai/crypte-campagne.png' });
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste .cst-campagne >> nth=0');
await p.waitForTimeout(1200);
await p.locator('.cst-livrets').evaluate((d) => (d.open = true)).catch(() => {});
await p.waitForTimeout(300);
for (const [texte, nom] of [['Glissades', 'glissades']]) {
  const el = p.locator(`label:has-text("${texte}")`).first();
  await el.scrollIntoViewIfNeeded().catch((e) => erreurs.push(nom + ' ' + e.message.slice(0, 80)));
  await p.evaluate(() => window.scrollBy(0, -120));
  await p.screenshot({ path: `/work/browser-shots/constructeur-essai/crypte-${nom}.png` });
}
const notes = p.locator('.cst-notes');
await notes.scrollIntoViewIfNeeded().catch(() => {});
await notes.screenshot({ path: '/work/browser-shots/constructeur-essai/crypte-notes.png' }).catch((e) => erreurs.push('notes ' + e.message.slice(0, 80)));
console.log('état :', await p.$eval('.cst-etat', (e) => e.innerText).catch(() => '?'));
console.log('erreurs', JSON.stringify(erreurs));
await b.close();
