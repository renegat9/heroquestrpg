// Le guide du constructeur (2026-11-20) : accueil → guide, en bureau et en téléphone ; le lien depuis le constructeur.
import { chromium } from 'playwright';
const b = await chromium.launch();
const erreurs = [];
const dir = '/work/browser-shots/constructeur-essai';
for (const [nom, vp] of [['bureau', { width: 1440, height: 1000 }], ['tel', { width: 390, height: 844 }]]) {
  const p = await (await b.newContext({ viewport: vp })).newPage();
  p.on('pageerror', (e) => erreurs.push(e.message));
  await p.goto('http://localhost/', { waitUntil: 'networkidle' });
  if (nom === 'bureau') await p.screenshot({ path: `${dir}/accueil-guide.png`, fullPage: true });
  await p.click('a:has-text("Guide du constructeur")');
  await p.waitForURL('**/constructeur/guide');
  await p.waitForTimeout(500);
  await p.screenshot({ path: `${dir}/guide-${nom}.png`, fullPage: true });
  const large = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  console.log(nom, 'défilement horizontal :', large);
  if (nom === 'bureau') {
    await p.click('a:has-text("Ouvrir le constructeur")');
    await p.waitForURL('**/constructeur');
    await p.waitForTimeout(800);
    await p.locator('.cst-tete').screenshot({ path: `${dir}/cst-tete.png` });
    console.log('lien Guide dans le constructeur :', await p.locator('.cst-tete a:has-text("Guide")').count());
  }
}
console.log('erreurs', JSON.stringify(erreurs));
await b.close();
