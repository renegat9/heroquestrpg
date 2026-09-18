// Diagnostic ponctuel (icônes manette, 2026-09-18) : ouvre le sous-choix
// « Avec quelle arme ? » (option Attaquer) et capture la liste des armes —
// pour vérifier l'icône de CHAQUE entrée (défaut #2 : sac à dos au lieu d'une
// arme). Jetable après usage, comme menu-creneaux.mjs dont il reprend le
// patron de connexion.
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots';
const prefixe = process.env.PREFIXE || 'souschoix-armes';
const [code, ident] = process.argv.slice(2);

const nav = await chromium.launch();
const tel = await nav.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();

await p.goto(`${base}/joueur`, { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);

await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForSelector('button.choice', { timeout: 15000 });
await p.waitForTimeout(1200);

await p.click('button.choice:has-text("Attaquer")');
await p.waitForTimeout(700);
await p.screenshot({ path: `${out}/${prefixe}.png` });

console.log('OK', prefixe);
await nav.close();
