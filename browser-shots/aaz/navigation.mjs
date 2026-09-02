import { chromium } from 'playwright';
const ok = [], ko = [];
const v = (c, q) => (c ? ok : ko).push(q);

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2, hasTouch: true, isMobile: true });
const p = await ctx.newPage();
await p.goto('http://localhost/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', process.env.ID);
await p.click('button:has-text("Entrer")');
await p.waitForTimeout(2500);
await p.click('button:has-text("Reprendre la partie")');
await p.waitForTimeout(9000);

const titres = async () => (await p.locator('.choice .ttl').allInnerTexts()).map((t) => t.split('\n')[0].trim());
const niveau1 = await titres();
console.log('MENU :', niveau1.join(' | '));
v(niveau1.filter((t) => t.startsWith('Lancer')).length === 1, `un seul « Lancer un sort » (${niveau1.length} options au total)`);

// ---- NIVEAU 2 : la liste des sorts ----
await p.locator('.choice:has-text("Lancer un sort")').tap();
await p.waitForTimeout(700);
v(await p.locator('.cl-groupe').count() >= 2, `sorts groupés par élément (${await p.locator('.cl-groupe').count()} groupes)`);
v(await p.locator('.sheet .choice').count() === 9, `les 9 sorts sont listés (${await p.locator('.sheet .choice').count()})`);
const retour2 = (await p.locator('.cl-retour').innerText()).trim();
v(retour2.includes('actions'), `retour de niveau 2 nomme sa destination : « ${retour2} »`);
await p.screenshot({ path: 'browser-shots/aaz/10-liste-sorts.png' });

// ---- NIVEAU 3 : les cibles ----
await p.locator('.sheet .choice').first().tap();
await p.waitForTimeout(700);
const enCiblage = await p.locator('.cible-retour').count() > 0;
v(enCiblage, 'le ciblage s\'ouvre pour un sort qui a des cibles');
if (enCiblage) {
  const retour3 = (await p.locator('.cible-retour').innerText()).trim();
  v(retour3.includes('sorts'), `retour de niveau 3 nomme sa destination : « ${retour3} »`);
  await p.screenshot({ path: 'browser-shots/aaz/11-ciblage.png' });

  // ---- LE POINT DE RENÉ : remonter d'un cran, pas tout fermer ----
  await p.locator('.cible-retour').tap();
  await p.waitForTimeout(600);
  v(await p.locator('.cl-groupe').count() >= 2, 'retour → on retombe sur LA LISTE DES SORTS, pas sur le menu');
  await p.screenshot({ path: 'browser-shots/aaz/12-retour-liste.png' });

  // ---- puis du niveau 2 vers le menu ----
  await p.locator('.cl-retour').tap();
  await p.waitForTimeout(600);
  v(await p.locator('.sheet').count() === 0 && (await titres()).length === niveau1.length,
    'retour → on retombe sur le menu d\'action');
}

// ---- Le tap sur le FOND dépile d'un cran ----
await p.locator('.choice:has-text("Lancer un sort")').tap();
await p.waitForTimeout(600);
await p.locator('.sheet .choice').first().tap();
await p.waitForTimeout(600);
await p.mouse.click(206, 60); // hors de la feuille
await p.waitForTimeout(600);
v(await p.locator('.cl-groupe').count() >= 2, 'tap sur le fond → un seul cran, on reste dans la liste');

// ---- La liste d'objets, coût affiché ----
await p.locator('.cl-retour').tap();
await p.waitForTimeout(500);
const objets = p.locator('.choice:has-text("Utiliser un objet")');
v(await objets.count() === 1, 'une option « Utiliser un objet »');
if (await objets.count()) {
  await objets.tap();
  await p.waitForTimeout(700);
  const lignes = await p.locator('.sheet .choice .meta').allInnerTexts();
  console.log('OBJETS :', lignes.join(' | '));
  v(lignes.some((l) => l.includes('gratuit')), 'chaque ligne dit son coût');
  await p.screenshot({ path: 'browser-shots/aaz/13-liste-objets.png' });
}

console.log('');
for (const o of ok) console.log('  ✓ ' + o);
for (const k of ko) console.log('  ✗ ' + k);
console.log('DEFAUTS=' + ko.length);
await b.close();
