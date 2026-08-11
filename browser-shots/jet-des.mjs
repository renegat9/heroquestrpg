/*
 * Partie courte, scriptée, pour PHOTOGRAPHIER le rendu des jets de dés.
 *
 * Étape 1 : compte + personnage + groupe + table + « prêt » → la quête démarre.
 * Le placement du monstre au contact se fait ensuite en base (voir la commande
 * artisan qui suit), pour ne pas rejouer une exploration entière juste pour
 * obtenir une attaque.
 */
import { chromium } from 'playwright';

const base = 'http://localhost';
const suffix = Date.now().toString().slice(-6);
const b = await chromium.launch();

const ctx = await b.newContext({ viewport: { width: 412, height: 915 } });
const page = await ctx.newPage();

await page.goto(base + '/joueur', { waitUntil: 'networkidle' });
await page.click('button:has-text("Créer un compte")');
await page.fill('input[placeholder="votre pseudo dans le jeu"]', `Des${suffix}`);
await page.fill('input[placeholder="login unique (ex. renegat)"]', `des${suffix}`);
await page.click('button:has-text("Créer mon compte")');
await page.waitForTimeout(1200);

await page.click('button:has-text("Créer un personnage")');
await page.fill('input[placeholder="ex. Gorrim le Brutal"]', 'Krogar');
// Barbare : 3 dés d'attaque — de quoi voir plusieurs dés dans la volée.
const barbare = page.locator('button:has-text("Barbare"), label:has-text("Barbare")').first();
if (await barbare.count()) { await barbare.click().catch(() => {}); }
await page.click('button:has-text("Créer le personnage")');
await page.waitForTimeout(1200);

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

// « Prêt » → démarrage de la quête.
await page.bringToFront();
const pret = page.locator('button:has-text("Prêt"), button:has-text("prêt")').first();
if (await pret.count()) { await pret.click().catch(() => {}); }
await page.waitForTimeout(9000);
await page.screenshot({ path: '/work/browser-shots/jd-01-quete-lancee.png' });

await ctx.storageState({ path: '/work/browser-shots/jd-state.json' });
await tableCtx.storageState({ path: '/work/browser-shots/jd-table-state.json' });
console.log('DONE');
await b.close();
