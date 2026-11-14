// MODE CLASSIQUE : ouvrir un groupe sur une campagne construite depuis la page du joueur.
//   node browser-shots/classique-ouvrir.mjs <ident du joueur> <titre de la campagne à choisir>
// Imprime le code du groupe ouvert (dernière ligne « CODE=… »), pour que `campagne/nettoyer.sh` le purge.
import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/classique';
const [ident, titre] = process.argv.slice(2);
const erreurs = [];

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();
p.on('pageerror', (e) => erreurs.push(`pageerror: ${e.message}`));
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2500);
await p.screenshot({ path: `${out}/10-joueur.png`, fullPage: true });

await p.click('button:has-text("Mode classique")');
await p.waitForTimeout(1500);
console.log('campagnes :', JSON.stringify(await p.evaluate(() => [...document.querySelectorAll('.joueur-classique-campagne')].map((n) => ({
    titre: n.querySelector('.joueur-classique-titre')?.textContent.trim(),
    detail: n.querySelector('.joueur-classique-detail')?.textContent.replace(/\s+/g, ' ').trim(),
    choisie: n.classList.contains('on'),
    grisee: n.classList.contains('off'),
})))));
console.log('débordement :', await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth));
await p.locator('.pcard-mini-form').first().scrollIntoViewIfNeeded();
await p.screenshot({ path: `${out}/11-formulaire.png` });

// Le brouillon ne se choisit pas ; la campagne demandée, si.
await p.locator('.joueur-classique-campagne', { hasText: 'Brouillon' }).click({ force: true });
await p.locator('.joueur-classique-campagne', { hasText: titre }).click();
await p.waitForTimeout(300);
console.log('après les clics :', JSON.stringify(await p.evaluate(() => [...document.querySelectorAll('.joueur-classique-campagne.on .joueur-classique-titre')].map((n) => n.textContent.trim()))));
await p.fill('.pcard-mini-form input.joueur-input-sm', 'Les Essayeurs');
await p.click('button:has-text("Ouvrir le groupe")');
await p.waitForURL(/\/manette\//, { timeout: 15000 });
await p.waitForTimeout(3500);
await p.screenshot({ path: `${out}/12-manette-hub.png` });
console.log('erreurs :', JSON.stringify(erreurs));
console.log('CODE=' + p.url().split('/manette/')[1].split('?')[0]);
await b.close();
