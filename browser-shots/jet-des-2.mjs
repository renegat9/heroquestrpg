/*
 * Étape 2 : l'attaque elle-même, et les captures.
 *   jd-02 : le menu avec « Attaquer »
 *   jd-03 : la feuille de ciblage
 *   jd-04 : l'OVERLAY du jet — deux volées nommées, succès entourés de vert
 *   jd-05 : le FIL, dés en ligne (historique)
 *   jd-06 : le fil APRÈS rechargement complet de la page (rejeu depuis la base)
 */
import { chromium } from 'playwright';

const base = 'http://localhost';
const code = process.env.CODE;
const b = await chromium.launch();
const ctx = await b.newContext({
    viewport: { width: 412, height: 915 },
    storageState: 'browser-shots/jd-state.json',
});
const page = await ctx.newPage();

// La table doit rester présente (heartbeat) sinon la manette gèle.
const tableCtx = await b.newContext({ viewport: { width: 1280, height: 800 }, storageState: 'browser-shots/jd-table-state.json' });
const tablePage = await tableCtx.newPage();
await tablePage.goto(`${base}/table/${code}`, { waitUntil: 'networkidle' }).catch(() => {});
await tablePage.waitForTimeout(2000);

await page.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await page.waitForTimeout(6000);
await page.screenshot({ path: '/work/browser-shots/jd-02-menu.png' });

const attaquer = page.locator('button:has-text("Attaquer"), *:has-text("Attaquer")').last();
await attaquer.click({ timeout: 15000 }).catch((e) => console.log('clic attaquer:', e.message));
await page.waitForTimeout(1500);
await page.screenshot({ path: '/work/browser-shots/jd-03-ciblage.png' });

// Feuille de ciblage : on prend la première cible proposée.
const cible = page.locator('.cible-sheet button, [class*="cible"] button').first();
if (await cible.count()) {
    await cible.click().catch((e) => console.log('clic cible:', e.message));
} else {
    await page.locator('button:has-text("Assassin")').first().click().catch((e) => console.log('cible nom:', e.message));
}

// L'overlay du jet dure ~4 s : on tire tout de suite.
await page.waitForTimeout(900);
await page.screenshot({ path: '/work/browser-shots/jd-04-overlay-jet.png' });

// Puis le fil, une fois l'overlay retombé.
await page.waitForTimeout(6000);
await page.screenshot({ path: '/work/browser-shots/jd-05-fil.png', fullPage: true });

// Et le fil APRÈS un rechargement complet : c'est le rejeu depuis la base.
await page.reload({ waitUntil: 'networkidle' });
await page.waitForTimeout(5000);
await page.screenshot({ path: '/work/browser-shots/jd-06-fil-apres-reload.png', fullPage: true });

console.log('DONE');
await b.close();
