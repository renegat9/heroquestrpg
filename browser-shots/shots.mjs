import { chromium } from 'playwright';

const base = 'http://localhost';
const suffix = Date.now().toString().slice(-6);

const b = await chromium.launch();

async function shoot(page, path, name, vw, vh) {
  await page.setViewportSize({ width: vw, height: vh });
  try { await page.goto(base + path, { waitUntil: 'networkidle', timeout: 30000 }); } catch {}
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `/work/browser-shots/${name}.png` });
  console.log('OK', name);
}

// ---- écrans publics (sans compte) ----
const publicCtx = await b.newContext();
const publicPage = await publicCtx.newPage();
await shoot(publicPage, '/', '1-accueil', 1280, 860);
await shoot(publicPage, '/narrateur', '2-narrateur', 1280, 860);
await shoot(publicPage, '/joueur', '3-joueur', 1280, 860);
await publicCtx.close();

// ---- écrans de jeu : état réel (compte + personnage + groupe créés via l'API) ----
const joueurCtx = await b.newContext({ viewport: { width: 412, height: 915 } });
const joueurPage = await joueurCtx.newPage();
await joueurPage.goto(base + '/joueur', { waitUntil: 'networkidle' });
await joueurPage.click('button:has-text("Créer un compte")');
await joueurPage.fill('input[placeholder="votre pseudo dans le jeu"]', `Captures${suffix}`);
await joueurPage.fill('input[placeholder="login unique (ex. renegat)"]', `captures${suffix}`);
await joueurPage.click('button:has-text("Créer mon compte")');
await joueurPage.waitForTimeout(1000);
await joueurPage.click('button:has-text("Créer un personnage")');
await joueurPage.fill('input[placeholder="ex. Gorrim le Brutal"]', 'Capture');
await joueurPage.click('button:has-text("Créer le personnage")');
await joueurPage.waitForTimeout(1000);
await joueurPage.click('button:has-text("Créer un groupe")');
await joueurPage.waitForTimeout(300);
await joueurPage.click('button:has-text("Forger la campagne")');
await joueurPage.waitForTimeout(2000);
const code = joueurPage.url().match(/\/manette\/([^?]+)/)?.[1];

await shoot(joueurPage, joueurPage.url().replace(base, ''), '5-manette', 412, 915);
await joueurCtx.close();

if (code) {
  const tableCtx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const tablePage = await tableCtx.newPage();
  await tablePage.goto(base + '/narrateur', { waitUntil: 'networkidle' });
  await tablePage.fill('#codeTable', code);
  await tablePage.click('button:has-text("Ouvrir la table")');
  await tablePage.waitForTimeout(2000);
  await shoot(tablePage, tablePage.url().replace(base, ''), '4-table', 1440, 900);
  await tableCtx.close();
} else {
  console.log('SKIP 4-table (code de groupe introuvable — la création a peut-être échoué)');
}

await b.close();
console.log('DONE');
