import { chromium } from 'playwright';

// Vérification VISUELLE des deux thèmes de campagne sur l'écran de table
// (2026-09-24). Même patron que shots.mjs : compte + personnage + groupe
// réels via l'API/le front, MAIS on s'arrête AVANT `/pret` — cette route
// démarre la quête (`DemarreurQuete::demarrer()` → `DeckFouille::construire()`
// → `objets.boite`), colonne pas encore migrée sur la base du conteneur.
// Rien ici ne touche donc une donnée réelle ni un chemin de code qui dépend
// de la migration en attente.

const base = 'http://localhost';
const suffix = Date.now().toString().slice(-6);

const b = await chromium.launch();

const joueurCtx = await b.newContext({ viewport: { width: 412, height: 915 } });
const joueurPage = await joueurCtx.newPage();
await joueurPage.goto(base + '/joueur', { waitUntil: 'networkidle' });
await joueurPage.click('button:has-text("Créer un compte")');
await joueurPage.fill('input[placeholder="votre pseudo dans le jeu"]', `Themes${suffix}`);
await joueurPage.fill('input[placeholder="login unique (ex. renegat)"]', `themes${suffix}`);
await joueurPage.click('button:has-text("Créer mon compte")');
await joueurPage.waitForTimeout(1000);
await joueurPage.click('button:has-text("Créer un personnage")');
await joueurPage.fill('input[placeholder="ex. Gorrim le Brutal"]', 'Testeur des Thèmes');
await joueurPage.click('button:has-text("Créer le personnage")');
await joueurPage.waitForTimeout(1000);
await joueurPage.click('button:has-text("Créer un groupe")');
await joueurPage.waitForTimeout(300);

// Thème narratif : champ visible dès qu'un groupe est saisissable, placeholder
// "Donjon classique" (JoueurView.vue).
const themeInput = joueurPage.locator('input[placeholder="Donjon classique"]').first();
if (await themeInput.count() > 0) {
  await themeInput.fill('Cryptes oubliées sous la banquise');
}

await joueurPage.click('button:has-text("Forger la campagne")');
await joueurPage.waitForTimeout(2000);
const code = joueurPage.url().match(/\/manette\/([^?]+)/)?.[1];
console.log('CODE', code);
await joueurCtx.close();

if (code) {
  const tableCtx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const tablePage = await tableCtx.newPage();
  await tablePage.goto(base + '/narrateur', { waitUntil: 'networkidle' });
  await tablePage.fill('#codeTable', code);
  await tablePage.click('button:has-text("Ouvrir la table")');
  await tablePage.waitForTimeout(2000);
  await tablePage.screenshot({ path: '/work/browser-shots/themes-01-table-hub.png' });

  const themes = await tablePage.locator('.tv-theme').allTextContents();
  console.log('THEMES AFFICHES', JSON.stringify(themes));

  await tablePage.screenshot({ path: '/work/browser-shots/themes-02-zoom.png', clip: { x: 0, y: 0, width: 520, height: 160 } });
  await tableCtx.close();
} else {
  console.log('SKIP — code de groupe introuvable');
}

await b.close();
console.log('DONE');
