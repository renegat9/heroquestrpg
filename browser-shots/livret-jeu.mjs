// Captures de JEU pour le livret (docs/livret/). Complète `livret.mjs`, qui ne
// prend que les écrans publics et le guide.
//
//   node browser-shots/livret-jeu.mjs <code-groupe> <identifiant> <étape>
//
// ⚠ Ce script ne CRÉE rien : il photographie une partie déjà montée par
// `browser-shots/campagne/preparer-livret.sh`, dont l'existence tient à une
// seule chose — `preparer.sh` marque tout le monde prêt, donc la quête démarre
// et le hub (marché, roster, paniers) n'est plus photographiable.
//
// Les étapes correspondent à l'état du jeu, pas au script : un menu d'action
// n'existe que pendant le tour du héros que l'on pilote.
//
//   hub    — roster, étal du marché, fiche, sac, écran de table au hub
//   quete  — écran de table en quête + manette du héros dont c'est le tour
//
// Sortie : browser-shots/livret/*.png (convertis ensuite en webp, voir README).
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, ident, etape = 'hub'] = process.argv.slice(2);
if (!code || !ident) {
    console.error('usage : livret-jeu.mjs <code-groupe> <identifiant> [hub|quete]');
    process.exit(1);
}

const b = await chromium.launch();
const snap = async (page, nom, attente = 1600) => {
    await page.waitForTimeout(attente);
    await page.screenshot({ path: `${out}/${nom}.png` });
    console.log('OK', nom);
};

// --- téléphone : 412x915, comme une manette réelle -------------------------
const tel = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');   // ⚠ « Se connecter » est l'ONGLET, pas le bouton
await p.waitForTimeout(2500);

if (etape === 'hub') {
    await snap(p, '10-roster');
    await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
    await snap(p, '11-manette-marche', 3000);
    for (const [lbl, nom] of [['Fiche', '12-manette-fiche'], ['Sac', '13-manette-sac']]) {
        await p.click(`button:has-text("${lbl}")`);
        await snap(p, nom);
    }
} else {
    await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
    await snap(p, '30-manette-action', 3500);
    try { await p.click('button:has-text("Sorts")'); await snap(p, '14-manette-sorts'); } catch {}
    try { await p.click('button:has-text("Action")'); await p.waitForTimeout(900); } catch {}
    // ⚠ scrollIntoView + force : la liste d'actions défile sous le fil de combat,
    // et un clic « propre » expire avant que Playwright ne la juge stable.
    for (const [motif, nom] of [['Se déplacer', '31-manette-deplacement'],
                                ['Attaquer', '33-manette-cible']]) {
        const el = p.locator('button.choice', { hasText: motif }).first();
        try {
            await el.scrollIntoViewIfNeeded({ timeout: 5000 });
            await el.click({ timeout: 8000, force: true });
            await snap(p, nom, 1800);
            await p.keyboard.press('Escape');
            await p.waitForTimeout(1200);
        } catch (e) { console.log('SKIP', nom, e.message.split('\n')[0]); }
    }
}
await tel.close();

// --- écran de table --------------------------------------------------------
const tv = await b.newContext({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 2 });
const t = await tv.newPage();
await t.goto(base + '/narrateur', { waitUntil: 'networkidle' });
try { await t.fill('#codeTable', code); await t.click('button:has-text("Ouvrir la table")'); } catch {}
await t.waitForTimeout(4000);
if (etape === 'hub') {
    await snap(t, '19-table-prologue', 1000);
    try { await t.click("button:has-text(\"Commencer l'aventure\")"); } catch {}
    await snap(t, '20-table-hub', 2000);
} else {
    await snap(t, '22-table-donjon', 2000);
}
await b.close();
console.log('DONE');
