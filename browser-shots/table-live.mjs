import { chromium } from 'playwright';
const base = 'http://localhost';
const code = 'recette-complete-lho8';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
await page.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', code);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(6000);
const bilan = await page.evaluate(() => {
    const imgs = [...document.querySelectorAll('img')];
    return {
        url: location.pathname,
        images: imgs.length,
        chargees: imgs.filter((i) => i.complete && i.naturalWidth > 0).length,
        cassees: imgs.filter((i) => i.complete && i.naturalWidth === 0).map((i) => i.currentSrc || i.src),
        srcs: imgs.map((i) => (i.currentSrc || i.src).replace(location.origin, '')).slice(0, 6),
        texte: document.body.innerText.replace(/\s+/g, ' ').slice(0, 260),
    };
});
console.log(JSON.stringify(bilan, null, 2));
await page.screenshot({ path: '/work/browser-shots/table-live.png', animations: 'disabled', timeout: 90000 });
await b.close();
