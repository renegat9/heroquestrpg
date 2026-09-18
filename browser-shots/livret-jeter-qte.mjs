import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, ident] = process.argv.slice(2);

const b = await chromium.launch();
const tel = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForTimeout(2500);
try { await p.click('button:has-text("Action")', { timeout: 2000 }); await p.waitForTimeout(600); } catch {}
await p.locator('button.choice', { hasText: 'Jeter un objet' }).first().click({ force: true });
await p.waitForTimeout(700);
await p.locator('button.choice', { hasText: 'Potion de soin' }).first().click({ force: true });
await p.waitForTimeout(700);
await p.locator('.cl-qte button').nth(1).click();
await p.waitForTimeout(400);
await p.screenshot({ path: `${out}/16-manette-jeter-quantite.png` });
console.log('OK 16-manette-jeter-quantite (2)');
await b.close();
