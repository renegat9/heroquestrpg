// Capture LA PAIRE « dé de mouvement barré / compté » pour le livret
// (2026-09-24, contrat « L'Armure de plates FAIT PERDRE LE DÉ ») :
// `DeplacementSheet.vue` affiche le dé réellement tombé, rayé d'un ✕ quand
// une Armure de plates l'annule, et normal sinon (Chevalier, ou pièce forgée
// Allégée). On ouvre la feuille de déplacement au tout DÉBUT du tour du héros
// — avant tout tap de case — parce que c'est là, dans l'en-tête, que le dé
// s'affiche ; il ne bouge plus pour le reste du tour (persisté en base, pas
// recalculé).
//
//   node browser-shots/livret-armure.mjs <code> <ident> <fichier>
//
// ⚠ Suppose que c'est déjà le tour de <ident> (vérifié par appel à
// browser-shots/campagne/hq.sh <slot> menu avant d'invoquer ce script) : la
// feuille de déplacement n'existe que pendant le tour actif du héros.
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, ident, fichier] = process.argv.slice(2);
if (!code || !ident || !fichier) {
    console.error('usage : livret-armure.mjs <code> <ident> <fichier>');
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
try { await p.click('button:has-text("Action")', { timeout: 2000 }); await p.waitForTimeout(600); } catch {}

// ⚠ Pendant que « Le MJ réfléchit… » (narration en cours), un événement
// Reverb peut re-rendre ActionTab et refermer une feuille tout juste ouverte
// — la fenêtre utile est courte. On réessaie donc SANS délai après l'ouverture
// détectée : chaque clic est suivi d'un contrôle immédiat, et la capture part
// à l'instant où la feuille existe, pas après une pause qui la laisserait se
// refermer sous nos pieds.
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
if (!ouvert) console.log('  ⚠ ÉCHEC : la feuille de déplacement ne s\'est jamais ouverte');
await p.screenshot({ path: `${out}/${fichier}.png` });
console.log('OK', fichier);
await b.close();
