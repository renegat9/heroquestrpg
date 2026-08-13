/*
 * VÉRIFICATION EN PARTIE RÉELLE des classes d'extension (plan
 * misty-whistling-wolf, point 4). Rien de tout ce lot n'avait été joué par
 * l'UI : tests verts et base vérifiée, mais jamais un écran.
 *
 * Ce que ce scénario prouve, dans l'ordre :
 *  1. le sélecteur propose bien les 12 classes ;
 *  2. l'ELFE affiche ses DEUX VOIES, et le répertoire elfique se choisit ;
 *  3. un CHEVALIER créé porte ses 3 capacités de carte ;
 *  4. la quête démarre et la manette rend la main au joueur.
 */
import { chromium } from 'playwright';

const base = 'http://localhost';
const suffix = Date.now().toString().slice(-6);
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 } });
const page = await ctx.newPage();

await page.goto(base + '/joueur', { waitUntil: 'networkidle' });
await page.click('button:has-text("Créer un compte")');
await page.fill('input[placeholder="votre pseudo dans le jeu"]', `Ext${suffix}`);
await page.fill('input[placeholder="login unique (ex. renegat)"]', `ext${suffix}`);
await page.click('button:has-text("Créer mon compte")');
await page.waitForTimeout(1200);

await page.click('button:has-text("Créer un personnage")');
await page.waitForTimeout(400);

// 1. Les 12 classes sont-elles proposées ?
const classes = await page.evaluate(() => [...document.querySelectorAll('input[name="nouvelleClasse"]')]
    .map((i) => i.value));
console.log('CLASSES=' + classes.length + ' → ' + classes.join(','));

// 2. L'ELFE et ses deux voies.
await page.click('label:has-text("Elfe")');
await page.waitForTimeout(600);
const voies = await page.evaluate(() => [...document.querySelectorAll('button')]
    .map((b) => b.textContent.trim())
    .filter((t) => t.includes('École élémentaire') || t.includes('Répertoire elfique')));
console.log('VOIES=' + JSON.stringify(voies));

await page.click('button:has-text("Répertoire elfique")');
await page.waitForTimeout(900);
const sorts = await page.evaluate(() => [...document.querySelectorAll('.sef-btn .sef-nom')].map((e) => e.textContent.trim()));
console.log('REPERTOIRE=' + sorts.length + ' → ' + sorts.join(','));
await page.screenshot({ path: '/work/browser-shots/ext-01-voies-elfe.png' });

// On choisit trois sorts : le bouton de création doit se débloquer.
for (const s of sorts.slice(0, 3)) {
    await page.click(`.sef-btn:has-text("${s}")`);
    await page.waitForTimeout(150);
}
await page.screenshot({ path: '/work/browser-shots/ext-02-trois-sorts.png' });

// 3. On crée finalement un CHEVALIER (3 capacités de carte, bouclier de départ).
await page.click('label:has-text("Chevalier")');
await page.waitForTimeout(400);
await page.fill('input[placeholder="ex. Gorrim le Brutal"]', 'Roland');
await page.click('button:has-text("Créer le personnage")');
await page.waitForTimeout(1500);
await page.screenshot({ path: '/work/browser-shots/ext-03-roster.png' });

await page.click('button:has-text("Créer un groupe")');
await page.waitForTimeout(400);
await page.click('button:has-text("Forger la campagne")');
await page.waitForTimeout(2500);

const code = page.url().match(/\/manette\/([^?]+)/)?.[1];
console.log('CODE=' + code);

// Table ouverte : sans narrateur actif, aucune quête ne démarre.
const tableCtx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const tablePage = await tableCtx.newPage();
await tablePage.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await tablePage.fill('#codeTable', code);
await tablePage.click('button:has-text("Ouvrir la table")');
await tablePage.waitForTimeout(2500);

await page.bringToFront();
const pret = page.locator('button:has-text("Prêt"), button:has-text("prêt")').first();
if (await pret.count()) { await pret.click().catch(() => {}); }
await page.waitForTimeout(9000);
await page.screenshot({ path: '/work/browser-shots/ext-04-quete.png' });

// 4. La fiche du héros : ses capacités de carte doivent s'y voir.
const fiche = page.locator('button:has-text("Fiche"), [role="tab"]:has-text("Fiche")').first();
if (await fiche.count()) { await fiche.click().catch(() => {}); await page.waitForTimeout(1200); }
await page.screenshot({ path: '/work/browser-shots/ext-05-fiche.png', fullPage: true });

console.log('DONE code=' + code);
await b.close();
