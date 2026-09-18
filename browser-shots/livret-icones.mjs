// Captures de JEU pour le livret — les QUATRE figures périmées par la
// correction d'icônes du 2026-09-18 (« Équiper »/« Ranger » partageaient leurs
// icônes avec « Attaquer »/« Utiliser un objet » ; les ARMES du sous-choix
// d'attaque portaient un sac à dos au lieu d'épées).
//
//   node browser-shots/livret-icones.mjs <code-groupe>
//
// ⚠ Ne CRÉE rien : photographie une campagne déjà montée par
// browser-shots/campagne/preparer.sh puis PROVOQUÉE par
// browser-shots/livret-icones.php (Grom : Rapière + Épée courte en main,
// sac garni, monstre au contact). Identifiants de connexion lus directement
// depuis la base (Joueur.identifiant), preparer.sh (la variante complète, pas
// -livret) ne les écrit pas dans des fichiers comme preparer-livret.sh.
//
// Sortie : browser-shots/livret/{30,34,32,16}-*.png (converties ensuite en
// .webp, voir docs/livret/README.md).
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code] = process.argv.slice(2);
if (!code) {
    console.error('usage : livret-icones.mjs <code-groupe>');
    process.exit(1);
}

// Identifiants de connexion des deux héros dont on a besoin — lus depuis la
// base via `docker compose exec app php artisan tinker` en amont et collés
// ici en argv serait plus fragile qu'un identifiant fixe déjà connu du
// tinker de mise en place (bar1/mag4 + le SUF du groupe) : on les passe donc
// en variables d'environnement pour ne rien deviner côté script.
const identGrom = process.env.IDENT_GROM;
const identAldric = process.env.IDENT_ALDRIC;
if (!identGrom || !identAldric) {
    console.error('usage : IDENT_GROM=... IDENT_ALDRIC=... node browser-shots/livret-icones.mjs <code-groupe>');
    process.exit(1);
}

const b = await chromium.launch();

async function connecter(page, ident) {
    await page.goto(base + '/joueur', { waitUntil: 'networkidle' });
    await page.fill('input[placeholder="ex. renegat"]', ident);
    await page.click('button:has-text("Entrer")'); // ⚠ « Se connecter » est l'ONGLET, pas le bouton
    await page.waitForTimeout(2000);
    await page.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
}

// ── Grom : menu d'action, sous-choix d'armes, attaque réelle ───────────────
const telGrom = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const grom = await telGrom.newPage();
await connecter(grom, identGrom);
await grom.waitForSelector('button.choice', { timeout: 15000 });
await grom.waitForTimeout(1200);

// ── 30-manette-action ───────────────────────────────────────────────────────
// Haut du menu : Se déplacer / Jeter / Équiper / Ranger / Utiliser un objet —
// exactement le cadrage qui montrait avant le sac à dos partagé.
await grom.screenshot({ path: `${out}/30-manette-action.png` });
console.log('OK 30-manette-action');

// ── 34-manette-attaque-deux-armes ───────────────────────────────────────────
const boutonAttaquer = grom.locator('button.choice', { hasText: 'Attaquer' }).first();
await boutonAttaquer.scrollIntoViewIfNeeded();
await boutonAttaquer.click({ timeout: 8000, force: true });
await grom.waitForSelector('text=Avec quelle arme ?', { timeout: 8000 });
await grom.waitForTimeout(500);
await grom.screenshot({ path: `${out}/34-manette-attaque-deux-armes.png` });
console.log('OK 34-manette-attaque-deux-armes');

// ── Jouer l'attaque pour de vrai (Épée courte, la première entrée) ─────────
await grom.locator('button.choice', { hasText: 'Épée courte' }).first().click({ timeout: 8000, force: true });
await grom.waitForSelector('text=Choisis une cible', { timeout: 8000 });
await grom.waitForTimeout(400);
// Une seule cible en vue (le monstre au contact) : la première ChoiceCard de
// la feuille, qui n'est pas le bouton « Retour aux armes » (classe .btn).
await grom.locator('.sheet .choices button.choice').first().click({ timeout: 8000, force: true });
// Attente de la résolution : le menu se regénère (nouvelle option ou créneau
// consommé) — on attend que la feuille de ciblage se ferme.
await grom.waitForSelector('text=Choisis une cible', { state: 'detached', timeout: 10000 });
console.log('OK attaque jouée — attente de la résolution/narration');
await grom.screenshot({ path: `${out}/_debug-apres-attaque.png` });

// ── 16-manette-jeter-quantite (sur Grom, après son attaque : « Jeter » est
//    gratuit et reste au menu même après avoir agi) ─────────────────────────
const boutonJeter = grom.locator('button.choice', { hasText: 'Jeter un objet' }).first();
await boutonJeter.waitFor({ timeout: 30000 });
await grom.screenshot({ path: `${out}/_debug-menu-post-attaque.png` });
await boutonJeter.scrollIntoViewIfNeeded();
await boutonJeter.click({ timeout: 8000, force: true });
await grom.waitForSelector('text=Quel objet jeter ?', { timeout: 8000 });
await grom.locator('button.choice', { hasText: 'Fiole de soin' }).first().click({ timeout: 8000, force: true });
await grom.waitForSelector('text=Combien en jeter ?', { timeout: 8000 });
await grom.locator('.cl-qte button').nth(1).click(); // « + » : 1 → 2 sur les 3 portées
await grom.waitForTimeout(400);
await grom.screenshot({ path: `${out}/16-manette-jeter-quantite.png` });
console.log('OK 16-manette-jeter-quantite');
// On s'arrête là, sans confirmer — l'objet ne doit pas être détruit pour de
// vrai, et la capture doit rester celle du PALIER, pas de l'écran suivant.

await telGrom.close();

// ── Aldric : hors de son tour, le Fil du combat (réamorcé depuis
//    EtatGroupe.journal_combat au chargement — pas besoin d'être connecté
//    en direct pendant l'attaque de Grom) ───────────────────────────────────
const telAldric = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const aldric = await telAldric.newPage();
await connecter(aldric, identAldric);
await aldric.waitForSelector('text=Fil du combat', { timeout: 15000 });
await aldric.waitForTimeout(800);
await aldric.screenshot({ path: `${out}/32-manette-combat.png` });
console.log('OK 32-manette-combat');
await telAldric.close();

await b.close();
console.log('DONE');
