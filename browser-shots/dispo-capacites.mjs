/*
 * DISPONIBILITÉ DES CAPACITÉS sur la fiche du joueur (René, 2026-09-14 :
 * « afficher si une abileté est disponible ou non et pourquoi il n'est pas
 * disponible quand c'est le cas »).
 *
 * Vérification EN PARTIE RÉELLE, parce qu'un test vert ne dit pas si la phrase
 * tient dans 412 px ni si le grisé se lit encore. Le scénario, par classe :
 *  1. la fiche AU HUB — les fenêtres « une fois par quête » y sont fermées ;
 *  2. la quête démarre, la fiche rouvre ce qui doit l'être ;
 *  3. le CHEVALIER montre « Exige un bouclier équipé », le BERSERKER son
 *     plafond de PV chiffré.
 *
 * Usage : CLASSE=chevalier node browser-shots/dispo-capacites.mjs
 */
import { chromium } from 'playwright';

const base = 'http://localhost';
const classe = process.env.CLASSE ?? 'chevalier';
const nom = { chevalier: 'Roland', berserker: 'Gerhardt' }[classe] ?? 'Aldric';
const suffix = Date.now().toString().slice(-6);
const shot = (n) => `/work/browser-shots/dispo-${classe}-${n}.png`;

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 412, height: 915 } });
const page = await ctx.newPage();

await page.goto(base + '/joueur', { waitUntil: 'networkidle' });
await page.click('button:has-text("Créer un compte")');
await page.fill('input[placeholder="votre pseudo dans le jeu"]', `Cap${suffix}`);
await page.fill('input[placeholder="login unique (ex. renegat)"]', `cap${suffix}`);
await page.click('button:has-text("Créer mon compte")');
await page.waitForTimeout(1200);

await page.click('button:has-text("Créer un personnage")');
await page.waitForTimeout(400);
await page.click(`label:has-text("${classe[0].toUpperCase() + classe.slice(1)}")`);
await page.waitForTimeout(400);
await page.fill('input[placeholder="ex. Gorrim le Brutal"]', nom);
await page.click('button:has-text("Créer le personnage")');
await page.waitForTimeout(1500);

await page.click('button:has-text("Créer un groupe")');
await page.waitForTimeout(400);
await page.click('button:has-text("Forger la campagne")');
await page.waitForTimeout(2500);

const code = page.url().match(/\/manette\/([^?]+)/)?.[1];
console.log('CODE=' + code);

/** Ouvre l'onglet Fiche et capture. */
async function fiche(n) {
    const onglet = page.locator('button:has-text("Fiche"), [role="tab"]:has-text("Fiche")').first();
    if (await onglet.count()) { await onglet.click().catch(() => {}); await page.waitForTimeout(1000); }

    // ⚠ `fullPage` ne sert à RIEN ici : la manette scrolle DANS un conteneur,
    // pas dans la page — la capture « pleine page » s'arrêtait au bas du
    // viewport et coupait la section qu'on venait valider. On amène donc la
    // liste à l'écran, et on photographie l'ÉLÉMENT.
    const liste = page.locator('.talent-list, .empty-note').last();
    await liste.scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(400);
    await page.screenshot({ path: shot(n) });
    await liste.screenshot({ path: shot(n + '-talents') }).catch(() => {});
    const lu = await page.evaluate(() => [...document.querySelectorAll('.talent-item')].map((e) => ({
        nom: e.querySelector('.tn')?.textContent?.trim(),
        statut: e.querySelector('.tpuce')?.textContent?.trim(),
        cadence: e.querySelector('.tcad')?.textContent?.trim() ?? null,
        raison: e.querySelector('.traison')?.textContent?.trim() ?? null,
    })));
    console.log(n + '=' + JSON.stringify(lu, null, 1));
}

// 1. AU HUB : pas de quête, donc pas de fenêtre où dépenser.
await fiche('01-hub');

/** Appelle une route de l'API depuis la page (session + CSRF du SPA). */
async function api(chemin, methode, corps) {
    return page.evaluate(async ([chemin, methode, corps]) => {
        const jeton = document.querySelector('meta[name="csrf-token"]')?.content;
        const r = await fetch(chemin, {
            method: methode,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': jeton },
            body: corps ? JSON.stringify(corps) : undefined,
        });
        return r.status + ' ' + (await r.text()).slice(0, 300);
    }, [chemin, methode, corps]);
}

// 1 bis. Le CHEVALIER commence bouclier au poing, donc « Exige un bouclier
// équipé » ne se verrait jamais. On le lui retire — AU HUB, parce que
// `EquipementController` refuse (422) tout changement en quête.
if (classe === 'chevalier') {
    const moi = await page.evaluate(async () => (await (await fetch('/api/moi', { headers: { Accept: 'application/json' } })).json()).joueur.personnages[0]);
    const bouclier = (moi.equipement?.armes ?? []).find((a) => a.bouclier);
    console.log('BOUCLIER=' + JSON.stringify(bouclier ?? null));
    if (bouclier) {
        console.log('DESEQUIPE=' + await api(`/api/groupes/${code}/equipement`, 'DELETE',
            { personnage_id: moi.id, inventaire_id: bouclier.inventaire_id }));
    }
}

// 2. Table ouverte + prêt → la quête démarre.
const tableCtx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const tablePage = await tableCtx.newPage();
await tablePage.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await tablePage.fill('#codeTable', code);
await tablePage.click('button:has-text("Ouvrir la table")');
await tablePage.waitForTimeout(2500);

await page.bringToFront();

// ⚠ Le « Prêt » vit sur l'onglet HUB, pas sur la fiche : cliquer à l'aveugle
// depuis l'onglet ouvert n'a rien trouvé et m'a fait attendre deux minutes une
// quête que personne n'avait demandée. On passe donc par la VRAIE route, celle
// que la manette appelle (`POST /groupes/{code}/pret`).
const persoId = await page.evaluate(async () => (await (await fetch('/api/moi', { headers: { Accept: 'application/json' } })).json()).joueur.personnages[0].id);
console.log('PRET=' + await api(`/api/groupes/${code}/pret`, 'POST', { personnage_id: persoId, pret: true }));

// ⚠ On ATTEND la quête, on ne la suppose pas : la génération passe par la file
// et prend de dix à quarante secondes. Un `waitForTimeout` fixe m'a fait
// photographier le hub en croyant photographier la quête.
for (let i = 0; i < 40; i++) {
    await page.waitForTimeout(3000);
    const etat = await page.evaluate(async () => {
        const r = await fetch('/api/moi', { headers: { Accept: 'application/json' } });
        const j = await r.json();
        return (j.joueur?.personnages?.[0]?.competences ?? []).map((c) => c.raison);
    });
    console.log('attente#' + i + ' ' + JSON.stringify(etat));
    if (!etat.includes('Utilisable en quête seulement')) break;
}

await page.reload({ waitUntil: 'networkidle' });
await page.waitForTimeout(2500);
await fiche('02-quete');

// 3. FENÊTRE CONSOMMÉE. Aucune route joueur ne dépense une capacité de carte à
// la demande (elles se déclenchent sur un coup reçu), et provoquer le coup
// exact prendrait un tour entier de mise en scène. On attend donc que l'hôte
// marque la dépense sur la quête — le scénario affiche le code et l'id, la
// commande est dans le journal de travail.
console.log('ATTENTE_EPUISEE code=' + code + ' personnage=' + persoId);
for (let i = 0; i < 40; i++) {
    await page.waitForTimeout(3000);
    const raisons = await page.evaluate(async () => {
        const j = await (await fetch('/api/moi', { headers: { Accept: 'application/json' } })).json();
        return (j.joueur?.personnages?.[0]?.competences ?? []).map((c) => c.raison);
    });
    if (raisons.some((r) => (r ?? '').startsWith('Déjà utilisée'))) break;
}
await page.reload({ waitUntil: 'networkidle' });
await page.waitForTimeout(2500);
await fiche('03-epuisee');

console.log('DONE code=' + code);
await b.close();
