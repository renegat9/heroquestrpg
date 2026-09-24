// Capture le ROSTER d'un joueur qui a DEUX personnages : l'un engagé sur une
// campagne en cours (verrouillé, pas de suppression), l'autre libre et
// jamais entré en jeu (Supprimer proposé).
//
//   node browser-shots/livret-roster.mjs <ident> <fichier>
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [ident, fichier] = process.argv.slice(2);
if (!ident || !fichier) {
    console.error('usage : livret-roster.mjs <ident> <fichier>');
    process.exit(1);
}

const b = await chromium.launch();
const tel = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.screenshot({ path: `${out}/${fichier}.png` });
console.log('OK', fichier);
await b.close();
