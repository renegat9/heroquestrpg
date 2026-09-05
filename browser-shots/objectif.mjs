import { chromium } from 'playwright';
const base = 'http://localhost';
const code = process.env.CODE;
const nom = process.env.NOM || 'objectif';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
await page.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', code);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(7000);
// Le panneau d'ouverture couvre l'écran : on le referme s'il est là.
// La carte d'ouverture couvre l'écran tant que la table n'a pas signalé la
// fin de lecture (verrou B1) : on la referme comme le ferait le narrateur.
await page.evaluate(async () => {
  const jeton = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
  await fetch('/api/table/lecture-terminee', { method: 'POST', headers: { 'X-XSRF-TOKEN': jeton, 'Accept': 'application/json' } });
});
await page.waitForTimeout(2500);
const txt = await page.evaluate(() => {
  const o = document.querySelector('.quest .obj');
  return o ? { texte: o.innerText.replace(/\s+/g, ' '), classes: o.className } : null;
});
console.log(JSON.stringify(txt));
const bloc = await page.$('.top');
if (bloc) await bloc.screenshot({ path: `/work/browser-shots/${nom}.png` });
await b.close();
