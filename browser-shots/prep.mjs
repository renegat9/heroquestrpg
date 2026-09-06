import { chromium } from 'playwright';
const base = 'http://localhost';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
await page.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', process.env.CODE);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(2500);
// La quête démarre pendant qu'on regarde : les deux joueurs se déclarent prêts.
const releves = [];
for (let i = 0; i < 26; i++) {
  const r = await page.evaluate(() => {
    const p = document.querySelector('.prep');
    return {
      t: Date.now(),
      prep: p ? p.innerText.replace(/\s+/g, ' ') : null,
      crans: [...document.querySelectorAll('.prep-jauge i')].map((i) => i.className || 'vide'),
      voilePleinEcran: !!document.querySelector('.ouv'),
      carteVisible: !!document.querySelector('.dg, .dungeon-grid, .carte-grille'),
    };
  });
  if (r.prep || r.voilePleinEcran) releves.push(r);
  await page.waitForTimeout(4000);
}
console.log(JSON.stringify(releves.slice(0, 8), null, 2));
await page.screenshot({ path: `/work/browser-shots/${process.env.NOM}.png` });
await b.close();
