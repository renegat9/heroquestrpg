// LE CONSTRUCTEUR DE CAMPAGNES, éprouvé dans un navigateur : une campagne INVENTÉE de bout en bout — deux salles,
// une quête (salles ouvertes, porte, entrée, monstre-cible, consigne, une note), validation du serveur, enregistrement.
//   node browser-shots/constructeur.mjs <slug d'essai>
// ⚠ Écrit dans la vraie base (campagnes_construites) : retirer la campagne d'essai ensuite.
import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/classique';
const slug = process.argv[2] ?? 'essai-constructeur';
const CASE = 26;
const erreurs = [];

const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
p.on('pageerror', (e) => erreurs.push(e.message));
p.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text().slice(0, 200)); });

await p.goto(base + '/constructeur', { waitUntil: 'networkidle' });
await p.waitForTimeout(800);
await p.screenshot({ path: `${out}/90-constructeur-liste.png` });

await p.click('button:has-text("Nouvelle campagne")');
await p.click('.cst-onglets button:has-text("Campagne")');
await p.getByLabel('Slug').fill(slug);
await p.getByLabel('Titre').fill('Essai — le constructeur');

// Le plateau : deux salles accolées, tracées à la souris.
await p.click('.cst-onglets button:has-text("Plateau")');
const grille = async () => p.locator('svg.cst-grille').boundingBox();
const point = async (x, y, fx = 0.5, fy = 0.5) => { const g = await grille(); return [g.x + (x + fx) * CASE, g.y + (y + fy) * CASE]; };
async function tracer(x0, y0, x1, y1) {
    await p.mouse.move(...await point(x0, y0)); await p.mouse.down();
    await p.mouse.move(...await point(x1, y1), { steps: 4 }); await p.mouse.up();
}
await tracer(2, 2, 6, 5);
await tracer(7, 2, 10, 5);
await p.screenshot({ path: `${out}/91-constructeur-plateau.png` });

// La quête.
await p.click('.cst-onglets button:has-text("Quêtes")');
await p.click('.cst-quetes-liste button:has-text("Quête")');
const outil = async (titre) => p.click(`.cst-outil[title="${titre}"]`);
const clic = async (x, y, fx, fy) => p.mouse.click(...await point(x, y, fx, fy));
await outil('Ouvrir / fermer une salle'); await clic(3, 3); await clic(8, 3);
await outil('Porte (sur le bord cliqué)'); await clic(6, 3, 0.92, 0.5);
await outil('Entrée'); await clic(2, 4);
await outil('Monstre'); await clic(9, 3);
await outil('Cible de fin (un monstre)'); await clic(9, 3);
await p.getByLabel('Consigne dite aux joueurs').fill('Abattre le gardien de la seconde salle.');
await p.click('.cst-notes button:has-text("Note")');
await p.locator('textarea[placeholder="Texte lu"]').fill('Un texte inventé, lu quand on fouille la première salle.');
await p.waitForTimeout(1500);

const etat = await p.evaluate(() => ({
    validation: document.querySelector('.cst-etat')?.innerText.trim(),
    refus: [...document.querySelectorAll('.cst-refus li')].map((l) => l.innerText),
}));
console.log('avant enregistrement :', JSON.stringify(etat));
await p.screenshot({ path: `${out}/92-constructeur-quete.png`, fullPage: true });

await p.click('.cst-barre button:has-text("Enregistrer")');
await p.waitForTimeout(1200);
console.log('après :', JSON.stringify(await p.evaluate(() => ({
    message: document.querySelector('.cst-message')?.innerText ?? null,
    liste: [...document.querySelectorAll('.cst-liste .cst-campagne b')].map((n) => n.innerText),
}))));
console.log('débordement :', await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), '| erreurs :', JSON.stringify(erreurs));
await b.close();
