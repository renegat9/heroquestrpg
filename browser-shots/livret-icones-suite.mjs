// Suite de livret-icones.mjs : à lancer quand l'attaque a déjà été jouée
// (a_agi=1 côté Grom) et qu'il ne reste que « Jeter un objet » (16) et le Fil
// du combat côté Aldric (32) à capturer. Écrit séparément pour ne pas
// rejouer une seconde attaque sur un état déjà consommé.
//
//   IDENT_GROM=... IDENT_ALDRIC=... node browser-shots/livret-icones-suite.mjs <code>
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code] = process.argv.slice(2);
const identGrom = process.env.IDENT_GROM;
const identAldric = process.env.IDENT_ALDRIC;

const b = await chromium.launch();

async function connecter(page, ident) {
    await page.goto(base + '/joueur', { waitUntil: 'networkidle' });
    await page.fill('input[placeholder="ex. renegat"]', ident);
    await page.click('button:has-text("Entrer")');
    await page.waitForTimeout(2000);
    await page.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
}

const telGrom = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const grom = await telGrom.newPage();
await connecter(grom, identGrom);
await grom.waitForSelector('button.choice', { timeout: 20000 });
await grom.waitForTimeout(1000);
await grom.screenshot({ path: `${out}/_debug-menu-grom.png` });

const boutonJeter = grom.locator('button.choice', { hasText: 'Jeter un objet' }).first();
await boutonJeter.waitFor({ timeout: 15000 });
await boutonJeter.scrollIntoViewIfNeeded();
await boutonJeter.click({ timeout: 8000, force: true });
await grom.waitForSelector('text=Quel objet jeter ?', { timeout: 8000 });
await grom.locator('button.choice', { hasText: 'Fiole de soin' }).first().click({ timeout: 8000, force: true });
await grom.waitForSelector('text=Combien en jeter ?', { timeout: 8000 });
await grom.locator('.cl-qte button').nth(1).click();
await grom.waitForTimeout(400);
await grom.screenshot({ path: `${out}/16-manette-jeter-quantite.png` });
console.log('OK 16-manette-jeter-quantite');
await telGrom.close();

const telAldric = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const aldric = await telAldric.newPage();
await connecter(aldric, identAldric);
await aldric.waitForSelector('text=Fil du combat', { timeout: 15000 });
await aldric.waitForTimeout(800);
await aldric.screenshot({ path: `${out}/32-manette-combat.png` });
console.log('OK 32-manette-combat');
await telAldric.close();

await b.close();
console.log('DONE');
