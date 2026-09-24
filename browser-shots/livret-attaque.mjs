// Capture les feuilles d'ATTAQUE pour le livret : une arme (saut direct vers
// CibleSheet) ou deux (sous-choix « Avec quelle arme ? » d'abord).
//
//   node browser-shots/livret-attaque.mjs <code> <ident> <fichier>
//
// ⚠ Suppose que c'est déjà le tour de <ident> ET qu'une cible est légale
// (vérifié par browser-shots/campagne/hq.sh <slot> menu avant d'invoquer ce
// script) — sinon « Attaquer » n'apparaît pas au menu et ce script échoue.
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, ident, fichier] = process.argv.slice(2);
if (!code || !ident || !fichier) {
    console.error('usage : livret-attaque.mjs <code> <ident> <fichier>');
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

const el = p.locator('button.choice', { hasText: 'Attaquer' }).first();
await el.scrollIntoViewIfNeeded({ timeout: 5000 });
let ouvert = false;
for (let i = 0; i < 15 && !ouvert; i++) {
    await el.click({ timeout: 8000, force: true }).catch(() => {});
    for (let j = 0; j < 10; j++) {
        await p.waitForTimeout(150);
        if (await p.locator('.overlay .sheet').count()) { ouvert = true; break; }
    }
    if (!ouvert) console.log('  … pas encore ouvert, nouvel essai');
}
if (!ouvert) console.log('  ⚠ ÉCHEC : aucune feuille ouverte après clic sur Attaquer');
await p.waitForTimeout(400);
await p.screenshot({ path: `${out}/${fichier}.png` });
console.log('OK', fichier);
await b.close();
