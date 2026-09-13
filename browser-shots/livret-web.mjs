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
        // ⚠ TOUT lien interne doit avoir sa cible. Le lien « Sommaire » du
        // bandeau a pointé dans le vide : le marqueur de substitution du
        // gabarit et l'ancre étaient le même id="somm", donc le remplacement
        // emportait la cible. Rien ne cassait, le clic ne faisait simplement
        // rien — exactement le genre de promesse muette à vérifier en jeu.
        const orphelines = await p.evaluate(() => [...document.querySelectorAll('a[href^="#"]')]
            .map(a => a.getAttribute('href'))
            .filter(h => h.length > 1 && !document.querySelector(h)));
        console.log('  ancres sans cible :', orphelines.length, orphelines.join(' '));

        // ⚠ TOUTES les entrées, pas un échantillon : les ancres tombaient 600 px
        // trop bas parce que les images différées n'occupaient aucune place tant
        // qu'elles n'étaient pas chargées — un défaut qui grandit avec la
        // position dans le document, donc invisible sur les premiers chapitres.
        const liens = ['#somm', ...(await p.evaluate(() =>
            [...document.querySelectorAll('.somm a')].map(a => a.getAttribute('href'))))];
        const rates = [];
        for (const cible of liens) {
            const sel = cible === '#somm' ? '.lv-bar a[href="#somm"]' : `.somm a[href="${cible}"]`;
            await p.evaluate(() => window.scrollTo(0, 0));
            await p.waitForTimeout(250);
            await p.click(sel);
            await p.waitForTimeout(700);
            const haut = await p.evaluate(c => Math.round(
                document.querySelector(c).getBoundingClientRect().top), cible);
            if (!(haut >= 0 && haut < 120)) rates.push(`${cible} (${haut}px)`);
        }
        console.log(`  ancres testées : ${liens.length}`,
                    rates.length ? `⚠ ${rates.length} RATÉES : ${rates.join(', ')}` : '— toutes ✓');
        await p.screenshot({ path: `${out}/52-web-bestiaire.png` });
    }
}

const p = await (await b.newContext({ viewport: { width: 1440, height: 980 }, deviceScaleFactor: 2 })).newPage();
await p.goto('http://localhost/', { waitUntil: 'networkidle' });
await p.waitForTimeout(2000);
await p.screenshot({ path: `${out}/40-accueil-livret.png` });
console.log('accueil → carte livret :', await p.locator('a.is-livret').count(),
            '| href :', await p.locator('a.is-livret').getAttribute('href'));
await b.close();
