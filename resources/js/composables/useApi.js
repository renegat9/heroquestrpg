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

        /** POST /api/connexion {identifiant, mot_de_passe} → {joueur}. */
        connexion: (identifiant, motDePasse) =>
            request('POST', '/connexion', { identifiant, mot_de_passe: motDePasse }),

        /** POST /api/deconnexion → 204. */
        deconnexion: () => request('POST', '/deconnexion'),

        /** GET /api/moi → {joueur, personnages: [...]}. */
        moi: () => request('GET', '/moi'),

        // ---- groupes / campagne ----

        /** POST /api/groupes {nom, theme, longueur, ton} → {groupe}. */
        creerGroupe: (payload) => request('POST', '/groupes', payload),

        /** POST /api/groupes/{identifiant}/joueurs {personnage_id} ou {nom, classe} → {personnage}. */
        rejoindreGroupe: (identifiant, payload) =>
            request('POST', `/groupes/${identifiant}/joueurs`, payload),

        /** GET /api/groupes/{identifiant}/etat → EtatGroupe. */
        getEtat: (identifiant) => request('GET', `/groupes/${identifiant}/etat`),

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
    };
}
