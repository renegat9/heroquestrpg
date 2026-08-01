// Vérifie si une page de table FRAÎCHE reçoit bien les lignes `.combat.journal`
// (le fil des événements) pendant que la partie tourne.
import { chromium } from 'playwright';

const BASE = 'http://localhost';
const CODE = process.argv[2];

const b = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
const page = await ctx.newPage();

// Trace les trames WebSocket reçues AVANT que le thread ne sature.
page.on('websocket', (ws) => {
  ws.on('framereceived', (f) => {
    const t = typeof f.payload === 'string' ? f.payload : '';
    if (t.includes('combat.journal')) console.log('TRAME combat.journal :', t.slice(0, 220));
    else if (t.includes('groupe.etat')) console.log('TRAME groupe.etat (ok)');
  });
});

await page.goto(`${BASE}/narrateur`, { waitUntil: 'domcontentloaded' });
await page.locator('input.narreur-input').fill(CODE);
await page.getByText('Ouvrir la table').first().click();
console.log('table ouverte, écoute 150 s…');
await page.waitForTimeout(150000);

const lignes = await Promise.race([
  page.evaluate(() => [...document.querySelectorAll('.evt-log li')].map((n) => n.innerText)).catch(() => null),
  new Promise((r) => setTimeout(() => r('(thread bloqué)'), 12000)),
]);
console.log('fil affiché :', JSON.stringify(lignes));
await b.close();
