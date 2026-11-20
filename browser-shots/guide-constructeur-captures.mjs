// Les captures du GUIDE DU CONSTRUCTEUR (2026-11-20) — sur la campagne d'exemple `essai-guide`, inventée, chargée par
// `campagne-essai.sh charger essai-guide.json` puis retirée : aucune quête de livret officiel dans le dépôt. Rien n'est
// enregistré. Mode d'emploi complet : skill `outillage-dev-et-tests`, « Les captures du guide du constructeur ».
// Sortie : public/images/guide-constructeur/*.webp (converties par Chromium : pas de cwebp sur l'hôte).
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';
const SORTIE = '/work/public/images/guide-constructeur';
mkdirSync(SORTIE, { recursive: true });
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
const p = await ctx.newPage();
const conv = await ctx.newPage();
const erreurs = [];
p.on('pageerror', (e) => erreurs.push(e.message));

async function webp(png, nom) {
  const url = await conv.evaluate(async (b64) => {
    const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
    const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
    c.getContext('2d').drawImage(img, 0, 0);
    return c.toDataURL('image/webp', 0.86);
  }, png.toString('base64'));
  writeFileSync(`${SORTIE}/${nom}.webp`, Buffer.from(url.split(',')[1], 'base64'));
  console.log('✓', nom, Math.round(url.length * 0.75 / 1024), 'Ko');
}
/** Capture de la zone qui va du haut de `debut` au bas de `fin` (marge comprise), sur toute la largeur de `cadre`. */
async function zone(nom, debut, fin = debut, cadre = null, marge = 10) {
  const a = await debut.boundingBox(); const z = await fin.boundingBox();
  const c = cadre ? await cadre.boundingBox() : a;
  // `boundingBox()` est relatif à la fenêtre, la capture `fullPage` à la page : on ajoute le défilement.
  const dy = await p.evaluate(() => window.scrollY);
  const x = Math.max(0, Math.min(a.x, c.x) - marge), y = Math.max(0, a.y + dy - marge);
  const w = Math.max(a.x + a.width, z.x + z.width, c.x + c.width) - x + marge;
  const h = z.y + dy + z.height - y + marge;
  await webp(await p.screenshot({ clip: { x, y, width: w, height: h }, fullPage: true }), nom);
}
async function ouvrir() {
  await p.goto('http://localhost/constructeur', { waitUntil: 'networkidle' });
  await p.click('.cst-liste .cst-campagne:has-text("Exemple du guide")');
  await p.waitForTimeout(1200);
}

await ouvrir();
// 1. la liste et l'onglet Campagne
await webp(await p.screenshot({ clip: { x: 0, y: 0, width: 1280, height: 475 } }), 'campagne');
// 2. le plateau
await p.click('.cst-onglets button:has-text("Plateau")');
await p.waitForTimeout(600);
await webp(await p.screenshot({ clip: { x: 0, y: 0, width: 1280, height: 720 } }), 'plateau');
// 3. une quête : barre d'outils et carte
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste .cst-campagne >> nth=0');
await p.waitForTimeout(1200);
await zone('quete-carte', p.locator('.cst-barre').first(), p.locator('.cst-grille-cadre'), p.locator('.cst-barre').first());
// 4. l'outil Sortie, porte verrouillée
await p.click('.cst-outils button[title="Sortie"]');
await p.selectOption('.cst-outils select >> nth=0', 'porte');
await p.waitForTimeout(300);
await p.selectOption('.cst-outils select >> nth=1', 'note');
await p.waitForTimeout(300);
await zone('outil-sortie', p.locator('.cst-outils'));
// 5. la fiche de la quête : du titre à la récompense, fin, charge et captif
const titre = p.locator('label:has-text("Titre")').last();
await zone('fiche-quete', titre, p.locator('label.cst-case:has-text("Récompense")'), p.locator('.cst-grille-cadre'));
await zone('fin-captif', p.locator('span.cst-lbl:has-text("Suite")'), p.locator('label.cst-case:has-text("Captif à escorter")').locator('xpath=..'), p.locator('.cst-grille-cadre'));
// 6. les notes
await zone('notes', p.locator('.cst-notes'));
// 7. les règles des livrets : le Gardien (profil d'auteur) et un squelette inactif
await p.locator('.cst-livrets').evaluate((d) => (d.open = true));
await p.locator('.cst-livrets .cst-puce').nth(0).click();
await p.locator('.cst-livrets .cst-puce').nth(1).click();
await p.waitForTimeout(400);
const livrets = p.locator('.cst-livrets');
await zone('regles-livrets', livrets.locator('summary'), livrets.locator('.cst-bloc').first(), livrets);
// 8. un refus : un monstre posé dans le mur (la validation le dit, rien n'est enregistré)
await ouvrir();
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste .cst-campagne >> nth=0');
await p.waitForTimeout(1000);
await p.click('.cst-outils button[title="Monstre"]');
const g = await p.locator('svg.cst-grille').boundingBox();
const CASE = g.width / 26;
await p.mouse.click(g.x + CASE * 2.5, g.y + CASE * 2.5);   // case (2, 2) : la salle no-1, fermée dans cette quête
await p.locator('.cst-refus').last().waitFor({ timeout: 8000 });
await p.waitForTimeout(500);
await webp(await p.screenshot({ clip: { x: 0, y: 0, width: 1280, height: 120 } }), 'etat-refus');
await zone('refus', p.locator('.cst-refus').last());
console.log('erreurs', JSON.stringify(erreurs));
await b.close();
