// Capture ad hoc pour le livret : se connecte comme <ident>, va sur la manette
// du groupe <code>, clique sur "Action" si besoin, et prend le cliché <nom>.
// Optionnel : une suite de clics avant le cliché ("Attaquer", etc.)
import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, ident, nom, ...clics] = process.argv.slice(2);

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

for (const motif of clics) {
    const el = p.locator('button.choice', { hasText: motif }).first();
    await el.scrollIntoViewIfNeeded({ timeout: 5000 });
    await el.click({ timeout: 8000, force: true });
    await p.waitForTimeout(900);
}

await p.screenshot({ path: `${out}/${nom}.png` });
console.log('OK', nom);
await b.close();
