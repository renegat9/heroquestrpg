// Pourquoi page.screenshot() expire-t-il sur l'écran de table ?
import { chromium } from 'playwright';
const code = process.argv[2];
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
await page.goto('http://localhost/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', code);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(6000);

const diag = await page.evaluate(() => {
    const anims = document.getAnimations();
    const infinies = anims.filter((a) => {
        const d = a.effect?.getTiming?.();
        return d && (d.iterations === Infinity || d.iterations > 1000);
    });
    return {
        animations: anims.length,
        infinies: infinies.length,
        exemples: infinies.slice(0, 6).map((a) => {
            const el = a.effect?.target;
            return `${el?.tagName?.toLowerCase() || '?'}.${(el?.className || '').toString().split(' ')[0]} ← ${a.animationName || a.constructor.name}`;
        }),
        hauteurDoc: document.documentElement.scrollHeight,
        elements: document.querySelectorAll('*').length,
    };
});
console.log(JSON.stringify(diag, null, 2));
const parClasse = await page.evaluate(() => { const c = {}; for (const a of document.getAnimations()) { const el = a.effect?.target; const k = (el?.className || '?').toString().split(' ')[0] + ' ← ' + (a.animationName || 'transition'); c[k] = (c[k] || 0) + 1; } return Object.entries(c).sort((x,y)=>y[1]-x[1]).slice(0,8); });
console.log('TOP CLASSES :', JSON.stringify(parClasse));

// Après avoir ANNULÉ les animations infinies, la capture passe-t-elle ?
await page.evaluate(() => document.getAnimations().forEach((a) => a.cancel()));
const t = Date.now();
try {
    await page.screenshot({ path: '/work/browser-shots/table-live.png', timeout: 25000 });
    console.log(`capture OK après annulation des animations, en ${Date.now() - t} ms`);
} catch (e) {
    console.log('capture ENCORE en échec : ' + e.message.split('\n')[0]);
}
await b.close();
