// Mesure la dérive de l'écran de table pendant une partie en cours :
// nœuds DOM, tas JS, écouteurs, et temps de réponse du thread principal.
import { chromium } from 'playwright';

const BASE = 'http://localhost';
const CODE = process.argv[2];
const MINUTES = Number(process.argv[3] || 12);

const b = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
const page = await ctx.newPage();
await page.goto(`${BASE}/narrateur`, { waitUntil: 'domcontentloaded' });
await page.locator('input.narreur-input').fill(CODE);
await page.getByText('Ouvrir la table').first().click();
await page.waitForTimeout(4000);

const cdp = await ctx.newCDPSession(page);
await cdp.send('Performance.enable');

console.log('t(s)\tnoeuds\tlisteners\ttasJS(Mo)\tcpu_tache(s)\treponse(ms)');
const t0 = Date.now();
for (let i = 0; i <= MINUTES * 2; i++) {
  const { metrics } = await cdp.send('Performance.getMetrics');
  const m = Object.fromEntries(metrics.map((x) => [x.name, x.value]));
  const debut = Date.now();
  const noeuds = await Promise.race([
    page.evaluate(() => document.querySelectorAll('*').length).catch(() => -1),
    new Promise((r) => setTimeout(() => r(-2), 10000)),
  ]);
  const reponse = Date.now() - debut;
  console.log([
    ((Date.now() - t0) / 1000).toFixed(0),
    noeuds,
    m.JSEventListeners ?? '?',
    ((m.JSHeapUsedSize ?? 0) / 1048576).toFixed(1),
    (m.TaskDuration ?? 0).toFixed(1),
    reponse,
  ].join('\t'));
  await new Promise((r) => setTimeout(r, 30000));
}
await b.close();
