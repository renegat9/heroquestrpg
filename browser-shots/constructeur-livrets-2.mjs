// 2026-11-20 : les réglages ajoutés pour les livrets, dans l'écran du constructeur. Rien n'est enregistré.
import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1100 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
p.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') erreurs.push(m.type() + ': ' + m.text().slice(0, 200)); });
const dir = '/work/browser-shots/constructeur-essai';
async function quete(campagne, i) {
  await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
  await p.click(`.cst-liste .cst-campagne:has-text("${campagne}")`);
  await p.waitForTimeout(1000);
  await p.click('.cst-onglets button:has-text("Quêtes")');
  await p.click(`.cst-quetes-liste .cst-campagne >> nth=${i}`);
  await p.waitForTimeout(1200);
}
// Légion oubliée : note B « éveiller »
await quete('Seigneur Sorcier', 6);
await p.locator('.cst-livrets').evaluate((d) => (d.open = true));
const puce = p.locator('.cst-livrets .cst-puce').nth(1); await puce.click();
await p.waitForTimeout(300);
await p.screenshot({ path: `${dir}/sorcier-q7.png`, fullPage: true });
console.log('q7 éveil :', JSON.stringify(await p.$$eval('label.cst-case', (l) => l.filter((x) => /^\s*\d+ ·/.test(x.innerText)).map((x) => x.innerText.trim() + (x.querySelector('input').checked ? ' ✓' : '')).slice(0, 30))));
console.log('q7 état :', await p.$eval('.cst-etat', (e) => e.innerText));
// Seigneur Sorcier q10 : profil du boss, sorts
await quete('Seigneur Sorcier', 9);
await p.locator('.cst-livrets').evaluate((d) => (d.open = true));
await p.locator('.cst-livrets .cst-puce').first().click();
await p.waitForTimeout(300);
console.log('q10 vulnérable :', JSON.stringify(await p.$$eval('.pm-puce', (l) => l.map((x) => x.innerText.trim()))));
await p.locator('.cst-livrets').screenshot({ path: `${dir}/sorcier-q10-profil.png` });
// Livret de base q2 : captif
await quete('Livret de Quêtes', 1);
console.log('q2 captif :', await p.$eval('select:near(:text("Captif à escorter"))', (s) => s.options[s.selectedIndex]?.text).catch((e) => 'absent ' + e.message.slice(0, 80)));
await p.screenshot({ path: `${dir}/base-q2.png`, fullPage: true });
// Kellar q9 et q10 : verrou de sortie
for (const i of [8, 9]) {
  await quete('Kellar', i);
  await p.click('.cst-outils button[title*="Sortie"], button[title="Sortie"]').catch(() => {});
  await p.waitForTimeout(300);
  const t = await p.locator('span.cst-sous:has-text("Sortie actuelle")').innerText().catch(() => 'aucun texte');
  console.log(`kellar q${i + 1} :`, t.replace(/\s+/g, ' '), '| état', await p.$eval('.cst-etat', (e) => e.innerText));
  await p.screenshot({ path: `${dir}/kellar-q${i + 1}.png` });
}
console.log('erreurs', JSON.stringify(erreurs));
await b.close();
