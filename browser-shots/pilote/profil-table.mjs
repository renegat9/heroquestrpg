// Reproduit le gel de l'écran de table et profile le thread JS via CDP.
// Lancé dans un conteneur Playwright jetable (n'utilise pas le pilote de la partie).
import { chromium } from 'playwright';

const BASE = 'http://localhost';
const CODE = process.argv[2];

const b = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
const page = await ctx.newPage();

await page.goto(`${BASE}/narrateur`, { waitUntil: 'domcontentloaded' });
await page.locator('input.narreur-input').fill(CODE);
await page.getByText('Ouvrir la table').first().click();
await page.waitForTimeout(4000);
console.log('url =', page.url());

const cdp = await ctx.newCDPSession(page);
await cdp.send('Profiler.enable');
await cdp.send('Profiler.setSamplingInterval', { interval: 200 });
await cdp.send('Profiler.start');
await new Promise((r) => setTimeout(r, 6000));
const { profile } = await cdp.send('Profiler.stop');

// Agrège le temps auto (self time) par fonction.
const parId = new Map(profile.nodes.map((n) => [n.id, n]));
const self = new Map();
const total = (profile.samples || []).length;
for (const id of profile.samples || []) {
  const n = parId.get(id);
  if (!n) continue;
  const f = n.callFrame;
  const cle = `${f.functionName || '(anonyme)'}  ${(f.url || '').split('/').pop()}:${f.lineNumber + 1}`;
  self.set(cle, (self.get(cle) || 0) + 1);
}
console.log(`\n--- ${total} échantillons sur 6 s ---`);
[...self.entries()].sort((a, b2) => b2[1] - a[1]).slice(0, 18)
  .forEach(([k, v]) => console.log(`${String(((v / total) * 100).toFixed(1)).padStart(5)} %  ${k}`));

// La page répond-elle encore ?
const vivante = await Promise.race([
  page.evaluate(() => 'oui').catch(() => 'erreur'),
  new Promise((r) => setTimeout(() => r('NON — thread bloqué'), 8000)),
]);
console.log('\npage réactive :', vivante);

// Combien de nœuds DOM / quelle taille de grille ?
const info = await Promise.race([
  page.evaluate(() => ({
    noeuds: document.querySelectorAll('*').length,
    cellules: document.querySelectorAll('.cell, .tile, .case').length,
    fig: document.querySelectorAll('.fig').length,
  })).catch(() => null),
  new Promise((r) => setTimeout(() => r(null), 8000)),
]);
console.log('DOM :', JSON.stringify(info));

await b.close();
