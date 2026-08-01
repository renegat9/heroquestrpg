// Clôture de campagne via le MENU D'URGENCE du narrateur, depuis une page de
// table utilisable : les animations CSS sont neutralisées à l'injection, sinon
// l'écran de table sature son thread et ne répond plus aux clics (cf. verdict §2.1).
import { chromium } from 'playwright';

const BASE = 'http://localhost';
const CODE = process.argv[2];
const SHOTS = '/work/browser-shots';

const b = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
const page = await ctx.newPage();
await page.addInitScript(() => {
  const poser = () => {
    const s = document.createElement('style');
    s.textContent = '*,*::before,*::after{animation:none !important;transition:none !important;}';
    document.head.appendChild(s);
  };
  if (document.head) poser();
  else document.addEventListener('DOMContentLoaded', poser);
});

await page.goto(`${BASE}/narrateur`, { waitUntil: 'domcontentloaded' });
await page.locator('input.narreur-input').fill(CODE);
await page.getByText('Ouvrir la table').first().click();
await page.waitForTimeout(4000);
console.log('table ouverte :', page.url());

await page.locator('button.status-urgence').click({ timeout: 15000 });
await page.waitForTimeout(1200);
await page.screenshot({ path: `${SHOTS}/cl-01-urgence-ouvert.png` });
console.log('panneau d\'urgence :\n' + (await page.evaluate(() => document.body.innerText)).slice(-900));

// Étape 1 : demander l'arrêt de campagne (le BOUTON, pas le texte descriptif)
await page.locator('button', { hasText: 'Arrêter la campagne' }).first().click({ timeout: 15000 });
await page.waitForTimeout(1000);
await page.screenshot({ path: `${SHOTS}/cl-02-confirmation.png` });
const boutons = await page.locator('button').evaluateAll((ns) =>
  ns.map((n, i) => `${i}: ${n.innerText.replace(/\s+/g, ' ').trim().slice(0, 60)}`).filter((t) => t.length > 3));
console.log('boutons après clic :\n' + boutons.join('\n'));

// Étape 2 : la confirmation en ligne
const conf = page.locator('button', { hasText: /Confirmer — arrêter la campagne/i }).first();
await conf.click({ timeout: 15000 });
console.log('confirmation cliquée');
await page.waitForTimeout(9000);
await page.screenshot({ path: `${SHOTS}/cl-03-apres-cloture.png` });
console.log('écran après clôture :\n' + (await page.evaluate(() => document.body.innerText)).slice(0, 1400));

await b.close();
