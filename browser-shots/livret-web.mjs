// Vérifie la version WEB du livret : rendu desktop + mobile, images chargées,
// ancres du sommaire, et la carte de l'accueil qui y mène.
import { chromium } from 'playwright';
const b = await chromium.launch();
const out = '/work/browser-shots/livret';

for (const [nom, w, h, dsf] of [['50-web-desktop', 1280, 1000, 2],
                                ['51-web-mobile', 412, 915, 3]]) {
    const p = await (await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: dsf })).newPage();
    const cassees = [];
    p.on('response', r => { if (r.status() >= 400) cassees.push(`${r.status()} ${r.url()}`); });
    await p.goto('http://localhost/livret/', { waitUntil: 'networkidle' });
    await p.waitForTimeout(1500);
    await p.screenshot({ path: `${out}/${nom}.png` });
    const img = await p.evaluate(() => {
        const t = [...document.images];
        return { total: t.length, cassees: t.filter(i => i.complete && i.naturalWidth === 0).length };
    });
    console.log(nom, '| images:', img.total, '| cassées:', img.cassees,
                '| requêtes ≥400:', cassees.length, cassees.slice(0, 3).join(' ; '));
    if (nom.includes('desktop')) {
        await p.click('.somm a[href="#ch13"]');
        await p.waitForTimeout(1200);
        await p.screenshot({ path: `${out}/52-web-bestiaire.png` });
        console.log('  ancre ch13 →', await p.evaluate(() => document.querySelector('#ch13 h2')?.innerText.trim()));
    }
}

const p = await (await b.newContext({ viewport: { width: 1440, height: 980 }, deviceScaleFactor: 2 })).newPage();
await p.goto('http://localhost/', { waitUntil: 'networkidle' });
await p.waitForTimeout(2000);
await p.screenshot({ path: `${out}/40-accueil-livret.png` });
console.log('accueil → carte livret :', await p.locator('a.is-livret').count(),
            '| href :', await p.locator('a.is-livret').getAttribute('href'));
await b.close();
