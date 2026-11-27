// 2026-11-27 : les trois dernières règles de Prophecy of Telor (lot E2) dans l'écran du constructeur — la zone hors du
// plateau, la salle en contrebas, l'invocation de Melar —, sur la campagne d'essai essai-telor-fin. Rien n'est enregistré.
import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1100 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
p.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text().slice(0, 200)); });
await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
await p.click('.cst-liste .cst-campagne:has-text("Essai Telor fin")');
await p.waitForTimeout(1000);
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste .cst-campagne >> nth=0');
await p.waitForTimeout(1200);
await p.locator('.cst-livrets').evaluate((d) => (d.open = true)).catch(() => {});
await p.waitForTimeout(300);
for (const [texte, nom] of [['Zone hors du plateau', 'reserve'], ['Salle en contrebas', 'contrebas']]) {
  const el = p.locator(`label:has-text("${texte}")`).first();
  await el.scrollIntoViewIfNeeded().catch((e) => erreurs.push(nom + ' ' + e.message.slice(0, 80)));
  await p.evaluate(() => window.scrollBy(0, -120));
  await p.screenshot({ path: `/work/browser-shots/constructeur-essai/telor-fin-${nom}.png` });
}
const notes = p.locator('.cst-notes');
await notes.scrollIntoViewIfNeeded().catch(() => {});
await notes.screenshot({ path: '/work/browser-shots/constructeur-essai/telor-fin-notes.png' }).catch((e) => erreurs.push('notes ' + e.message.slice(0, 80)));
console.log('état :', await p.$eval('.cst-etat', (e) => e.innerText).catch(() => '?'));
console.log('erreurs', JSON.stringify(erreurs));
await b.close();
