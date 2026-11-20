// Tour de l'écran du constructeur (2026-11-20) : chaque campagne, chaque onglet, quelques quêtes. Rien n'est enregistré.
import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1100 } })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
p.on('console', (m) => { if (m.type() === 'error') erreurs.push('console: ' + m.text()); });
const dir = '/work/browser-shots/constructeur-essai';
await p.goto('http://localhost/', { waitUntil: 'networkidle' });
await p.screenshot({ path: `${dir}/accueil.png`, fullPage: true });
await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
await p.screenshot({ path: `${dir}/liste.png`, fullPage: true });
const titres = await p.$$eval('.cst-liste .cst-campagne', (l) => l.map((x) => x.innerText.split('\n')[0]));
console.log('campagnes', JSON.stringify(titres));
for (const [i, t] of titres.entries()) {
  await p.click(`.cst-liste .cst-campagne >> nth=${i}`);
  await p.waitForTimeout(1500);
  const etat = await p.$eval('.cst-etat', (e) => e.innerText);
  console.log(i, t, 'état:', etat);
  for (const o of ['Campagne', 'Plateau', 'Quêtes']) {
    await p.click(`.cst-onglets button:has-text("${o}")`);
    await p.waitForTimeout(700);
    await p.screenshot({ path: `${dir}/c${i}-${o}.png`, fullPage: true });
  }
  const nq = await p.locator('.cst-quetes-liste .cst-campagne').count();
  for (const q of [0, nq - 1]) {
    await p.click(`.cst-quetes-liste .cst-campagne >> nth=${q}`);
    await p.waitForTimeout(1000);
    const refus = await p.$$eval('.cst-refus li', (l) => l.map((x) => x.innerText));
    console.log('  quête', q, 'refus', JSON.stringify(refus));
    await p.screenshot({ path: `${dir}/c${i}-q${q}.png`, fullPage: true });
  }
  await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
}
console.log('erreurs', JSON.stringify(erreurs));
await b.close();
