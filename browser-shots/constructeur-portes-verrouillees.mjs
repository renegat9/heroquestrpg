// LOT K5a : le constructeur pose une porte VERROUILLÉE (à clé), et écrit une note « au 2e tour du MJ, ouvrir la porte ».
// Rien n'est enregistré — l'écran seul, la validation en direct.
//   node browser-shots/constructeur-portes-verrouillees.mjs
import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/classique';
const CASE = 26;
const erreurs = [];
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
p.on('pageerror', (e) => erreurs.push(e.message));
p.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text().slice(0, 200)); });

await p.goto(base + '/constructeur', { waitUntil: 'networkidle' });
await p.click('button:has-text("Nouvelle campagne")');
await p.click('.cst-onglets button:has-text("Campagne")');
await p.getByLabel('Slug').fill('essai-verrous');
await p.getByLabel('Titre').fill('Essai — portes verrouillées');
await p.click('.cst-onglets button:has-text("Plateau")');
const grille = async () => p.locator('svg.cst-grille').boundingBox();
const point = async (x, y, fx = 0.5, fy = 0.5) => { const g = await grille(); return [g.x + (x + fx) * CASE, g.y + (y + fy) * CASE]; };
await p.mouse.move(...await point(2, 2)); await p.mouse.down();
await p.mouse.move(...await point(6, 5), { steps: 4 }); await p.mouse.up();
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste button:has-text("Quête")');
const outil = async (titre) => p.click(`.cst-outil[title="${titre}"]`);
const clic = async (x, y, fx, fy) => p.mouse.click(...await point(x, y, fx, fy));
await outil('Ouvrir / fermer une salle'); await clic(3, 3);
await outil('Entrée'); await clic(2, 4);
await outil('Porte (sur le bord cliqué)');
await p.selectOption('select[title="Verrou de la porte"]', 'cle');
await clic(6, 3, 0.95, 0.5);
await p.click('button:has-text("Note")');
const note = p.locator('.cst-note').first();
await note.locator('select').nth(0).selectOption('tour_du_mj');
await note.locator('label:has-text("à partir du tour") input').fill('2');
await note.locator('.cst-effet select').first().selectOption('ouvrir_porte');
await p.waitForTimeout(1500);
console.log(JSON.stringify(await p.evaluate(() => ({
    validation: document.querySelector('.cst-etat')?.innerText.trim(),
    refus: [...document.querySelectorAll('.cst-refus li')].map((l) => l.innerText),
    effet: [...document.querySelectorAll('.cst-effet select')].map((s) => s.selectedOptions[0]?.textContent.trim()),
}))), '| erreurs :', JSON.stringify(erreurs));
await p.screenshot({ path: `${out}/94-constructeur-verrous.png`, fullPage: true });
await b.close();
