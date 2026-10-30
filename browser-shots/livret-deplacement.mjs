// Reprise de la seule figure 31-manette-deplacement du livret : la feuille de déplacement et le trajet visé, sur la
// manette du héros ACTIF. `livret-jeu.mjs quete` la prend aussi, mais un clic précédent (onglet Sorts) peut laisser une
// autre feuille ouverte (vu le 2026-10-30) : celle-ci part d'une manette fraîche.
//
//   node browser-shots/livret-deplacement.mjs <code-groupe> <identifiant-du-héros-actif>
import { chromium } from 'playwright';

const [code, ident] = process.argv.slice(2);
if (!code || !ident) {
    console.error('usage : livret-deplacement.mjs <code-groupe> <identifiant>');
    process.exit(1);
}
const b = await chromium.launch();
const tel = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();
await p.goto('http://localhost/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2500);
await p.goto(`http://localhost/manette/${code}`, { waitUntil: 'networkidle' });
await p.locator('.turn-banner.mine').first().waitFor({ timeout: 20000 });
const el = p.locator('button.choice', { hasText: 'Se déplacer' }).first();
await el.scrollIntoViewIfNeeded({ timeout: 5000 });
await el.click({ timeout: 8000, force: true });
await p.waitForTimeout(1500);
// Sans ce tap, la feuille ne montre que les cases accessibles : le TRAJET exact n'existe qu'après avoir visé une case.
await p.locator('.dg-cell.accessible').last().click({ timeout: 5000, force: true }).catch(() => {});
await p.waitForTimeout(1800);
await p.screenshot({ path: '/work/browser-shots/livret/31-manette-deplacement.png' });
console.log('OK 31-manette-deplacement');
await b.close();
