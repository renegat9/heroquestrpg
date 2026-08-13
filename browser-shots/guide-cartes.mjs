/* Le guide liste-t-il TOUTES les cartes d'une classe ? Trois pièges :
 *  - les sorts de classe (Barde/Druide/Warlock) sont des cartes, pas des nœuds ;
 *  - les 8 techniques du Moine vivent DANS ses 4 cartes de style ;
 *  - les répertoires de classe étaient jetés du groupement des sorts.
 */
import { chromium } from 'playwright';
const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1280, height: 1200 } })).newPage();
await page.goto('http://localhost/guide', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);

const r = await page.evaluate(() => {
    const par = (nom) => [...document.querySelectorAll('article')].find((a) => a.querySelector('h3, h2, h4')?.textContent.toLowerCase().includes(nom));
    const compte = (a, sel) => (a ? a.querySelectorAll(sel).length : null);
    const fiche = (nom) => {
        const a = par(nom);
        return { sorts: compte(a, '.tt-sort'), innees: compte(a, '.tt-innee'), techniques: compte(a, '.tech-li') };
    };
    return { barde: fiche('barde'), moine: fiche('moine'), druide: fiche('druide'), warlock: fiche('warlock'), elfe: fiche('elfe') };
});
console.log(JSON.stringify(r));

// Onglet Sorts : les 4 répertoires doivent avoir leur groupe. L'onglet peut
// porter des libellés variés selon la version — on tente, sans bloquer.
const onglet = page.locator('button', { hasText: /^Sorts/ }).first();
if (await onglet.count()) { await onglet.click().catch(() => {}); }
await page.waitForTimeout(800);
const groupes = await page.evaluate(() => [...document.querySelectorAll('.grp-title')].map((e) => e.textContent.replace(/\s+/g, ' ').trim()));
console.log('GROUPES=' + JSON.stringify(groupes));

const b = page.locator('article', { hasText: 'Léger sur ses pieds' }).first();
if (await b.count()) { await b.scrollIntoViewIfNeeded(); await page.waitForTimeout(300); await b.screenshot({ path: 'browser-shots/guide-barde.png' }); }
await nav.close();
