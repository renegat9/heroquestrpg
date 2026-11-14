// Lot M2 (`docs/plan-mode-classique.md` §5) : la manette d'un joueur qui pilote DEUX héros, vue à l'écran.
// Partie montée par `campagne/preparer-deux-heros.sh`, nettoyée par `campagne/nettoyer.sh`.
//   node browser-shots/m2-bandeau.mjs <code> <ident joueur 1> <ident joueur 2>
import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/m2';
const [code, ident1, ident2] = process.argv.slice(2);
const erreurs = [];

async function manette(b, ident, largeur) {
    const ctx = await b.newContext({ viewport: { width: largeur, height: 915 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage();
    p.on('pageerror', (e) => erreurs.push(`[${ident}] pageerror: ${e.message}`));
    p.on('console', (m) => { if (m.type() === 'error') erreurs.push(`[${ident}] console: ${m.text().slice(0, 200)}`); });
    await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
    await p.fill('input[placeholder="ex. renegat"]', ident);
    await p.click('button:has-text("Entrer")');
    await p.waitForTimeout(2000);
    await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
    await p.waitForTimeout(2500);
    return p;
}

// Ce que le bandeau montre, lu dans le DOM (une capture ne se relit pas à la machine).
const bandeau = (p) => p.evaluate(() => [...document.querySelectorAll('.bandeau-heros-pastille')].map((b) => ({
    nom: b.querySelector('.bandeau-heros-nom')?.textContent?.trim(),
    courant: b.classList.contains('est-courant'),
    aLaMain: b.classList.contains('a-la-main'),
    verrou: b.disabled,
    tour: !!b.querySelector('.bandeau-heros-tour'),
})));
const debordement = (p) => p.evaluate(() => ({
    page: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    bandeau: (() => { const n = document.querySelector('.bandeau-heros'); return n ? n.scrollWidth - n.clientWidth : null; })(),
}));
const cliche = async (p, nom, note) => {
    await p.screenshot({ path: `${out}/${nom}.png` });
    console.log(`${nom} — ${note}`, JSON.stringify(await bandeau(p)), JSON.stringify(await debordement(p)));
};

const b = await chromium.launch();
const j1 = await manette(b, ident1, 412);

await cliche(j1, '01-hub', 'joueur 1 au hub');
await j1.locator('.bandeau-heros-pastille').nth(1).click();
await j1.waitForTimeout(700);
await cliche(j1, '02-hub-second-heros', 'joueur 1 a touché sa seconde pastille');

// « Prêt » : UN bouton, qui doit prêter les DEUX héros du joueur.
await j1.locator('button', { hasText: 'Prêt pour la quête' }).first().click();
await j1.waitForTimeout(2500);
await cliche(j1, '03-pret', 'joueur 1 a appuyé sur Prêt');
console.log('prets après le joueur 1 :', JSON.stringify(await j1.evaluate(async (c) =>
    (await (await fetch(`/api/groupes/${c}/etat`, { headers: { Accept: 'application/json' } })).json()).groupe?.prets, code)));

const j2 = await manette(b, ident2, 412);
await cliche(j2, '04-hub-joueur-2', 'joueur 2 au hub');
await j2.locator('button', { hasText: 'Prêt pour la quête' }).first().click();

// La quête part quand les quatre héros sont prêts ; on attend le premier menu.
await j1.waitForTimeout(15000);
await cliche(j1, '05-quete-joueur-1', 'en quête, manette du joueur 1');
await cliche(j2, '06-quete-joueur-2', 'en quête, manette du joueur 2 (pas son tour)');

// Le premier héros du joueur 1 termine son tour : la manette doit basculer SEULE sur son second héros.
try { await j1.click('nav button:has-text("Action")', { timeout: 2000 }); await j1.waitForTimeout(500); } catch {}
console.log('options :', JSON.stringify(await j1.evaluate(() => [...document.querySelectorAll('button.choice')].map((x) => x.textContent.trim().replace(/\s+/g, ' ').slice(0, 40)))));
const finir = j1.locator('button.choice', { hasText: /Terminer|Attendre|Passer/ }).first();
await finir.scrollIntoViewIfNeeded({ timeout: 5000 });
await finir.click({ timeout: 8000, force: true });
await j1.waitForTimeout(9000);
await cliche(j1, '07-second-heros-a-la-main', 'après la fin du tour du premier héros');
console.log('options :', JSON.stringify(await j1.evaluate(() => [...document.querySelectorAll('button.choice')].map((x) => x.textContent.trim().replace(/\s+/g, ' ').slice(0, 40)))));

// Le même écran à 360 px.
const etroit = await manette(b, ident1, 360);
await cliche(etroit, '08-quete-360', 'joueur 1 à 360 px');

console.log('erreurs :', JSON.stringify(erreurs, null, 1));
await b.close();
