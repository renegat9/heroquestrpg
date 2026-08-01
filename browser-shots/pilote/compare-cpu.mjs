// Compare la charge CPU du thread principal : écran de TABLE vs MANETTE,
// dans le même navigateur, sur la même partie, en même temps.
import { chromium } from 'playwright';

const BASE = 'http://localhost';
const [CODE, IDENT, PERSO] = process.argv.slice(2);

const b = await chromium.launch({ args: ['--no-sandbox'] });

// --- Table ---
const ctxT = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
const table = await ctxT.newPage();
await table.goto(`${BASE}/narrateur`, { waitUntil: 'domcontentloaded' });
await table.locator('input.narreur-input').fill(CODE);
await table.getByText('Ouvrir la table').first().click();
await table.waitForTimeout(3000);

// --- Manette (même joueur qu'un héros de la partie, lecture seule) ---
const ctxM = await b.newContext({ viewport: { width: 420, height: 900 }, locale: 'fr-FR' });
const manette = await ctxM.newPage();
await manette.goto(`${BASE}/joueur`, { waitUntil: 'domcontentloaded' });
await manette.locator('input.joueur-input').first().fill(IDENT);
await manette.getByText('Entrer').first().click();
await manette.waitForTimeout(2500);
await manette.goto(`${BASE}/manette/${CODE}?perso=${PERSO}`, { waitUntil: 'domcontentloaded' });
await manette.waitForTimeout(3000);

const cdpT = await ctxT.newCDPSession(table); await cdpT.send('Performance.enable');
const cdpM = await ctxM.newCDPSession(manette); await cdpM.send('Performance.enable');
const lire = async (c) => Object.fromEntries((await c.send('Performance.getMetrics')).metrics.map((x) => [x.name, x.value]));

const t0 = { table: await lire(cdpT), manette: await lire(cdpM) };
console.log('mesure sur 60 s…');
await new Promise((r) => setTimeout(r, 60000));
const t1 = { table: await lire(cdpT), manette: await lire(cdpM) };

for (const nom of ['table', 'manette']) {
  const d = (k) => (t1[nom][k] ?? 0) - (t0[nom][k] ?? 0);
  console.log(`${nom.padEnd(8)} | CPU tâches ${d('TaskDuration').toFixed(1)} s / 60 s = ${((d('TaskDuration') / 60) * 100).toFixed(0)} %`
    + ` | script ${d('ScriptDuration').toFixed(1)} s | style+layout ${(d('RecalcStyleDuration') + d('LayoutDuration')).toFixed(1)} s`
    + ` | nœuds ${t1[nom].Nodes} | tas ${(t1[nom].JSHeapUsedSize / 1048576).toFixed(1)} Mo`);
}

for (const [nom, p] of [['table', table], ['manette', manette]]) {
  const t = Date.now();
  const ok = await Promise.race([p.evaluate(() => 1).then(() => 'oui').catch(() => 'err'),
    new Promise((r) => setTimeout(() => r('NON (>10 s)'), 10000))]);
  console.log(`${nom.padEnd(8)} | répond au thread principal : ${ok} (${Date.now() - t} ms)`);
}
await b.close();
