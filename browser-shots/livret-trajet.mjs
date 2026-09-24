// Capture le TRAJET EXACT (aperçu orange, contrat « Aperçu du trajet »)
// pour le livret : ouvre la feuille de déplacement, tape une case accessible
// éloignée pour forcer un chemin de plusieurs pas, et photographie l'aperçu
// AVANT de confirmer.
//
//   node browser-shots/livret-trajet.mjs <code> <ident> <fichier>
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, ident, fichier] = process.argv.slice(2);
if (!code || !ident || !fichier) {
    console.error('usage : livret-trajet.mjs <code> <ident> <fichier>');
    process.exit(1);
}

const b = await chromium.launch();
const tel = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForTimeout(2500);
try { await p.click('button:has-text("Action")', { timeout: 2000 }); await p.waitForTimeout(1000); } catch {}

const el = p.locator('button.choice', { hasText: 'Se déplacer' }).first();
await el.scrollIntoViewIfNeeded({ timeout: 5000 });
let ouvert = false;
for (let i = 0; i < 15 && !ouvert; i++) {
    await el.click({ timeout: 8000, force: true }).catch(() => {});
    for (let j = 0; j < 10; j++) {
        await p.waitForTimeout(150);
        if (await p.locator('.dep-sheet').count()) { ouvert = true; break; }
    }
    if (!ouvert) console.log('  … pas encore ouvert, nouvel essai');
}
if (!ouvert) { console.log('  ⚠ ÉCHEC : la feuille de déplacement ne s\'est jamais ouverte'); }
else {
    // Vise une case accessible ÉLOIGNÉE pour forcer un trajet de plusieurs
    // pas plutôt qu'un pas unique. ⚠ Certaines cases accessibles portent une
    // PORTE en surcouche (`.dg-door`, z-index supérieur) : un clic forcé sur
    // l'une d'elles est intercepté par la porte, pas par la case — l'aperçu
    // ne se déclenche jamais et rien ne le signale. On essaie donc plusieurs
    // candidates, du plus au moins éloigné, jusqu'à ce que l'aperçu apparaisse
    // réellement (le bandeau « Touche une case éclairée » a disparu).
    const cases = p.locator('.dg-cell.accessible');
    const n = await cases.count();
    let pris = false;
    for (let i = n - 1; i >= Math.max(0, n - 8) && !pris; i--) {
        await cases.nth(i).click({ timeout: 5000, force: true }).catch(() => {});
        await p.waitForTimeout(700);
        if (await p.locator('.dep-apercu').count()) { pris = true; }
    }
    await p.waitForTimeout(600);
}
await p.screenshot({ path: `${out}/${fichier}.png` });
console.log('OK', fichier);
await b.close();
