// La TABLE d'un groupe, ouverte par son code comme un narrateur — une capture, sans rien jouer.
//   node browser-shots/table-capture.mjs <code> <chemin de la capture sous browser-shots/> [zoom]
import { chromium } from 'playwright';
const [code, sortie] = process.argv.slice(2);
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const p = await ctx.newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
await p.goto('http://localhost/narrateur', { waitUntil: 'networkidle' });
await p.fill('#codeTable', code);
await p.click('button:has-text("Ouvrir la table")');
await p.waitForTimeout(6000);
await p.screenshot({ path: `/work/browser-shots/${sortie}` });
console.log('table :', p.url().replace('http://localhost', ''), '| erreurs :', JSON.stringify(erreurs));
await b.close();
