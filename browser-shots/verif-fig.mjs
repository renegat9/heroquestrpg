import { chromium } from 'playwright';
const code = process.argv[2];
const b = await chromium.launch();
const page = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
await page.goto('http://localhost/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', code);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(6000);

console.log(JSON.stringify(await page.evaluate(() => {
    const fig = document.querySelector('.fig');
    const cell = document.querySelector('.dg-cell');
    const attrs = (el) => el ? [...el.attributes].map((a) => a.name).filter((n) => n.startsWith('data-v')) : [];
    // La règle compilée par :slotted() porte le suffixe `-s`.
    let regle = null;
    for (const sh of document.styleSheets) {
        let rs; try { rs = sh.cssRules; } catch { continue; }
        for (const r of rs) if (r.selectorText?.includes('dg-fig-move')) regle = r.selectorText;
    }
    fig?.classList.add('dg-fig-move');
    cell?.classList.add('dg-fig-move');
    const t = (el) => el ? getComputedStyle(el).transitionProperty : null;
    const res = { regleCompilee: regle, scopeFigurine: attrs(fig), scopeCase: attrs(cell),
                  transitionFigurine: t(fig), transitionCase: t(cell) };
    fig?.classList.remove('dg-fig-move'); cell?.classList.remove('dg-fig-move');
    return res;
})));

const t = Date.now();
try {
    await page.screenshot({ path: '/work/browser-shots/table-live.png', timeout: 25000 });
    console.log(`CAPTURE SANS RIEN ANNULER : OK en ${Date.now() - t} ms`);
} catch (e) { console.log('CAPTURE : ÉCHEC — ' + e.message.split('\n')[0]); }
await b.close();
