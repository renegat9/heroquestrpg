// Capture LA FORGE DU NAIN pour le livret (2026-09-24) : la feuille de détail
// d'un objet, au hub, porte désormais le catalogue de forge et l'amélioration
// déjà posée (`InfoSheet.vue`, contrat « La Forge du Nain devient
// ATTEIGNABLE »). Deux clichés, PAS le même joueur :
//
//   node browser-shots/livret-forge.mjs <code> <ident> <objet> <fichier>
//
// `<ident>` se connecte, ouvre son Sac, clique « Voir le détail » sur la
// ligne dont le nom contient `<objet>`, et photographie la feuille.
//
// ⚠ Ce script ne CRÉE rien et ne force rien côté serveur : la scène (qui
// possède l'objet, forgé ou non, avec ou sans le nœud Forge) est montée AVANT
// par de vrais appels API sur la campagne de harnais — achat, don, équipement,
// `POST /forge` — voir browser-shots/campagne/hq.sh (verbes marche/panier/
// confirmer/equiper/desequiper/donner) et le README pour la recette complète
// (Borin reçoit l'épée de Grom, la forge, la lui rend).
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, ident, objet, fichier] = process.argv.slice(2);
if (!code || !ident || !objet || !fichier) {
    console.error('usage : livret-forge.mjs <code> <ident> <objet> <fichier>');
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
await p.click('button:has-text("Sac")');
await p.waitForTimeout(800);

const ligne = p.locator('.item', { hasText: objet }).first();
await ligne.scrollIntoViewIfNeeded({ timeout: 5000 });
await ligne.locator('button[title="Voir le détail"]').click({ timeout: 5000 });
await p.waitForTimeout(900);
await p.screenshot({ path: `${out}/${fichier}.png` });
console.log('OK', fichier);
await b.close();
