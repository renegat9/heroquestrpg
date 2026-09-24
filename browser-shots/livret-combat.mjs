// Capture le FIL DU COMBAT hors tour pour le livret : hors de son tour, la
// manette n'affiche que le bandeau d'attente et le fil (App\Partie\JournalCombat),
// jamais un menu — ActionTab.vue.
//
//   node browser-shots/livret-combat.mjs <code> <ident> <fichier>
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, ident, fichier] = process.argv.slice(2);
if (!code || !ident || !fichier) {
    console.error('usage : livret-combat.mjs <code> <ident> <fichier>');
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
await p.screenshot({ path: `${out}/${fichier}.png` });
console.log('OK', fichier);
await b.close();
