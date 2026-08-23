// Sur QUEL élément Vue pose-t-il `dg-fig-move`, et une transition court-elle ?
import { chromium } from 'playwright';
const code = process.argv[2];
const b = await chromium.launch();
const page = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
await page.goto('http://localhost/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', code);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(5000);

await page.evaluate(() => {
    window.__flip = { classes: [], transitions: 0, positions: [] };
    const grille = document.querySelector('.dg');
    new MutationObserver((muts) => {
        for (const m of muts) {
            const el = m.target;
            if (el.className?.toString?.().includes('dg-fig-')) {
                window.__flip.classes.push(el.className.toString().split(' ').filter((c) => c.startsWith('dg-fig-') || c === 'ent-holder').join('.') || '(sans classe)');
            }
        }
    }).observe(grille, { attributes: true, attributeFilter: ['class'], subtree: true });

    // Une transition qui DÉMARRE réellement sur un enfant de la grille.
    grille.addEventListener('transitionstart', (e) => {
        window.__flip.transitions++;
        window.__flip.positions.push(`${e.target.className?.toString?.().split(' ')[0]} ← ${e.propertyName}`);
    }, true);
});

const lire = () => page.evaluate(() => [...document.querySelectorAll('.ent-holder')]
    .map((e) => `${e.style.gridColumn}/${e.style.gridRow}`).join(' | '));
const avant = await lire();
console.log('AVANT : ' + avant);
console.log('observateur armé — 55 s d’écoute');
await page.waitForTimeout(55000);
console.log('APRÈS : ' + (await lire()));
const r = await page.evaluate(() => {
    const c = {};
    for (const k of window.__flip.classes) c[k] = (c[k] || 0) + 1;
    const t = {};
    for (const k of window.__flip.positions) t[k] = (t[k] || 0) + 1;
    return { classeMovePoseeSur: c, transitionsDemarrees: window.__flip.transitions, detail: t };
});
console.log(JSON.stringify(r));
await b.close();
