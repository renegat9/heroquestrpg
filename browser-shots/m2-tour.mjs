// Lot M2, second temps : le premier héros du joueur 1 termine son tour, la manette doit basculer SEULE sur le second.
//   node browser-shots/m2-tour.mjs <code> <ident joueur 1>
import { chromium } from 'playwright';
const base = 'http://localhost';
const out = '/work/browser-shots/m2';
const [code, ident] = process.argv.slice(2);

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();
const requetes = [];
p.on('response', (r) => { if (r.url().includes('/api/') && r.request().method() !== 'GET') requetes.push(`${r.request().method()} ${r.url().split('/api')[1]} → ${r.status()}`); });
await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2000);
await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
await p.waitForTimeout(3000);

const etat = () => p.evaluate(() => ({
    reflechit: document.body.innerText.includes('MJ réfléchit'),
    entete: document.querySelector('.bandeau-heros-pastille.est-courant .bandeau-heros-nom')?.textContent?.trim(),
    aLaMain: [...document.querySelectorAll('.bandeau-heros-pastille.a-la-main .bandeau-heros-nom')].map((n) => n.textContent.trim()),
    verrous: [...document.querySelectorAll('.bandeau-heros-pastille')].map((x) => x.disabled),
    choix: [...document.querySelectorAll('button.choice')].map((x) => ({ t: x.textContent.trim().replace(/\s+/g, ' ').slice(0, 30), off: x.disabled })),
}));

console.log('à l\'ouverture :', JSON.stringify(await etat()));
// Attendre que le menu soit jouable (au plus 25 s).
for (let i = 0; i < 25 && (await etat()).reflechit; i++) await p.waitForTimeout(1000);
console.log('avant le clic  :', JSON.stringify(await etat()));
await p.screenshot({ path: `${out}/09-avant-fin-de-tour.png` });

await p.locator('button.choice', { hasText: 'Terminer le tour' }).first().click({ timeout: 8000 });
await p.waitForTimeout(1500);
console.log('après le clic  :', JSON.stringify(await etat()));
// Une feuille de confirmation ? On liste les boutons visibles qui ne sont pas des choix de menu.
console.log('feuille :', JSON.stringify(await p.evaluate(() => [...document.querySelectorAll('.sheet button, .feuille button, [role="dialog"] button')].map((x) => x.textContent.trim().replace(/\s+/g, ' ').slice(0, 40)))));
await p.screenshot({ path: `${out}/10-apres-clic.png` });
await p.waitForTimeout(9000);
console.log('10 s plus tard :', JSON.stringify(await etat()));
await p.screenshot({ path: `${out}/11-second-heros.png` });
console.log('requêtes :', JSON.stringify(requetes));
await b.close();
