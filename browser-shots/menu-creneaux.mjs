// Rendu du MENU D'ACTION complet — pour voir les créneaux gratuits (∞) et les
// icônes par type sur une seule image (René, 2026-09-18 : « tu me montreras un
// rendu du menu avec les icônes »).
//
// La capture du livret (`30-manette-action`) est cadrée en haut de l'écran et
// coupe la liste : les options du bas — « Utiliser un objet » (gratuit) et
// « Battre en retraite » (gratuit, icône du coureur depuis ce jour) — n'y
// figurent jamais. Ce script déroule jusqu'au bout et prend les deux moitiés.
//
//   node browser-shots/menu-creneaux.mjs <code-groupe> <identifiant>
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots';
const prefixe = process.env.PREFIXE || 'menu-creneaux';
const [code, ident] = process.argv.slice(2);

const nav = await chromium.launch();
const tel = await nav.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();

await p.goto(`${base}/joueur`, { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")'); // ⚠ « Se connecter » est l'ONGLET, pas le bouton
await p.waitForTimeout(2000);

// ⚠ La manette vit sur /manette/{code}, pas /joueur/{code}.
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForSelector('button.choice', { timeout: 15000 });
await p.waitForTimeout(1200);

// Haut de la liste, puis bas : le menu dépasse toujours un écran de téléphone.
await p.screenshot({ path: `${out}/${prefixe}-haut.png` });

// ⚠ La manette a une zone de défilement INTERNE : ni `scrollingElement` ni
// `fullPage` ne la déroulent — les deux rendent la même image que le haut.
// On amène donc la DERNIÈRE option dans la vue, ce qui fait défiler le bon
// conteneur quel qu'il soit.
await p.locator('button.choice').last().scrollIntoViewIfNeeded();
await p.waitForTimeout(900);
await p.screenshot({ path: `${out}/${prefixe}-bas.png` });

// Et la liste ENTIÈRE d'un coup, sans découpe — c'est elle qui montre d'un
// seul regard quelles options portent le ∞ et lesquelles non.
await p.screenshot({ path: `${out}/${prefixe}-complet.png`, fullPage: true });

console.log('OK', prefixe);
await nav.close();
