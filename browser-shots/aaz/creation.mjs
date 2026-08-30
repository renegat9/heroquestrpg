import { chromium } from 'playwright';

const S = Date.now().toString().slice(-6);
const defauts = [];
const note = (ok, quoi) => { console.log(`${ok ? '  ✓' : '  ✗'} ${quoi}`); if (!ok) defauts.push(quoi); };

const b = await chromium.launch();

/** Crée un compte + un héros par les VRAIS écrans, et rend {page, ctx}. */
async function inscrire(pseudo, login, heros, classe, elements = []) {
  const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2, hasTouch: true, isMobile: true });
  const p = await ctx.newPage();
  const erreursJs = [];
  p.on('pageerror', (e) => erreursJs.push(e.message));
  p.on('console', (m) => { if (m.type() === 'error') erreursJs.push(m.text()); });

  await p.goto('http://localhost/joueur', { waitUntil: 'networkidle' });
  await p.click('button:has-text("Créer un compte")');
  await p.fill('input[placeholder="votre pseudo dans le jeu"]', pseudo);
  await p.fill('input[placeholder="login unique (ex. renegat)"]', login);
  await p.click('button:has-text("Créer mon compte")');
  await p.waitForTimeout(2500);
  note(await p.locator('text=voici tes héros').count() > 0, `compte créé : ${pseudo}`);

  await p.click('button:has-text("Créer un personnage")');
  await p.fill('input[placeholder="ex. Gorrim le Brutal"]', heros);
  await p.click(`.joueur-radio:has-text("${classe}")`);
  // Un lanceur choisit ses ÉLÉMENTS DE MAGIE — le bouton reste désactivé sans.
  for (const e of elements) { await p.click(`button:has-text("${e}")`); }
  await p.waitForTimeout(300);
  const pret = await p.locator('button:has-text("Créer le personnage")').isEnabled();
  note(pret, `formulaire de héros complet (${classe}${elements.length ? ' + ' + elements.join('/') : ''})`);
  await p.click('button:has-text("Créer le personnage")');
  await p.waitForTimeout(2500);
  note(await p.locator(`text=${heros}`).count() > 0, `héros créé : ${heros} (${classe})`);

  return { p, ctx, erreursJs };
}

// ---- Joueur 1 : compte, héros, campagne ------------------------------------
const j1 = await inscrire(`Renaud${S}`, `renaud${S}`, 'Aldwin', 'Magicien', ['Feu', 'Eau', 'Terre']);
await j1.p.screenshot({ path: 'browser-shots/aaz/01-roster.png' });

await j1.p.click('button:has-text("Créer un groupe")');
await j1.p.waitForTimeout(400);
await j1.p.fill('input[placeholder="Donjon classique"]', 'La crypte de Gorrim');
await j1.p.click('.joueur-radio:has-text("Court")').catch(() => {});
await j1.p.screenshot({ path: 'browser-shots/aaz/02-creer-groupe.png' });
await j1.p.click('button:has-text("Forger la campagne")');
await j1.p.waitForTimeout(4000);

const url = j1.p.url();
const code = url.match(/\/manette\/([^?]+)/)?.[1] ?? null;
note(code !== null, `campagne forgée, redirection manette (${code ?? url})`);
await j1.p.screenshot({ path: 'browser-shots/aaz/03-manette-hub.png' });

// ---- Joueur 2 : rejoint par le CODE ----------------------------------------
const j2 = await inscrire(`Marie${S}`, `marie${S}`, 'Krogar', 'Barbare');
await j2.p.click('button:has-text("Rejoindre")').catch(() => {});
await j2.p.waitForTimeout(400);
await j2.p.fill('input[placeholder="CODE-XX"]', code);
await j2.p.screenshot({ path: 'browser-shots/aaz/04-rejoindre.png' });
// Le libellé exact du bouton varie ; on prend le premier qui existe.
for (const t of ['Rejoindre le groupe', 'Rejoindre la partie', 'Rejoindre']) {
  const l = j2.p.locator(`button:has-text("${t}")`).last();
  if (await l.count() && await l.isEnabled()) { await l.click(); break; }
}
await j2.p.waitForTimeout(3500);
note(j2.p.url().includes('/manette/'), `2e joueur dans le groupe (${j2.p.url().split('/').pop()})`);
await j2.p.screenshot({ path: 'browser-shots/aaz/05-manette-j2.png' });

console.log('\nCODE=' + code);
console.log('LOGIN1=' + `renaud${S}`);
console.log('LOGIN2=' + `marie${S}`);
for (const e of [...j1.erreursJs, ...j2.erreursJs]) console.log('  ⚠ erreur JS : ' + e.slice(0, 160));
console.log('DEFAUTS=' + defauts.length);
await b.close();
