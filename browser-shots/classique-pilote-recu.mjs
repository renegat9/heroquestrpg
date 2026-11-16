// MODE CLASSIQUE : la manette du joueur à qui un héros a été CONFIÉ — son bandeau porte deux héros.
//   node browser-shots/classique-pilote-recu.mjs <code> <ident du pilote> <capture>
import { chromium } from 'playwright';
const [code, ident, capture] = process.argv.slice(2);
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 })).newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));
await p.goto('http://localhost/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.goto(`http://localhost/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForTimeout(3500);
console.log('texte :', JSON.stringify(await p.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').slice(0, 400))));
await p.screenshot({ path: `/work/browser-shots/classique/${capture}.png` });
console.log('erreurs :', JSON.stringify(erreurs));
await b.close();
