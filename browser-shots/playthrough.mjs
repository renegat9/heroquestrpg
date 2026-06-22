import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://192.168.2.97';
const SHOTS = '/work/browser-shots';
const stamp = Date.now().toString(36).slice(-5);

// Noms uniques pour éviter toute collision de compte.
const P1 = { ident: `barb-${stamp}`, pseudo: `Barbare ${stamp}`, hero: 'Gorrim' };
const P2 = { ident: `mage-${stamp}`, pseudo: `Mage ${stamp}`, hero: 'Elira' };

const log = (...a) => console.log('•', ...a);
const errs = [];
function watch(page, who) {
  page.on('console', (m) => { if (m.type() === 'error') errs.push(`[${who}] console.error: ${m.text()}`); });
  page.on('pageerror', (e) => errs.push(`[${who}] pageerror: ${e.message}`));
  page.on('requestfailed', (r) => {
    const u = r.url();
    if (u.includes('/api/') ) errs.push(`[${who}] requestfailed: ${r.failure()?.errorText} ${u}`);
  });
  page.on('response', (r) => {
    const u = r.url();
    if ((u.includes('/api/') || u.includes('/broadcasting/auth')) && r.status() >= 400 && !u.endsWith('/moi') && !u.includes('/menu'))
      errs.push(`[${who}] HTTP ${r.status()} ${r.request().method()} ${u.replace(BASE,'')}`);
  });
}
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/pt-${name}.png` }).then(() => log('shot', name));
const pause = (ms) => new Promise((r) => setTimeout(r, ms));

const b = await chromium.launch();

// ---- Contexte joueur 1 (barbare) ----
const c1 = await b.newContext({ viewport: { width: 412, height: 915 }, isMobile: true });
const p1 = await c1.newPage(); watch(p1, 'P1');
// ---- Contexte joueur 2 (magicien) ----
const c2 = await b.newContext({ viewport: { width: 412, height: 915 }, isMobile: true });
const p2 = await c2.newPage(); watch(p2, 'P2');
// ---- Contexte narrateur (table) ----
const cN = await b.newContext({ viewport: { width: 1440, height: 900 } });
const pN = await cN.newPage(); watch(pN, 'TABLE');

async function inscrire(p, who) {
  await p.goto(`${BASE}/joueur`, { waitUntil: 'networkidle' });
  await p.getByRole('button', { name: /Créer un compte/i }).click();
  await p.getByPlaceholder(/votre pseudo/i).fill(who.pseudo);
  await p.getByPlaceholder(/login unique/i).fill(who.ident);
  await p.getByRole('button', { name: /Créer mon compte/i }).click();
  await p.waitForTimeout(1500);
}

async function creerPerso(p, nom, classe, elements = []) {
  await p.getByRole('button', { name: /^.*Créer un personnage/i }).first().click();
  await p.getByPlaceholder(/Gorrim le Brutal/i).fill(nom);
  // radio de classe
  await p.locator('label.joueur-radio', { hasText: new RegExp(classe, 'i') }).first().click();
  for (const el of elements) {
    await p.locator('button.joueur-elem-btn', { hasText: el }).click();
  }
  await p.getByRole('button', { name: /Créer le personnage/i }).click();
  await p.waitForTimeout(1500);
}

try {
  // === 1) Joueur 1 : compte + barbare + groupe ===
  log('P1 inscription'); await inscrire(p1, P1);
  await shot(p1, '01-p1-connecte');
  log('P1 crée le barbare'); await creerPerso(p1, P1.hero, 'Barbare');
  await shot(p1, '02-p1-roster');

  log('P1 crée le groupe');
  await p1.getByRole('button', { name: /Créer un groupe/i }).first().click();
  await p1.getByPlaceholder(/Donjon classique/i).fill('Le Tombeau Test');
  await p1.getByRole('button', { name: /Forger la campagne/i }).click();
  await p1.waitForURL(/\/manette\//, { timeout: 15000 });
  const CODE = decodeURIComponent(p1.url().split('/manette/')[1]);
  log('CODE du groupe =', CODE);
  await p1.waitForTimeout(2500);
  await shot(p1, '03-p1-manette-hub');

  // === 2) Narrateur : ouvre la table ===
  log('Narrateur ouvre la table');
  await pN.goto(`${BASE}/narrateur`, { waitUntil: 'networkidle' });
  await pN.locator('#codeTable').fill(CODE);
  await pN.getByRole('button', { name: /Ouvrir la table/i }).click();
  await pN.waitForURL(/\/table\//, { timeout: 15000 });
  await pN.waitForTimeout(2500);
  await shot(pN, '04-table-hub');

  // === 3) Joueur 2 : compte + magicien + rejoint ===
  log('P2 inscription'); await inscrire(p2, P2);
  log('P2 crée le magicien (Feu + Eau)'); await creerPerso(p2, P2.hero, 'Magicien', ['Feu', 'Eau']);
  await shot(p2, '05-p2-roster');
  log('P2 rejoint par code');
  await p2.locator('input.joueur-code-input').first().fill(CODE);
  await p2.getByRole('button', { name: /Rejoindre/i }).first().click();
  await p2.waitForURL(/\/manette\//, { timeout: 15000 });
  await p2.waitForTimeout(2500);
  await shot(p2, '06-p2-manette-hub');

  // === 4) Les deux joueurs se déclarent prêts ===
  log('P1 prêt');
  await p1.reload({ waitUntil: 'networkidle' }); await p1.waitForTimeout(2000);
  const pret1 = p1.locator('button.pret-btn');
  if (await pret1.count()) { await pret1.click(); log('P1 a cliqué Prêt'); }
  else errs.push('[P1] bouton Prêt introuvable au hub');
  await p1.waitForTimeout(1500);
  await shot(p1, '07-p1-pret');

  log('P2 prêt (déclenche le démarrage)');
  await p2.reload({ waitUntil: 'networkidle' }); await p2.waitForTimeout(2000);
  const pret2 = p2.locator('button.pret-btn');
  if (await pret2.count()) { await pret2.click(); log('P2 a cliqué Prêt'); }
  else errs.push('[P2] bouton Prêt introuvable au hub');
  const tQuete0 = Date.now(); // départ de la quête (chrono latence menu)
  // Démarrage : les broadcasts passent par la file derrière les jobs LLM
  // (GenererMenu ~8 s/héros). On attend que la TABLE bascule en quête (preuve
  // du temps réel) plutôt qu'un délai fixe.
  log('Attente du basculement live en quête (table)…');
  await pN.locator('.dep-cell, .tile, .map, [class*="grille"], [class*="carte"]').first()
    .waitFor({ timeout: 30000 }).catch(() => log('(table : sélecteur carte non trouvé, on continue)'));
  await pN.waitForTimeout(4000);
  await shot(pN, '08-table-quete-live');
  await shot(p1, '09-p1-quete-live');
  await shot(p2, '10-p2-quete-live');

  // === 6) Tour de jeu : on joue le héros DONT C'EST LE TOUR (initiative) ===
  // Le menu d'action arrive sur le canal privé du joueur actif → on attend
  // qu'un des deux ait le bouton « Se déplacer » (jusqu'à 30 s : latence LLM).
  async function attendreActif(pages, ms = 85000) {
    const t0 = Date.now();
    while (Date.now() - t0 < ms) {
      for (const [p, nom] of pages) {
        if (await p.getByRole('button', { name: /Se déplacer/i }).count()) return [p, nom];
      }
      await pause(1500);
    }
    return [null, null];
  }
  log('Attente du héros actif (menu de déplacement)…');
  const [pa, nomActif] = await attendreActif([[p1, 'P1/barbare'], [p2, 'P2/magicien']]);
  log(`⏱ menu actif détecté ${((Date.now() - tQuete0) / 1000).toFixed(1)} s après le départ de la quête`);
  if (pa) {
    log('Héros actif =', nomActif);
    await shot(pa, '11-actif-menu');
    log('→ Se déplacer'); await pa.getByRole('button', { name: /Se déplacer/i }).first().click();
    await pa.waitForTimeout(1500);
    await shot(pa, '12-deplacement-carte');
    const cible = pa.locator('.dep-cell.accessible').first();
    if (await cible.count()) {
      await cible.click(); log('case choisie');
      await pa.waitForTimeout(4000);
      await shot(pa, '13-apres-deplacement');
      // Deux créneaux : après le déplacement, l'action doit rester possible.
      const restant = await pa.locator('button', { hasText: /Attaquer|Fouiller|Lancer|Terminer le tour/i }).count();
      log('options d\'action encore offertes après déplacement :', restant);
      await shot(pa, '14-menu-apres-deplacement');
    } else {
      log('aucune case accessible — capture pour analyse'); await shot(pa, 'zz-aucune-case');
    }
  } else {
    log('Aucun héros actif détecté dans le délai — capture des deux manettes');
    await shot(p1, 'zz-p1-pas-actif'); await shot(p2, 'zz-p2-pas-actif');
  }

  // Onglets du magicien (Fiche / Sorts / Sac)
  log('P2 explore ses onglets');
  await shot(p2, '15-p2-quete-action');
  for (const [t, n] of [['Fiche','16-p2-fiche'],['Sorts','17-p2-sorts'],['Sac','18-p2-sac']]) {
    const tb = p2.getByRole('button', { name: new RegExp(t,'i') });
    if (await tb.count()) { await tb.first().click(); await p2.waitForTimeout(800); await shot(p2, n); }
  }

  log('Terminé sans exception fatale.');
} catch (e) {
  errs.push(`FATAL: ${e.message}`);
  log('EXCEPTION', e.message);
  await shot(p1, 'zz-p1-fatal').catch(()=>{});
  await shot(p2, 'zz-p2-fatal').catch(()=>{});
  await shot(pN, 'zz-table-fatal').catch(()=>{});
}

console.log('\n===== ERREURS CAPTURÉES (' + errs.length + ') =====');
for (const e of errs) console.log('  ✗', e);
console.log('==================================\n');

await b.close();
