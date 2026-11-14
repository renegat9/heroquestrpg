// MODE CLASSIQUE (`docs/plan-mode-classique.md` §7) : une quête CONSTRUITE vue à l'écran, table et manette.
// Partie montée par `campagne/preparer-classique.sh`, nettoyée par `campagne/nettoyer.sh`.
//   node browser-shots/classique-quete.mjs <code> <ident joueur 1> [préfixe des captures]
import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/classique';
const [code, ident, prefixe = ''] = process.argv.slice(2);
const erreurs = [];
const ecoute = (p, qui) => {
    p.on('pageerror', (e) => erreurs.push(`[${qui}] pageerror: ${e.message}`));
    p.on('console', (m) => { if (m.type() === 'error') erreurs.push(`[${qui}] console: ${m.text().slice(0, 200)}`); });
};

const b = await chromium.launch();

// La TABLE : ouverte par son code, comme un narrateur.
const tableCtx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const table = await tableCtx.newPage();
ecoute(table, 'table');
await table.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await table.fill('#codeTable', code);
await table.click('button:has-text("Ouvrir la table")');
await table.waitForTimeout(6000);
await table.screenshot({ path: `${out}/${prefixe}table.png` });
console.log('table :', table.url().replace(base, ''));

// La MANETTE du joueur 1.
const telCtx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const tel = await telCtx.newPage();
ecoute(tel, 'manette');
await tel.goto(base + '/joueur', { waitUntil: 'networkidle' });
await tel.fill('input[placeholder="ex. renegat"]', ident);
await tel.click('button:has-text("Entrer")');
await tel.waitForTimeout(2000);
await tel.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await tel.waitForTimeout(3000);
const etat = () => tel.evaluate(() => ({
    reflechit: document.body.innerText.includes('MJ réfléchit'),
    objectif: [...document.querySelectorAll('*')].find((n) => n.children.length === 0 && /^Objectif$/i.test(n.textContent.trim()))?.parentElement?.innerText?.replace(/\s+/g, ' ').slice(0, 160) ?? null,
    choix: [...document.querySelectorAll('button.choice')].map((x) => x.textContent.trim().replace(/\s+/g, ' ').replace(/^[a-z_]+/, '').replace(/chevron_right$/, '').slice(0, 40)),
}));
for (let i = 0; i < 25 && ((await etat()).reflechit || (await etat()).choix.length === 0); i++) await tel.waitForTimeout(1000);
console.log('manette :', JSON.stringify(await etat()));
await tel.screenshot({ path: `${out}/${prefixe}manette.png` });

console.log('erreurs :', JSON.stringify(erreurs));
await b.close();
