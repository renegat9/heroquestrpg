// Vérification VISUELLE de l'aperçu de trajet et des murs de glace
// (2026-09-17). Ne crée rien : photographie une campagne montée par
// `browser-shots/campagne/preparer.sh`, dans laquelle on a posé à la main un
// mur de glace et un piège détecté.
//
//   node browser-shots/apercu-trajet.mjs <code-groupe> <identifiant> <x> <y> [largeur]
//
// `x,y` = la case à TOUCHER sur la mini-carte. Les cellules de `DungeonGrid`
// n'ont pas de coordonnées dans le DOM (une carte de 5 000 cases ne porte pas
// 5 000 attributs) : elles sont rendues en ligne par ligne, donc l'index vaut
// `y * largeur + x`.
//
// Sortie : browser-shots/apercu-*.png
import { chromium } from 'playwright';

const base = 'http://localhost';
const [code, ident, cx, cy, largeur = '84'] = process.argv.slice(2);
if (!code || !ident || cx === undefined || cy === undefined) {
    console.error('usage : apercu-trajet.mjs <code> <identifiant> <x> <y> [largeur]');
    process.exit(1);
}

const b = await chromium.launch();
const snap = async (page, nom, attente = 1200) => {
    await page.waitForTimeout(attente);
    await page.screenshot({ path: `/work/browser-shots/${nom}.png` });
    console.log('OK', nom);
};

// --- manette (téléphone) ---------------------------------------------------
const tel = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const p = await tel.newPage();
p.on('console', (m) => m.type() === 'error' && console.log('  [console]', m.text()));

await p.goto(`${base}/joueur`, { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2500);

await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForTimeout(3000);

const bouton = p.locator('button:has-text("Se déplacer")').first();
if (await bouton.count()) {
    await bouton.click();
    await snap(p, 'apercu-1-carte');

    const index = Number(cy) * Number(largeur) + Number(cx);
    const cellule = p.locator('.dep-scroll .dg-cell').nth(index);
    await cellule.scrollIntoViewIfNeeded();
    await cellule.click();
    await snap(p, 'apercu-2-trajet', 1800);
} else {
    console.log('⚠ Pas d\'option « Se déplacer » — ce n\'est pas le tour de ce héros.');
    await snap(p, 'apercu-0-manette');
}

// --- écran de table --------------------------------------------------------
const tv = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
const t = await tv.newPage();
// ⚠ La table n'a pas de compte : elle OUVRE un groupe par code depuis
// /narrateur (POST /api/table + battement). Aller droit sur /table/{code} avec
// un contexte neuf rend « Accès refusé » — il n'y a pas encore de session.
await t.goto(`${base}/narrateur`, { waitUntil: 'networkidle' });
await t.fill('#codeTable', code);
await t.click('button:has-text("Ouvrir la table")');
await t.waitForTimeout(2500);
await snap(t, 'apercu-3-table', 4000);

await b.close();
