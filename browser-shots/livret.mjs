// Captures dédiées au LIVRET DE JEU (docs/livret/). Écrans publics + guide.
// Sortie : browser-shots/livret/*.png
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const b = await chromium.launch();

async function shoot(page, name, { full = false } = {}) {
    await page.waitForTimeout(1200);
    await page.screenshot({ path: `${out}/${name}.png`, fullPage: full });
    console.log('OK', name);
}

// ---- écrans publics (sans compte) ----
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();

await p.goto(base + '/', { waitUntil: 'networkidle' });
await shoot(p, '01-accueil');

await p.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await shoot(p, '02-narrateur');

await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await shoot(p, '03-joueur-connexion');

// ---- guide intégré, onglet par onglet ----
await p.goto(base + '/guide', { waitUntil: 'networkidle' });
await shoot(p, '04-guide-heros');
for (const [lbl, name] of [
    ['Bestiaire', '05-guide-bestiaire'],
    ['Équipement', '06-guide-equipement'],
    ['Sorts', '07-guide-sorts'],
    ['Pièges', '08-guide-pieges'],
]) {
    try {
        await p.click(`.guide-tab:has-text("${lbl}")`);
        await shoot(p, name);
    } catch (e) { console.log('SKIP', name, e.message.split('\n')[0]); }
}

await ctx.close();
await b.close();
console.log('DONE');
