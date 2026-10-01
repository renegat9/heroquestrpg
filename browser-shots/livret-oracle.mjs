// Captures du livret pour l'ORACLE (First Light, 2026-10-01) : le badge béni /
// maudit sur la fiche de la manette, et sur la carte du héros à la table.
// Usage : node browser-shots/livret-oracle.mjs <code> <ident-béni> <ident-maudit>
// Les deux héros doivent déjà porter l'état (campagne de harnais, voir
// browser-shots/campagne/preparer-livret.sh) — le badge n'existe qu'EN QUÊTE.
import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, identBeni, identMaudit] = process.argv.slice(2);

const b = await chromium.launch();

async function fiche(ident, nom) {
    const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
    const p = await ctx.newPage();
    await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
    await p.fill('input[placeholder="ex. renegat"]', ident);
    await p.click('button:has-text("Entrer")');
    await p.waitForTimeout(2000);
    await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
    await p.waitForTimeout(2500);
    await p.locator('button:has-text("Fiche"), [role="tab"]:has-text("Fiche")').first().click();
    await p.waitForTimeout(900);
    // La section Conditions porte les badges : on la centre dans le cadre.
    await p.locator('.sect-title:has-text("Conditions")').first().scrollIntoViewIfNeeded();
    await p.waitForTimeout(400);
    await p.screenshot({ path: `${out}/${nom}.png` });
    console.log('OK', nom);
    await ctx.close();
}

await fiche(identBeni, '38-manette-oracle');
await fiche(identMaudit, '38-manette-oracle-maudit');

const table = await b.newContext({ viewport: { width: 1600, height: 900 } });
await table.addInitScript(() => localStorage.setItem('table:scenes', '0'));
const t = await table.newPage();
await t.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await t.fill('#codeTable', code);
await t.click('button:has-text("Ouvrir la table")');
await t.waitForTimeout(5000);
await t.screenshot({ path: `${out}/24-table-oracle.png` });
console.log('OK 24-table-oracle');

await b.close();
