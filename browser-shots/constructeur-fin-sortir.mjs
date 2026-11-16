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

// FIN « SORTIR » (2026-11-16) : rien n'est enregistré — l'écran seul, la validation en direct.
await p.goto(base + '/constructeur', { waitUntil: 'networkidle' });
await p.click('button:has-text("Nouvelle campagne")');
await p.click('.cst-onglets button:has-text("Campagne")');
await p.getByLabel('Slug').fill(slug);
await p.getByLabel('Titre').fill('Essai — fin sortir');
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
await p.locator('label:has-text("Fin") select').selectOption('sortir');
await p.getByLabel('Consigne dite aux joueurs').fill('Trouver la porte de sortie.');
await p.waitForTimeout(1500);
console.log(JSON.stringify(await p.evaluate(() => ({
    fin: [...document.querySelectorAll('label')].find((l) => l.textContent.trim().startsWith('Fin'))?.querySelector('select')?.selectedOptions[0]?.textContent.trim(), aide: document.querySelector('.cst-ligne .cst-sous')?.textContent.trim(),
    validation: document.querySelector('.cst-etat')?.innerText.trim(),
    refus: [...document.querySelectorAll('.cst-refus li')].map((l) => l.innerText),
}))), '| erreurs :', JSON.stringify(erreurs));
await p.screenshot({ path: `${out}/93-constructeur-fin-sortir.png`, fullPage: true });
await b.close();
