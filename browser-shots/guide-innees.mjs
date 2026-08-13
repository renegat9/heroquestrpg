import { chromium } from 'playwright';
const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
await page.goto('http://localhost/guide', { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
const r = await page.evaluate(() => {
    const blocs = [...document.querySelectorAll('.hero-talents-t')]
        .filter((e) => e.textContent.includes('Capacités de carte')).length;
    // La fiche qui contient « Frénésie sanguinaire » (Berserker)
    const art = [...document.querySelectorAll('article')]
        .find((a) => a.textContent.includes('Frénésie sanguinaire'));
    return {
        blocsCapacitesDeCarte: blocs,
        berserkerInnees: art ? [...art.querySelectorAll('.tt-innee')].length : null,
        berserkerNoeuds: art ? [...art.querySelectorAll('.talent-li')].length : null,
    };
});
console.log(JSON.stringify(r));
const art = page.locator('article', { hasText: 'Frénésie sanguinaire' }).first();
await art.scrollIntoViewIfNeeded();
await page.waitForTimeout(300);
await art.screenshot({ path: 'browser-shots/guide-innees.png' });
await nav.close();
