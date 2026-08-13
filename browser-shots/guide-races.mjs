/* La RACE s'affiche-t-elle sur chaque fiche de classe, et l'infobulle du
 * déplacement explique-t-elle son socle ? */
import { chromium } from 'playwright';
const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1280, height: 1100 } })).newPage();
await page.goto('http://localhost/guide', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);

const r = await page.evaluate(() => {
    const out = {};
    for (const a of document.querySelectorAll('article.hero-card')) {
        const nom = a.querySelector('h2')?.textContent.trim();
        const race = a.querySelector('.hero-race')?.textContent.replace(/\s+/g, ' ').trim();
        const dep = [...a.querySelectorAll('.stat')].find((s) => s.textContent.includes('dépl.'));
        out[nom] = { race, bulle: dep?.getAttribute('title') };
    }
    return out;
});
console.log(JSON.stringify(r, null, 1));
const art = page.locator('article.hero-card', { hasText: 'Explorateur' }).first();
if (await art.count()) { await art.scrollIntoViewIfNeeded(); await page.waitForTimeout(300); await art.screenshot({ path: 'browser-shots/guide-race.png' }); }
await nav.close();
