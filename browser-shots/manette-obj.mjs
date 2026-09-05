import { chromium } from 'playwright';
const base = 'http://localhost';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const page = await ctx.newPage();
await page.goto(base + '/joueur', { waitUntil: 'networkidle' });
await page.fill('input[placeholder="ex. renegat"]', process.env.LOGIN);
await page.click(".joueur-auth-form button[type=submit]");
await page.waitForTimeout(4000);
// Rejoindre la manette du groupe.
await page.goto(`${base}/manette/${process.env.CODE}`, { waitUntil: 'networkidle' });
await page.waitForTimeout(6000);
const bilan = await page.evaluate(() => {
  const q = (s) => document.querySelector(s);
  const sc = (el) => (el ? { h: el.clientHeight, sh: el.scrollHeight, defile: el.scrollHeight > el.clientHeight + 1, top: el.scrollTop } : null);
  return {
    objectif: q('.obj-peek')?.innerText.replace(/\s+/g, ' ') ?? null,
    narrationEncorePresente: !!q('.narr-peek'),
    actions: sc(q('.act-scroll')),
    fil: sc(q('.cbt-lines')),
    body: sc(q('.body')),
  };
});
console.log(JSON.stringify(bilan, null, 2));
await page.screenshot({ path: `/work/browser-shots/${process.env.NOM}.png` });
await b.close();
