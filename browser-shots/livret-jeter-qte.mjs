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
// ⚠ Le menu est GRISÉ tant que « Le MJ réfléchit… » : on attend le bandeau « C'est ton tour »
// (ActionTab.vue), qui n'apparaît que quand le menu est jouable, puis l'option. L'objet visé est
// « Fiole de soin » (la légende parle de fioles) ; `OBJET=…` le change sans toucher au script.
await p.locator('.turn-banner.mine').first().waitFor({ timeout: 20000 });
await p.locator('button.choice', { hasText: 'Jeter un objet' }).first().waitFor({ timeout: 20000 });
await p.locator('button.choice', { hasText: 'Jeter un objet' }).first().click({ force: true });
await p.waitForTimeout(700);
await p.locator('button.choice', { hasText: process.env.OBJET ?? 'Fiole de soin' }).first().click({ force: true });
await p.waitForTimeout(700);
await p.locator('.cl-qte button').nth(1).click();
await p.waitForTimeout(400);
await p.screenshot({ path: `${out}/16-manette-jeter-quantite.png` });
console.log('OK 16-manette-jeter-quantite (2)');
await b.close();
