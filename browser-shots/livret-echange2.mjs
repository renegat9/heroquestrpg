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
await p.locator('button.choice', { hasText: 'Échanger avec un allié' }).first().click({ force: true });
await p.waitForTimeout(700);
await p.locator('button.choice', { hasText: 'Aldric' }).first().click({ force: true });
await p.waitForTimeout(700);
const stepperInc = p.locator('.ech-stepper button').filter({ has: p.locator('svg, span') });
// deux groupes .ech-pile, chacun un bouton "+" (2e bouton du groupe .ech-stepper)
const incs = p.locator('.ech-stepper button:nth-child(3)');
await incs.nth(1).click(); // Bouclier (Borin donne)
await p.waitForTimeout(300);
await incs.nth(3).click(); // Dague (Aldric donne)
await p.waitForTimeout(500);
await p.screenshot({ path: `${out}/15-manette-echange.png` });
console.log('OK 15-manette-echange (both sides moved)');
await b.close();
