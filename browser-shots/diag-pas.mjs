// Les figurines passent-elles par des cases INTERMÉDIAIRES, ou sautent-elles ?
import { chromium } from 'playwright';
const code = process.argv[2];
const b = await chromium.launch();
const page = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
await page.goto('http://localhost/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', code);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(5000);

await page.evaluate(() => {
    window.__pas = {};
    window.__evt = [];
    let n = 0;
    const lire = () => {
        for (const el of document.querySelectorAll('.ent-holder')) {
            // ⚠ Marquer CHAQUE élément : la classe `.fig hero` est la même pour
            // les quatre héros, les regrouper dessus entrelace leurs positions.
            if (!el.dataset.probe) el.dataset.probe = `${el.querySelector('.fig')?.classList[1] ?? '?'}#${++n}`;
            const k = el.dataset.probe;
            const p = `${parseInt(el.style.gridColumn)},${parseInt(el.style.gridRow)}`;
            const suite = (window.__pas[k] ??= []);
            if (suite[suite.length - 1] !== p) suite.push(p);
        }
    };
    // Observer AUSSI les classes de transition (apparition / disparition).
    window.__cls = {};
    new MutationObserver((muts) => {
        for (const m of muts) {
            const c = m.target.className?.toString?.() || '';
            for (const k of ['dg-fig-move', 'dg-fig-enter-active', 'dg-fig-leave-active']) {
                if (c.includes(k)) window.__cls[k] = (window.__cls[k] || 0) + 1;
            }
        }
    }).observe(document.querySelector('.dg'), { attributes: true, attributeFilter: ['class'], subtree: true });
    setInterval(lire, 40);
    lire();
});
console.log('échantillonnage armé (40 ms) — 60 s');
await page.waitForTimeout(60000);
console.log(JSON.stringify(await page.evaluate(() => {
    const out = {};
    for (const [k, v] of Object.entries(window.__pas)) if (v.length > 1) out[k] = v.slice(0, 20);
    return { deplacements: out, classes: window.__cls };
})));
await b.close();
