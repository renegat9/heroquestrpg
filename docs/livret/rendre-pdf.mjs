// Rend le livret en PDF A4 : la COUVERTURE à part (pleine page, sans pied de
// page), l'INTÉRIEUR avec son pied de page — puis `pdfunite` les recolle dans
// `public/livret/`, d'où nginx le sert et où l'accueil pointe.
// L'option `margin` de page.pdf() écrase @page, et le pied de page de Chromium
// s'imprime sur TOUTES les pages : d'où les deux passes.
import { chromium } from 'playwright';

const b = await chromium.launch();
const p = await b.newPage();
p.on('pageerror', e => console.log('ERREUR PAGE', e.message));

await p.goto('file:///work/docs/livret/couverture.html', { waitUntil: 'networkidle' });
await p.evaluate(() => document.fonts.ready);
await p.waitForTimeout(1200);
await p.pdf({
    path: '/work/docs/livret/.couverture.pdf',
    format: 'A4', printBackground: true, preferCSSPageSize: true,
    margin: { top: 0, bottom: 0, left: 0, right: 0 },
});
console.log('couverture OK');

await p.goto('file:///work/docs/livret/livret.html', { waitUntil: 'networkidle' });
await p.evaluate(() => document.fonts.ready);
await p.waitForTimeout(1500);
await p.pdf({
    path: '/work/docs/livret/.interieur.pdf',
    format: 'A4', printBackground: true, preferCSSPageSize: true,
    displayHeaderFooter: true,
    headerTemplate: '<div></div>',
    footerTemplate: '<div style="width:100%;font-family:Georgia,serif;font-size:7.5pt;'
        + 'color:#8a7458;padding:0 15mm;display:flex;justify-content:space-between">'
        + '<span>HeroQuest RPG — Livret de jeu</span>'
        + '<span class="pageNumber"></span></div>',
    margin: { top: '17mm', bottom: '16mm', left: '15mm', right: '15mm' },
});
console.log('intérieur OK');

await b.close();
