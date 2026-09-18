import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const ident = process.argv[2];

const b = await chromium.launch();
const tel = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();

await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.waitForTimeout(800);
await p.screenshot({ path: `${out}/03-joueur-connexion.png` });
console.log('OK 03-joueur-connexion');

await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2500);
await p.screenshot({ path: `${out}/10-roster.png` });
console.log('OK 10-roster');

await b.close();
console.log('DONE');
