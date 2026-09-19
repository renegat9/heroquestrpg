// Bouton PLEIN ÉCRAN de l'écran de table (René, 2026-09-18) — vérification.
//
// ⚠ Playwright en mode headless ne passe PAS réellement en plein écran : ce
// script ne prétend donc pas le prouver. Il vérifie ce qui EST vérifiable
// automatiquement — que le bouton existe, qu'il est au bon endroit, que son
// libellé et son icône basculent avec `document.fullscreenElement`, et que
// l'état est bien RELU du navigateur plutôt que mémorisé (le piège : sortir
// par Échap laisserait un drapeau local proposer d'entrer dans un mode où
// l'on est déjà).
//
//   node browser-shots/table-pleinecran.mjs <code-groupe>
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots';
const [code] = process.argv.slice(2);

const nav = await chromium.launch();
const p = await nav.newPage({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 1 });

await p.goto(`${base}/narrateur`, { waitUntil: 'networkidle' });
await p.fill('#codeTable', code);
await p.click('button:has-text("Ouvrir la table")');
await p.waitForSelector('.status-top', { timeout: 20000 });
await p.waitForTimeout(1500);

const bouton = p.locator('button[aria-label*="plein écran"]').first();
await bouton.waitFor({ timeout: 10000 });

console.log('titre au repos   :', await bouton.getAttribute('title'));
console.log('icône au repos   :', (await bouton.innerText()).trim());
await p.screenshot({ path: `${out}/pleinecran-1-repos.png` });

// On SIMULE l'entrée en plein écran côté navigateur, puis on vérifie que le
// bouton a suivi — c'est exactement le chemin qu'emprunte un Échap ou un F11,
// qui ne passent jamais par notre clic.
await p.evaluate(() => document.documentElement.requestFullscreen?.().catch(() => {}));
await p.waitForTimeout(600);

console.log('plein écran actif:', await p.evaluate(() => !! document.fullscreenElement));
console.log('titre en plein   :', await bouton.getAttribute('title'));
console.log('icône en plein   :', (await bouton.innerText()).trim());
await p.screenshot({ path: `${out}/pleinecran-2-actif.png` });

await nav.close();
