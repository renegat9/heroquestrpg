// 2026-11-27 : les réglages de Prophecy of Telor (lot E2) dans l'écran du constructeur, sur la campagne d'essai essai-telor. Rien n'est enregistré.
import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1100 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
p.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text().slice(0, 200)); });
await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
await p.click('.cst-liste .cst-campagne:has-text("Essai Telor")');
await p.waitForTimeout(1000);
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste .cst-campagne >> nth=0');
await p.waitForTimeout(1200);
await p.locator('.cst-livrets').evaluate((d) => (d.open = true)).catch(() => {});
await p.waitForTimeout(300);
await p.screenshot({ path: '/work/browser-shots/constructeur-essai/telor-1.png', fullPage: true });
await p.locator('.cst-notes').screenshot({ path: '/work/browser-shots/constructeur-essai/telor-notes.png' }).catch((e) => erreurs.push('notes ' + e.message.slice(0, 80)));
await p.locator('.cst-livrets').screenshot({ path: '/work/browser-shots/constructeur-essai/telor-livrets.png' }).catch((e) => erreurs.push('livrets ' + e.message.slice(0, 80)));
for (const [texte, nom] of [['Chemin imposé au porteur', 'chemin'], ['Quête à étages', 'etages']]) {
  const el = p.locator(`label:has-text("${texte}")`).first();
  await el.scrollIntoViewIfNeeded().catch((e) => erreurs.push(nom + ' ' + e.message.slice(0, 80)));
  await p.evaluate(() => window.scrollBy(0, -80));
  await p.screenshot({ path: `/work/browser-shots/constructeur-essai/telor-${nom}.png` });
}
console.log('état :', await p.$eval('.cst-etat', (e) => e.innerText).catch(() => '?'));
console.log('erreurs', JSON.stringify(erreurs));
await b.close();
