import { chromium } from 'playwright';
const BASE = 'http://192.168.2.97';
const CODE = 'groupe-de-gorrim-gaui';
const P1 = 'barb-ac5z8', P2 = 'mage-ac5z8';

const errs = [];
const log = (...a) => console.log('•', ...a);
function watch(p, who) {
  p.on('console', (m) => { if (m.type() === 'error') errs.push(`[${who}] ${m.text()}`); });
  p.on('pageerror', (e) => errs.push(`[${who}] pageerror ${e.message}`));
  p.on('response', (r) => { const u = r.url(); if (u.includes('/api/') && r.status() >= 400 && !u.endsWith('/moi') && !/marche|votes/.test(u)) errs.push(`[${who}] HTTP ${r.status()} ${r.request().method()} ${u.replace(BASE,'')}`); });
}
const pause = (ms) => new Promise((r) => setTimeout(r, ms));

const b = await chromium.launch();
const cN = await b.newContext({ viewport: { width: 1440, height: 900 } });
const pN = await cN.newPage(); watch(pN, 'TABLE');
const c1 = await b.newContext({ viewport: { width: 412, height: 915 }, isMobile: true });
const p1 = await c1.newPage(); watch(p1, 'P1');
const c2 = await b.newContext({ viewport: { width: 412, height: 915 }, isMobile: true });
const p2 = await c2.newPage(); watch(p2, 'P2');
const shot = (p, n) => p.screenshot({ path: `/work/browser-shots/cl-${n}.png` }).then(() => log('shot', n));

async function login(p, ident) {
  await p.goto(`${BASE}/joueur`, { waitUntil: 'networkidle' });
  await p.getByPlaceholder(/ex\. renegat/i).fill(ident);
  await p.getByRole('button', { name: /Entrer/i }).click();
  await p.waitForTimeout(1500);
}

try {
  // 1) Narrateur ouvre la table (groupe au hub)
  log('Narrateur ouvre la table');
  await pN.goto(`${BASE}/narrateur`, { waitUntil: 'networkidle' });
  await pN.locator('#codeTable').fill(CODE);
  await pN.getByRole('button', { name: /Ouvrir la table/i }).click();
  await pN.waitForURL(/\/table\//, { timeout: 15000 });
  await pN.waitForTimeout(3000);
  await shot(pN, '01-table-hub');

  // 2) Narrateur clique « Clôturer »
  log('Narrateur clique Clôturer');
  const btnClo = pN.getByRole('button', { name: /Clôturer/i });
  if (await btnClo.count()) {
    await btnClo.first().click();
    await pN.waitForTimeout(3000);
    await shot(pN, '02-table-cloture-ouverte');
    log('clôture ouverte par le narrateur');
  } else {
    errs.push('[TABLE] bouton Clôturer introuvable au hub');
    await shot(pN, '02-table-pas-de-bouton');
  }

  // 3) Joueurs : connexion + écran de clôture + confirmation
  log('P1 connexion + /cloture');
  await login(p1, P1);
  await p1.goto(`${BASE}/cloture/${CODE}`, { waitUntil: 'networkidle' });
  await p1.waitForTimeout(2500);
  await shot(p1, '03-p1-cloture');
  const conf1 = p1.getByRole('button', { name: /Confirmer le partage/i });
  if (await conf1.count()) { await conf1.first().click(); log('P1 a confirmé'); }
  else errs.push('[P1] bouton Confirmer introuvable');
  await p1.waitForTimeout(2000);
  await shot(p1, '04-p1-confirme');

  log('P2 connexion + /cloture');
  await login(p2, P2);
  await p2.goto(`${BASE}/cloture/${CODE}`, { waitUntil: 'networkidle' });
  await p2.waitForTimeout(2500);
  await shot(p2, '05-p2-cloture');
  const conf2 = p2.getByRole('button', { name: /Confirmer le partage/i });
  if (await conf2.count()) { await conf2.first().click(); log('P2 a confirmé (dernier → finalisation)'); }
  else errs.push('[P2] bouton Confirmer introuvable');

  // 4) Finalisation (job) → .cloture.terminee → épilogue partout
  log('Attente de la finalisation (épilogue)…');
  await p2.getByRole('button', { name: /Retour à l'accueil/i }).waitFor({ timeout: 30000 }).catch(() => log('(P2 épilogue non détecté)'));
  await pN.waitForTimeout(2000);
  await shot(pN, '06-table-epilogue');
  await shot(p1, '07-p1-epilogue');
  await shot(p2, '08-p2-epilogue');

  // 5) Joueurs : retour à l'accueil puis roster
  log('P1 retour accueil → roster');
  const ret1 = p1.getByRole('button', { name: /Retour à l'accueil/i });
  if (await ret1.count()) { await ret1.first().click(); await p1.waitForTimeout(1500); }
  await p1.goto(`${BASE}/joueur`, { waitUntil: 'networkidle' });
  await p1.waitForTimeout(2500);
  await shot(p1, '09-p1-roster-apres-cloture');

  log('Terminé.');
} catch (e) {
  errs.push(`FATAL ${e.message}`);
  await shot(pN, 'zz-table').catch(()=>{});
  await shot(p1, 'zz-p1').catch(()=>{});
}

console.log('\n===== ERREURS (' + errs.length + ') =====');
errs.forEach((e) => console.log('  ✗', e));
await b.close();
