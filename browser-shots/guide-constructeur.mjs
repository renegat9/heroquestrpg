// Le guide du constructeur (2026-11-20) : accueil → guide, en bureau et en téléphone ; images chargées ; lien depuis le constructeur.
import { chromium } from 'playwright';
const b = await chromium.launch();
const erreurs = [];
const dir = '/work/browser-shots/constructeur-essai';
for (const [nom, vp] of [['bureau', { width: 1440, height: 1000 }], ['tel', { width: 390, height: 844 }]]) {
  const p = await (await b.newContext({ viewport: vp })).newPage();
  p.on('pageerror', (e) => erreurs.push(e.message));
  await p.goto('http://localhost/', { waitUntil: 'networkidle' });
  await p.click('a:has-text("Guide du constructeur")');
  await p.waitForURL('**/constructeur/guide');
  for (const img of await p.locator('.gc-fig img').all()) await img.scrollIntoViewIfNeeded();
  await p.waitForLoadState('networkidle');
  const images = await p.$$eval('.gc-fig img', (l) => l.map((i) => [i.getAttribute('src').split('/').pop(), i.naturalWidth]));
  console.log(nom, 'images :', JSON.stringify(images));
  await p.screenshot({ path: `${dir}/guide-${nom}.png`, fullPage: true });
  console.log(nom, 'défilement horizontal :', await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth));
}
console.log('erreurs', JSON.stringify(erreurs));
await b.close();
