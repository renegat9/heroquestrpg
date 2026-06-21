/* =========================================================================
   useApi — client HTTP réel vers l'API Laravel (docs/contrat-api.md).

   Session & CSRF : la SPA est servie par Laravel sur la même origine
   (app.blade.php). L'auth est en SESSION (cookie) et le groupe `api`
   reçoit les middlewares session + CSRF dans bootstrap/app.php — Sanctum
   n'est pas installé. Le choix le plus simple est donc :
   - `credentials: 'same-origin'` pour envoyer le cookie de session ;
   - en-tête `X-XSRF-TOKEN` lu dans le cookie `XSRF-TOKEN` que Laravel
     pose/rafraîchit à chaque réponse. Surtout PAS la <meta csrf-token> :
     le login régénère la session, le jeton du blade devient périmé et
     tous les POST suivants seraient rejetés (419) sans rechargement.
     Repli sur la meta tant que le cookie n'existe pas (premier appel).

   La boucle de jeu (contrat) : la manette POSTe un choix → 202 → le
   moteur résout → l'état (.groupe.etat) et le prochain menu
   (.menu.propose) reviennent par Reverb (voir useEcho).
   ========================================================================= */

const BASE = '/api';

/** Erreur API typée : `status` 0 = erreur réseau (serveur injoignable). */
export class ApiError extends Error {
    constructor(status, message, donnees = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.donnees = donnees;
    }
}

/**
 * Vrai si l'erreur justifie le repli en mode démo : API injoignable
 * (erreur réseau) ou session refusée (401). Les autres statuts (422
 * option illégale, 404, 500…) sont des erreurs « réelles » à afficher.
 */
export function estErreurDemo(e) {
    return e instanceof ApiError && (e.status === 0 || e.status === 401);
}

function jetonCsrf() {
    const cookie = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='));

    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
    }

    const meta = document.querySelector('meta[name="csrf-token"]')?.content;

    return meta ? { 'X-CSRF-TOKEN': meta } : {};
}

async function request(method, path, body = undefined) {
    let response;
    try {
        response = await fetch(`${BASE}${path}`, {
            method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...jetonCsrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
    } catch (e) {
        throw new ApiError(0, `API injoignable (${method} ${path}) : ${e.message}`);
    }

    // 204 (deconnexion) et 202 (choix accepté) peuvent être sans corps.
    const texte = await response.text();
    const donnees = texte ? JSON.parse(texte) : null;

    if (!response.ok) {
        throw new ApiError(
            response.status,
            donnees?.message ?? `API ${method} ${path} → ${response.status}`,
            donnees,
        );
    }
    return donnees;
}

export function useApi() {
    return {
        get: (path) => request('GET', path),
        post: (path, body) => request('POST', path, body),
        put: (path, body) => request('PUT', path, body),
        delete: (path) => request('DELETE', path),

        // ---- authentification (session Laravel, guard `joueur`) ----

        /** POST /api/connexion {identifiant} → {joueur}. Jeu LAN : nom seul, sans mot de passe. */
        connexion: (identifiant) =>
            request('POST', '/connexion', { identifiant }),

        /**
         * POST /api/inscription {pseudo, identifiant}
         * → crée le compte et connecte ; 422 si identifiant pris. Sans mot de passe.
         */
        inscription: ({ pseudo, identifiant }) =>
            request('POST', '/inscription', { pseudo, identifiant }),

        /** POST /api/deconnexion → 204. */
        deconnexion: () => request('POST', '/deconnexion'),

        /**
         * GET /api/moi. Le serveur imbrique les personnages sous `joueur`
         * (`{joueur: {…, personnages}}`) ; on APLATIT en `{joueur, personnages}`
         * pour que les appelants (`const { joueur, personnages } = await moi()`)
         * reçoivent bien la liste au niveau racine (contrat).
         */
        moi: async () => {
            const r = await request('GET', '/moi');
            return { joueur: r?.joueur ?? null, personnages: r?.joueur?.personnages ?? [] };
        },

        // ---- personnages du roster ----

        /**
         * POST /api/personnages {nom, classe, elements?} → {personnage}.
         * Crée un perso libre dans le roster du joueur.
         * `elements` (magicien) : 2 éléments parmi feu/eau/terre/air.
         */
        creerPersonnage: ({ nom, classe, elements }) =>
            request('POST', '/personnages', elements ? { nom, classe, elements } : { nom, classe }),

        // ---- table (Narrateur — session sans compte) ----

        /**
         * POST /api/table {code} → {groupe: EtatGroupe}.
         * Ouvre une SESSION DE TABLE (cookie) pour ce groupe.
         * 404 si code inconnu.
         */
        ouvrirTable: (code) => request('POST', '/table', { code }),

        /**
         * POST /api/table/ping — heartbeat : rafraîchit « table active »
         * (cache TTL 30 s). À envoyer toutes les ~15 s.
         */
        pingTable: () => request('POST', '/table/ping'),

        /** POST /api/table/quitter — ferme la session de table. */
        quitterTable: () => request('POST', '/table/quitter'),

        // ---- groupes / campagne ----

        /**
         * POST /api/groupes {nom, theme, longueur, ton, personnage_id} → {groupe}.
         * `personnage_id` requis par le contrat (perso libre du joueur).
         */
        creerGroupe: (payload) => request('POST', '/groupes', payload),

        /** POST /api/groupes/{identifiant}/joueurs {personnage_id} ou {nom, classe,
         *  elements?} → {personnage}. `elements` (magicien) : 2 éléments du
         *  grimoire parmi feu/eau/terre/air — contrat « Sorts des héros ». */
        rejoindreGroupe: (identifiant, payload) =>
            request('POST', `/groupes/${identifiant}/joueurs`, payload),

        /**
         * POST /api/groupes/{identifiant}/pret {personnage_id, pret} →
         * (dé)marque un perso prêt. Si tous prêts + narrateur actif →
         * démarre la quête.
         */
        marquerPret: (identifiant, personnageId, pret) =>
            request('POST', `/groupes/${identifiant}/pret`, { personnage_id: personnageId, pret }),

        /** GET /api/groupes/{identifiant}/etat → EtatGroupe. */
        getEtat: (identifiant) => request('GET', `/groupes/${identifiant}/etat`),

        /**
         * GET etat AVEC REPRISE : juste après l'ouverture de la table ou la
         * création d'un groupe, la session peut ne pas être encore visible côté
         * serveur (course d'écriture) → 401/403 transitoire. On réessaie quelques
         * fois avant d'abandonner, ce qui évite de retomber à tort sur la démo.
         */
        getEtatReprise: async (identifiant, tentatives = 4, delaiMs = 500) => {
            let derniere;
            for (let i = 0; i < tentatives; i++) {
                try {
                    return await request('GET', `/groupes/${identifiant}/etat`);
                } catch (e) {
                    derniere = e;
                    const transitoire = e instanceof ApiError && [0, 401, 403, 500, 502, 503].includes(e.status);
                    if (!transitoire || i === tentatives - 1) throw e;
                    await new Promise((r) => setTimeout(r, delaiMs));
                }
            }
            throw derniere;
        },

        /** POST /api/groupes/{identifiant}/quetes → {quete} (démarre la quête suivante). */
        demarrerQuete: (identifiant) => request('POST', `/groupes/${identifiant}/quetes`),

        /**
         * POST /api/groupes/{identifiant}/choix {option_id, parametres?} → 202.
         * La résolution arrive ensuite par Reverb (.groupe.etat / .menu.propose).
         */
        envoyerChoix: (identifiant, payload) =>
            request('POST', `/groupes/${identifiant}/choix`, payload),

        // ---- phase marché (contrat « Phase marché », au hub uniquement) ----

        /** POST /groupes/{id}/marche {profil?} → ouvre la phase (broadcast .marche.ouvert). */
        ouvrirMarche: (identifiant, payload = {}) =>
            request('POST', `/groupes/${identifiant}/marche`, payload),

        /** GET /groupes/{id}/marche → EtatMarche. */
        getMarche: (identifiant) => request('GET', `/groupes/${identifiant}/marche`),

        /**
         * PUT /groupes/{id}/marche/panier {achats: [{objet_id, quantite}],
         * ventes: [{inventaire_id}]} — remplace le panier du joueur et
         * annule sa confirmation (broadcast .marche.maj).
         */
        majPanier: (identifiant, { achats = [], ventes = [] } = {}) =>
            request('PUT', `/groupes/${identifiant}/marche/panier`, { achats, ventes }),

        /** POST /groupes/{id}/marche/confirmation — si tous confirmés → application + clôture. */
        confirmerPanier: (identifiant) =>
            request('POST', `/groupes/${identifiant}/marche/confirmation`),

        /** DELETE /groupes/{id}/marche — annule la phase (rien appliqué). */
        annulerMarche: (identifiant) => request('DELETE', `/groupes/${identifiant}/marche`),

        // ---- montée de niveau (contrat « Montée de niveau ») ----

        /** GET /api/competences → catalogue des arbres [{id, classe, nom, type, effet, prerequis_id}]. */
        getCompetences: () => request('GET', '/competences'),

        /**
         * POST /groupes/{id}/competences {personnage_id, competence_id,
         * element?} — acquiert un nœud d'arbre (422 : pas son héros, classe
         * différente, prérequis manquant, aucun point). `element` requis par
         * les nœuds Première magie / Second élément / École (défaut eau).
         */
        acquerirCompetence: (identifiant, payload) =>
            request('POST', `/groupes/${identifiant}/competences`, payload),

        // ---- votes de groupe (contrat « Votes de groupe ») ----

        /** POST /groupes/{id}/votes {type, question?, options?, cible_joueur_id?} → lance le vote. */
        lancerVote: (identifiant, payload) =>
            request('POST', `/groupes/${identifiant}/votes`, payload),

        /** POST /groupes/{id}/votes/bulletin {option_id} — bulletin du joueur. */
        voterBulletin: (identifiant, optionId) =>
            request('POST', `/groupes/${identifiant}/votes/bulletin`, { option_id: optionId }),

        /** GET /groupes/{id}/votes → vote actif ou null. */
        getVote: (identifiant) => request('GET', `/groupes/${identifiant}/votes`),

        /** POST /groupes/{id}/depart — départ hors quête (part du pot commun). */
        quitterGroupe: (identifiant) => request('POST', `/groupes/${identifiant}/depart`),

        // ---- clôture de campagne (contrat « Clôture de campagne ») ----

        /**
         * POST /groupes/{id}/cloture {abandon?: bool} — ouvre la fenêtre de
         * clôture (broadcast .cloture.ouverte). `abandon: true` après une
         * quête échouée (TPK doc 05 §6) ; 422 si une quête est en cours.
         */
        ouvrirCloture: (identifiant, { abandon = false } = {}) =>
            request('POST', `/groupes/${identifiant}/cloture`, abandon ? { abandon: true } : {}),

        /** GET /groupes/{id}/cloture → EtatCloture. */
        getCloture: (identifiant) => request('GET', `/groupes/${identifiant}/cloture`),

        /**
         * PUT /groupes/{id}/cloture/repartition {inventaire_id, personnage_id}
         * — réassigne un équipement (annule les confirmations,
         * broadcast .cloture.maj).
         */
        reassignerEquipement: (identifiant, { inventaire_id, personnage_id }) =>
            request('PUT', `/groupes/${identifiant}/cloture/repartition`, { inventaire_id, personnage_id }),

        /** POST /groupes/{id}/cloture/confirmation — confirme ; tous
         *  confirmés → finalisation (job) puis .cloture.terminee. */
        confirmerCloture: (identifiant) =>
            request('POST', `/groupes/${identifiant}/cloture/confirmation`),

        /** DELETE /groupes/{id}/cloture — annule la fenêtre (rien appliqué). */
        annulerCloture: (identifiant) => request('DELETE', `/groupes/${identifiant}/cloture`),

        // ---- snapshots & reprise (contrat « Snapshots & reprise », TPK doc 05 §6) ----

        /** GET /groupes/{id}/snapshots → [{id, etiquette, sequence_evenement, created_at}]. */
        getSnapshots: (identifiant) => request('GET', `/groupes/${identifiant}/snapshots`),

        /**
         * POST /groupes/{id}/reprise {snapshot_id?} — restaure l'état depuis
         * un snapshot (défaut serveur : `debut_quete` de la dernière quête
         * échouée — le « recharger » après TPK). 422 si une quête est en
         * cours ET non échouée. L'état restauré revient par .groupe.etat
         * (la quête repasse en_cours), puis narration/menus sont redispatchés.
         */
        reprendrePartie: (identifiant, { snapshot_id } = {}) =>
            request('POST', `/groupes/${identifiant}/reprise`,
                snapshot_id != null ? { snapshot_id } : {}),
    };
}
