// Contrôle : la charge de l'écran de table vient-elle bien des animations CSS
// infinies ? On charge deux fois la même page — une normale, une avec
// `animation: none` injecté — et on compare le CPU du thread principal.
import { chromium } from 'playwright';

const BASE = 'http://localhost';
const CODE = process.argv[2];
const b = await chromium.launch({ args: ['--no-sandbox'] });

async function mesurer(sansAnimations) {
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
  const page = await ctx.newPage();
  if (sansAnimations) {
    await page.addInitScript(() => {
      const poser = () => {
        const s = document.createElement('style');
        s.textContent = '*,*::before,*::after{animation:none !important;transition:none !important;}';
        document.head.appendChild(s);
      };
      if (document.head) poser();
      else document.addEventListener('DOMContentLoaded', poser);
    });
  }
  await page.goto(`${BASE}/narrateur`, { waitUntil: 'domcontentloaded' });
  await page.locator('input.narreur-input').fill(CODE);
  await page.getByText('Ouvrir la table').first().click();
  await page.waitForTimeout(5000);

  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Performance.enable');
  const lire = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((x) => [x.name, x.value]));
  const a = await lire();
  await new Promise((r) => setTimeout(r, 30000));
  const z = await lire();
  const d = (k) => (z[k] ?? 0) - (a[k] ?? 0);

  const t = Date.now();
  const repond = await Promise.race([
    page.evaluate(() => 1).then(() => `oui (${Date.now() - t} ms)`).catch(() => 'err'),
    new Promise((r) => setTimeout(() => r('NON (>10 s)'), 10000)),
  ]);
  console.log(`${sansAnimations ? 'SANS animations' : 'AVEC animations'} : CPU ${((d('TaskDuration') / 30) * 100).toFixed(0)} %`
    + ` | style+layout ${(d('RecalcStyleDuration') + d('LayoutDuration')).toFixed(1)} s / 30 s`
    + ` | nœuds ${z.Nodes} | thread principal : ${repond}`);
  await ctx.close();
}

await mesurer(false);
await mesurer(true);
await b.close();
