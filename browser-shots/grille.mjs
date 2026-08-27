// Capture de l'écran de MONTÉE DE NIVEAU (grille de talents) dans le cadre
// téléphone : c'est là que se joue la question « 3 colonnes tiennent-elles
// en 360 px ? ». Jetable, hors du parcours de shots.mjs.
import { chromium } from 'playwright';

const base = 'http://localhost';
const suffix = Date.now().toString().slice(-6);
const b = await chromium.launch();

const ctx = await b.newContext({ viewport: { width: 412, height: 915 } });
const page = await ctx.newPage();

await page.goto(base + '/joueur', { waitUntil: 'networkidle' });
await page.click('button:has-text("Créer un compte")');
await page.fill('input[placeholder="votre pseudo dans le jeu"]', `Grille${suffix}`);
await page.fill('input[placeholder="login unique (ex. renegat)"]', `grille${suffix}`);
await page.click('button:has-text("Créer mon compte")');
await page.waitForTimeout(1000);
await page.click('button:has-text("Créer un personnage")');
await page.fill('input[placeholder="ex. Gorrim le Brutal"]', 'Grille');
await page.click('button:has-text("Créer le personnage")');
await page.waitForTimeout(1200);
await page.click('button:has-text("Créer un groupe")');
await page.waitForTimeout(400);
await page.click('button:has-text("Forger la campagne")');
await page.waitForTimeout(2500);

const code = page.url().match(/\/manette\/([^?]+)/)?.[1];
console.log('GROUPE', code);

await page.goto(`${base}/niveau/${code}`, { waitUntil: 'networkidle' });
await page.waitForTimeout(2000);
await page.screenshot({ path: '/work/browser-shots/6-grille-talents.png' });
console.log('OK 6-grille-talents');

// La feuille de détail : on tape la première tuile.
const tuile = page.locator('.tuile').first();
if (await tuile.count()) {
  await tuile.click();
  await page.waitForTimeout(600);
  await page.screenshot({ path: '/work/browser-shots/7-grille-detail.png' });
  console.log('OK 7-grille-detail');
}

await ctx.close();
await b.close();
console.log('DONE');
