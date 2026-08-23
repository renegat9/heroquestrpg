// Capture de l'ÉTAL sur la manette — le seul écran qui affiche les images
// d'objets du catalogue. Tout passe par l'UI réelle et l'API réelle.
import { chromium } from 'playwright';

const base = 'http://localhost';
const suffix = Date.now().toString().slice(-6);
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 } });
const page = await ctx.newPage();

await page.goto(base + '/joueur', { waitUntil: 'networkidle' });
await page.click('button:has-text("Créer un compte")');
await page.fill('input[placeholder="votre pseudo dans le jeu"]', `Etal${suffix}`);
await page.fill('input[placeholder="login unique (ex. renegat)"]', `etal${suffix}`);
await page.click('button:has-text("Créer mon compte")');
await page.waitForTimeout(1200);
await page.click('button:has-text("Créer un personnage")');
await page.fill('input[placeholder="ex. Gorrim le Brutal"]', 'Etal');
await page.click('button:has-text("Créer le personnage")');
await page.waitForTimeout(1200);
await page.click('button:has-text("Créer un groupe")');
await page.waitForTimeout(400);
await page.click('button:has-text("Forger la campagne")');
await page.waitForTimeout(2500);

const code = page.url().match(/\/manette\/([^?]+)/)?.[1];
if (!code) { console.log('ÉCHEC : pas de code de groupe — ' + page.url()); await b.close(); process.exit(1); }
console.log('groupe = ' + code);

// Ouvre la phase de marché avec la SESSION de la page. ⚠ Laravel veut aussi
// l'en-tête X-XSRF-TOKEN : les seuls cookies rendent un 419.
const cookies = await ctx.cookies();
const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
const r = await page.request.post(`${base}/api/groupes/${code}/marche`, {
    headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
});
console.log('POST /marche → ' + r.status());

await page.reload({ waitUntil: 'networkidle' });
await page.waitForTimeout(2500);

// Combien d'images de l'étal ont vraiment chargé (naturalWidth > 0) ?
const bilan = await page.evaluate(() => {
    const imgs = [...document.querySelectorAll('img')];
    return {
        total: imgs.length,
        chargees: imgs.filter((i) => i.complete && i.naturalWidth > 0).length,
        cassees: imgs.filter((i) => i.complete && i.naturalWidth === 0).map((i) => i.currentSrc || i.src),
        png: imgs.filter((i) => (i.currentSrc || i.src).endsWith('.png')).map((i) => `${i.naturalWidth}x${i.naturalHeight} ${(i.currentSrc || i.src).replace(location.origin, '')}`),
        webp: imgs.filter((i) => (i.currentSrc || i.src).endsWith('.webp')).length,
    };
});
console.log(JSON.stringify(bilan, null, 2));

await page.screenshot({ path: '/work/browser-shots/etal.png', fullPage: true });
await b.close();
