import { chromium } from 'playwright';
const code = process.argv[2];
const b = await chromium.launch();
const page = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
await page.goto('http://localhost/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', code);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(6000);
console.log(JSON.stringify(await page.evaluate(() => {
    const cell = document.querySelector('.dg-cell');
    if (!cell) return { erreur: 'aucune .dg-cell' };
    const cs = getComputedStyle(cell);
    const regles = [];
    for (const sheet of document.styleSheets) {
        let rules; try { rules = sheet.cssRules; } catch { continue; }
        for (const r of rules) {
            if (!r.selectorText || !r.style?.transition && !r.style?.transitionProperty) continue;
            try { if (cell.matches(r.selectorText)) regles.push(r.selectorText + '  →  ' + (r.style.transition || r.style.transitionProperty)); } catch {}
        }
    }
    return {
        cellules: document.querySelectorAll('.dg-cell').length,
        transitionCalculee: cs.transition,
        proprietes: cs.transitionProperty,
        reglesQuiMatchent: regles,
    };
})));
await b.close();
