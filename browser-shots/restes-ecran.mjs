// Captures du PLAN DES RESTES (2026-10-30) : miroir, grille de fer, fosse longue, terrains des tours, jetons en tuile
// (Golem, Rejeton putride), talisman maudit. Photographie une partie DÉJÀ montée par `browser-shots/campagne/preparer.sh`
// (thèmes mage_du_miroir, spirit_queen, jungles_delthrak, prophecy_telor) — ce script ne crée rien.
//
//   node browser-shots/restes-ecran.mjs <code-groupe> <identifiant-du-porteur>
//
// Sortie : browser-shots/restes/*.png
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const base = 'http://localhost';
const out = '/work/browser-shots/restes';
mkdirSync(out, { recursive: true });
const [code, ident] = process.argv.slice(2);
if (!code || !ident) {
    console.error('usage : restes-ecran.mjs <code-groupe> <identifiant>');
    process.exit(1);
}

const b = await chromium.launch();
const snap = async (page, nom, attente = 1500, opts = {}) => {
    await page.waitForTimeout(attente);
    await page.screenshot({ path: `${out}/${nom}.png`, ...opts });
    console.log('OK', nom);
};

// --- écran de table : la fenêtre (14×9 cases) suit le héros actif ; on la GLISSE vers chaque nouveauté -------------
// VUES = [nom, x, y] : la case à amener au centre de la fenêtre (coordonnées de la carte de la partie de test).
const VUES = JSON.parse(process.env.VUES ?? '[]');
const tv = await b.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 2 });
const t = await tv.newPage();
await t.goto(base + '/narrateur', { waitUntil: 'networkidle' });
try { await t.fill('#codeTable', code); await t.click('button:has-text("Ouvrir la table")'); } catch {}
await t.waitForTimeout(5000);
await snap(t, '01-table', 1000);
const fenetre = t.locator('.table-screen .map').first();
const centreActuel = JSON.parse(process.env.CENTRE ?? '[0,0]');
for (const [nom, x, y] of VUES) {
    // Taille d'une case lue au DOM, puis un glissé du centre de la fenêtre : la carte suit le pointeur.
    const cellule = await t.locator('.dg-cell').first().boundingBox();
    const boite = await fenetre.boundingBox();
    const cx = boite.x + boite.width / 2, cy = boite.y + boite.height / 2;
    const dx = (centreActuel[0] - x) * cellule.width, dy = (centreActuel[1] - y) * cellule.height;
    await t.mouse.move(cx, cy); await t.mouse.down();
    for (let k = 1; k <= 12; k++) { await t.mouse.move(cx + dx * k / 12, cy + dy * k / 12); }
    await t.mouse.up();
    centreActuel[0] = x; centreActuel[1] = y;
    await snap(t, nom, 1200);
}
try {
    await t.click('button[title="Légende de la carte"]', { timeout: 5000 });
    await snap(t, '09-table-legende', 1200);
    const legende = t.locator('.lg-panneau').first();
    if (await legende.count()) {
        await legende.screenshot({ path: `${out}/09b-legende.png` });
        console.log('OK 09b-legende');
    }
} catch (e) { console.log('SKIP legende', e.message.split('\n')[0]); }
await tv.close();

// --- manette du porteur du talisman ----------------------------------------------
const tel = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2500);
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await snap(p, '10-manette', 3000);
await snap(p, '11-manette-longue', 500, { fullPage: true });
for (const [lbl, nom] of [['Fiche', '12-manette-fiche'], ['Sac', '13-manette-sac']]) {
    try { await p.click(`button:has-text("${lbl}")`, { timeout: 4000 }); await snap(p, nom); } catch {}
}
try {
    await p.click('button:has-text("Action")', { timeout: 4000 });
    const el = p.locator('button.choice', { hasText: 'Lancer un sort de Dread' }).first();
    await el.scrollIntoViewIfNeeded({ timeout: 4000 });
    await el.click({ timeout: 6000, force: true });
    await snap(p, '14-manette-sort-dread', 1500);
} catch (e) { console.log('SKIP sort de Dread', e.message.split('\n')[0]); }
await tel.close();

await b.close();
console.log('DONE');
