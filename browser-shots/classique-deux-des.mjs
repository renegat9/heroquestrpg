// MODE CLASSIQUE, décision Q1 (2026-11-17) : deux dés de déplacement, sans base — la scène de la table au début d'un
// tour, et la feuille de déplacement de la manette.
//   node browser-shots/classique-deux-des.mjs <code> <ident joueur 2>
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
const base = 'http://localhost';
const out = '/work/browser-shots/classique';
const [code, ident] = process.argv.slice(2);
const erreurs = [];
const b = await chromium.launch();
const table = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
table.on('pageerror', (e) => erreurs.push(`table: ${e.message}`));
await table.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await table.fill('#codeTable', code);
await table.click('button:has-text("Ouvrir la table")');
await table.waitForTimeout(5000);
// Le joueur 1 finit son tour : le tour du joueur 2 s'ouvre, sa scène de déplacement part vers la table.
execSync('cd /work/browser-shots/campagne && ./hq.sh 1 menu >/dev/null && ./hq.sh 1 choix attendre >/dev/null && ./hq.sh 2 menu >/dev/null');
await table.waitForTimeout(4000);
console.log('scène :', (await table.locator('.scn').first().innerText().catch(() => '(aucune)')).replace(/\s+/g, ' '));
await table.screenshot({ path: `${out}/q1-table-scene.png` });

const tel = await (await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 })).newPage();
tel.on('pageerror', (e) => erreurs.push(`manette: ${e.message}`));
await tel.goto(base + '/joueur', { waitUntil: 'networkidle' });
await tel.fill('input[placeholder="ex. renegat"]', ident);
await tel.click('button:has-text("Entrer")');
await tel.waitForTimeout(2000);
await tel.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await tel.waitForTimeout(4000);
await tel.click('button.choice:has-text("Se déplacer")').catch(() => {});
await tel.waitForTimeout(1500);
console.log('feuille :', (await tel.locator('.dep-detail').first().innerText().catch(() => '(aucune)')).replace(/\s+/g, ' '));
await tel.screenshot({ path: `${out}/q1-manette-feuille.png` });
console.log('erreurs :', JSON.stringify(erreurs));
await b.close();
