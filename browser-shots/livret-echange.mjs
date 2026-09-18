// Captures de JEU pour le livret (docs/livret/) — le geste d'échange, le
// palier de quantité de « jeter », et le RAFRAÎCHISSEMENT de deux figures que
// ces deux gestes ont périmées : le menu du tour porte deux options de plus
// (30-manette-action), et la feuille de déplacement affiche désormais le
// trajet avant de le jouer (31-manette-deplacement, commit « Le vrai chemin »
// du 2026-09-17).
//
//   node browser-shots/livret-echange.mjs <code-groupe> <identifiant-de-Grom>
//
// ⚠ Ce script ne CRÉE rien : il photographie une campagne déjà montée par
// `browser-shots/campagne/preparer.sh` puis PROVOQUÉE par
// `browser-shots/livret-echange.php` (Grom et Borin orthogonalement
// adjacents, sacs garnis). Voir ce fichier pour l'état exact.
//
// Sortie : browser-shots/livret/*.png (convertis ensuite en .webp, voir
// docs/livret/README.md).
import { chromium } from 'playwright';

const base = 'http://localhost';
const out = '/work/browser-shots/livret';
const [code, ident] = process.argv.slice(2);
if (!code || !ident) {
    console.error('usage : livret-echange.mjs <code-groupe> <identifiant-de-Grom>');
    process.exit(1);
}

const b = await chromium.launch();
const snap = async (page, nom, attente = 800) => {
    await page.waitForTimeout(attente);
    await page.screenshot({ path: `${out}/${nom}.png` });
    console.log('OK', nom);
};

const tel = await b.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 3 });
const p = await tel.newPage();

await p.goto(base + '/joueur', { waitUntil: 'networkidle' });
await p.fill('input[placeholder="ex. renegat"]', ident);
await p.click('button:has-text("Entrer")'); // ⚠ « Se connecter » est l'ONGLET, pas le bouton
await p.waitForTimeout(2000);

await p.goto(`${base}/manette/${code}`, { waitUntil: 'networkidle' });
// Le menu arrive par .menu.propose (Reverb) ou par le rattrapage GET /menu au
// montage — laisser le temps aux deux avant de chercher les boutons.
await p.waitForSelector('button.choice', { timeout: 15000 });
await p.waitForTimeout(1000);

// ── 30-manette-action (RAFRAÎCHIE) ──────────────────────────────────────────
// Le menu porte maintenant « Échanger avec un allié adjacent » et « Jeter un
// objet — définitif » : la capture doit les montrer, pas seulement exister.
const boutonEchanger = p.locator('button.choice', { hasText: 'Échanger avec un allié adjacent' }).first();
const boutonJeter = p.locator('button.choice', { hasText: 'Jeter un objet' }).first();
await boutonEchanger.waitFor({ timeout: 8000 });
await boutonJeter.waitFor({ timeout: 8000 });
// Centre la vue sur « Échanger » : jeter est trois lignes au-dessus, les deux
// nouvelles entrées tiennent alors dans le même cadre.
await boutonEchanger.scrollIntoViewIfNeeded();
await p.waitForTimeout(300);
await snap(p, '30-manette-action', 600);

// ── 31-manette-deplacement (RAFRAÎCHIE) ─────────────────────────────────────
// Un tap ne part plus tout droit : il demande le trajet exact au serveur,
// l'affiche, et seul un second tap (ou « Y aller ») l'engage. La capture doit
// montrer CET aperçu — pas juste la mini-carte vide comme avant le correctif.
const boutonDeplacer = p.locator('button.choice', { hasText: 'Se déplacer' }).first();
await boutonDeplacer.scrollIntoViewIfNeeded();
await boutonDeplacer.click({ timeout: 8000, force: true });
await p.waitForSelector('.dep-carte', { timeout: 8000 });
await p.waitForTimeout(600);
// Une case accessible mais PAS la case de départ elle-même — la plus
// éloignée du lot pour que le trajet traverse plusieurs cases, pas une seule.
const casesAccessibles = p.locator('.dg-cell.accessible');
const nb = await casesAccessibles.count();
if (nb === 0) throw new Error('aucune case accessible dans la feuille de déplacement — écran bloqué, voir capture');
const caseVisee = casesAccessibles.nth(nb - 1);
await caseVisee.scrollIntoViewIfNeeded();
await caseVisee.click({ timeout: 5000 });
// L'aperçu part d'un POST réseau (apercuDeplacement) : attendre qu'il revienne
// avant de figer l'image, sinon la capture montre « Calcul du trajet… ».
await p.waitForFunction(() => {
    const hint = document.querySelector('.dep-apercu .dep-hint');
    return hint && !hint.textContent.includes('Calcul du trajet');
}, { timeout: 8000 });
await snap(p, '31-manette-deplacement', 500);
// Fermer SANS jouer le déplacement : Grom doit rester en place pour la suite.
await p.locator('button:has-text("Fermer")').click({ timeout: 5000 });
await p.waitForTimeout(500);

// ── 15-manette-echange ──────────────────────────────────────────────────────
await boutonEchanger.scrollIntoViewIfNeeded();
await boutonEchanger.click({ timeout: 8000, force: true });
await p.waitForSelector('text=Échanger avec qui ?', { timeout: 8000 });
await p.locator('button.choice', { hasText: 'Borin' }).first().click({ timeout: 8000 });
await p.waitForSelector('text=Échanger avec Borin', { timeout: 8000 });
await p.waitForTimeout(400);

// Une pièce dans CHAQUE sens : l'Épée large (encombrante) part vers Borin,
// son Bouclier (encombrant lui aussi) revient vers Grom — et la Fiole de
// soin (non encombrante) reste visible juste en dessous, sans le badge.
const ligneEpee = p.locator('.ech-pile', { hasText: 'Épée large' });
await ligneEpee.waitFor({ timeout: 5000 });
await ligneEpee.locator('button').nth(1).click(); // « + » : +1 vers Borin
const ligneBouclier = p.locator('.ech-pile', { hasText: 'Bouclier' });
await ligneBouclier.waitFor({ timeout: 5000 });
await ligneBouclier.locator('button').nth(1).click(); // « + » : +1 vers Grom
await p.waitForTimeout(400);
await snap(p, '15-manette-echange', 500);

// Retour propre, SANS valider — l'échange réel n'a pas eu lieu, ce n'est
// qu'un aperçu pour la capture.
await p.locator('button:has-text("Retour aux alliés")').click({ timeout: 5000 });
await p.waitForTimeout(300);
await p.locator('button:has-text("Retour aux actions")').click({ timeout: 5000 });
await p.waitForTimeout(500);

// ── 16-manette-jeter-quantite ────────────────────────────────────────────────
await boutonJeter.scrollIntoViewIfNeeded();
await boutonJeter.click({ timeout: 8000, force: true });
await p.waitForSelector('text=Quel objet jeter ?', { timeout: 8000 });
await p.locator('button.choice', { hasText: 'Fiole de soin' }).first().click({ timeout: 8000 });
await p.waitForSelector('text=Combien en jeter ?', { timeout: 8000 });
// 2 sur les 3 portées : montre un choix réel, pas le 1 par défaut qui
// n'aurait pas eu besoin d'un palier.
await p.locator('.cl-qte button').nth(1).click(); // « + »
await p.waitForTimeout(300);
await snap(p, '16-manette-jeter-quantite', 500);
// On s'arrête ici : ne PAS confirmer, l'objet ne doit pas être détruit pour
// de vrai sur cette campagne de harnais qu'on va nettoyer de toute façon,
// mais surtout pour que la capture reste celle du PALIER, pas de l'écran
// suivant.

await b.close();
console.log('DONE');
