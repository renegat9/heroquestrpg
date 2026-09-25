/*
 * Capture la scène de table des DÉS DE RÉSISTANCE (le complément à un seul
 * genre de livret-scenes.mjs, même patron que livret-scene-deplacement.mjs).
 * À lancer AVANT livret-scene-resistance.php.
 *
 *   node browser-shots/livret-scene-resistance.mjs <code>
 */
import { chromium } from 'playwright';

const code = process.argv[2];
if (!code) { console.error('usage : node browser-shots/livret-scene-resistance.mjs <code>'); process.exit(1); }

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 2 });
await ctx.addInitScript(() => localStorage.setItem('table:scenes',
    JSON.stringify({ actives: true, duree: 8000 })));
const p = await ctx.newPage();
await p.goto('http://localhost/narrateur', { waitUntil: 'networkidle' });
await p.fill('#codeTable', code);
await p.click('button:has-text("Ouvrir la table")');
await p.waitForTimeout(3000);
await p.locator('.narr-plier').click({ timeout: 3000 }).catch(() => {});
await p.waitForTimeout(500);
console.log('PRÊT');

let prise = false;
for (let i = 0; i < 700 && !prise; i++) {
    if (await p.locator('.scn-carte').count()) {
        const brut = await p.locator('.scn-titre').innerText({ timeout: 800 }).catch(() => '');
        const titre = brut.replace(/\n/g, ' ').replace(/^\S+\s+/, '').trim();
        if (process.env.TRACE && titre !== globalThis.dernierTitre) {
            globalThis.dernierTitre = titre;
            console.log('vu :', JSON.stringify(titre));
        }
        if (/lance Boule de Feu$/.test(titre)) {
            prise = true;
            await p.waitForTimeout(700);
            const r = await p.locator('.scn-carte').boundingBox();
            const m = 14;
            await p.screenshot({ path: '/work/browser-shots/livret/78-scene-resistance.png', clip: {
                x: Math.max(0, r.x - m), y: Math.max(0, r.y - m),
                width: r.width + 2 * m, height: r.height + 2 * m } });
            console.log('78-scene-resistance · ' + titre);
        }
    }
    await p.waitForTimeout(200);
}
console.log(prise ? 'PRISE' : 'MANQUANTE : 78-scene-resistance');
await b.close();
