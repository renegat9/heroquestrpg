/* =========================================================================
   Store réactif partagé entre les écrans.

   Réceptacle unique de l'API (useApi) et du temps réel (useEcho) :
   - `joueur` / `personnages` : session courante (GET /api/moi) ;
   - `etat`   : dernier EtatGroupe reçu (GET etat ou broadcast .groupe.etat) ;
   - `menu`   : dernier menu personnel reçu (.menu.propose), consommé par
     la manette ; `menuEnAttente` = choix parti, on attend .groupe.etat ;
   - `modeDemo` : l'API est injoignable (réseau) ou refuse la session
     (401) → les vues retombent sur resources/js/data/demo.js et un badge
     « démo » discret s'affiche (components/ui/DemoBadge.vue).

   Les fonctions de mapping EtatGroupe → formats des composants existants
   (DungeonMap, GroupPanel, InitiativeBar, InitMini) vivent ici : on
   adapte la donnée, pas les composants.
   ========================================================================= */

import { reactive, readonly } from 'vue';

const state = reactive({
    /** Identifiant du groupe courant (param de route). */
    groupe: null,
    /** Joueur connecté ({id, pseudo}) + ses personnages (GET /api/moi). */
    joueur: null,
    personnages: [],
    /** Dernier EtatGroupe (contrat : groupe, quete, carte, entites, initiative…). */
    etat: null,
    /** Dernier menu personnel ({contexte, options}) reçu sur joueur.{id}. */
    menu: null,
    /** Choix envoyé, en attente du prochain .groupe.etat (boutons désactivés). */
    menuEnAttente: false,
    /** Texte de narration courant du MJ. */
    narration: "Une lueur d'ambre danse sur les murs suintants. Trois ombres trapues se redressent en grognant…",
    /** « Le MJ réfléchit… » (job IA en cours, indicateur non bloquant). */
    mjReflechit: false,
    /** État de connexion temps réel : 'ok' | 'warn'. */
    connexion: 'ok',
    /** Repli sur les données de démo (API absente ou session refusée). */
    modeDemo: false,

    /** EtatMarche courant (contrat : profil, multiplicateur, inventaire,
     *  paniers étiquetés, total_projete, or_courant) — null hors phase. */
    marche: null,
    /** Résultat de la dernière finalisation ({applique}) — informatif. */
    marcheFinalise: null,
    /** Vote actif (contrat : type, question?, options?, cible_joueur_id?). */
    vote: null,
    /** Décompte en direct (.vote.maj : {decompte, exprimes, attendus}). */
    voteDecompte: null,
    /** Résultat (.vote.resultat : {option_id, applique}) — la feuille reste
     *  affichée jusqu'à fermeture manuelle. */
    voteResultat: null,
});

export function useGameStore() {
    return {
        state: readonly(state),

        setGroupe(groupe) {
            state.groupe = groupe;
        },
        setJoueur(joueur, personnages = []) {
            state.joueur = joueur;
            state.personnages = personnages;
        },
        /** Applique un EtatGroupe complet (GET etat ou .groupe.etat). */
        appliquerEtat(etat) {
            state.etat = etat;
            if (typeof etat?.narration === 'string' && etat.narration) state.narration = etat.narration;
            if (typeof etat?.mj_reflechit === 'boolean') state.mjReflechit = etat.mj_reflechit;
            state.menuEnAttente = false; // le moteur a résolu : on rend la main
        },
        /** Nouveau menu personnel (.menu.propose sur joueur.{id}). */
        setMenu(menu) {
            state.menu = menu;
            state.menuEnAttente = false;
        },
        /** Le choix est parti (202) : boutons gelés jusqu'au prochain état. */
        choixEnvoye() {
            state.menuEnAttente = true;
        },
        annulerChoixEnAttente() {
            state.menuEnAttente = false;
        },
        setNarration(texte) {
            state.narration = texte;
        },
        setMjReflechit(actif) {
            state.mjReflechit = actif;
        },
        setConnexion(statut) {
            state.connexion = statut;
        },
        activerModeDemo(raison = '') {
            if (!state.modeDemo) {
                console.info(`[store] mode démo activé${raison ? ` — ${raison}` : ''}.`);
            }
            state.modeDemo = true;
        },

        // ---- phase marché ----

        /** EtatMarche (GET marche, .marche.ouvert, .marche.maj). Les
         *  broadcasts portent l'EtatMarche directement ; on tolère un
         *  éventuel enrobage {marche: …}. */
        appliquerMarche(etatMarche) {
            const m = etatMarche?.inventaire || etatMarche?.paniers ? etatMarche : etatMarche?.marche;
            if (m) {
                state.marche = m;
                state.marcheFinalise = null;
            }
        },
        /** .marche.finalise ({applique}) ou annulation : la phase se ferme. */
        fermerMarche(applique = null) {
            state.marche = null;
            state.marcheFinalise = applique === null ? null : { applique };
        },

        // ---- votes de groupe ----

        /** Vote actif (.vote.lance {vote} ou GET votes). */
        appliquerVote(vote) {
            state.vote = vote ?? null;
            state.voteDecompte = null;
            state.voteResultat = null;
        },
        /** .vote.maj ({decompte, exprimes, attendus}). */
        setVoteDecompte(payload) {
            state.voteDecompte = payload ?? null;
        },
        /** .vote.resultat ({option_id, applique}) — le vote reste affiché. */
        setVoteResultat(payload) {
            state.voteResultat = payload ?? null;
        },
        /** Fermeture de la feuille (après lecture du résultat). */
        fermerVote() {
            state.vote = null;
            state.voteDecompte = null;
            state.voteResultat = null;
        },
    };
}

/* =========================================================================
   Mapping EtatGroupe → formats attendus par les composants existants
   ========================================================================= */

/** Codes de case du contrat → classes CSS de DungeonMap. */
const TUILES = { m: 'wall', s: 'floor', p: 'door', b: 'fog' };

/** Habillage par classe de héros (icônes Material Symbols des maquettes). */
export const CLASSES = {
    barbare: { l: 'Barbare', ic: 'sports_martial_arts', demo: 'barb' },
    magicien: { l: 'Magicien', ic: 'auto_fix_high', demo: 'mage' },
    magicienne: { l: 'Magicienne', ic: 'auto_fix_high', demo: 'mage' },
    nain: { l: 'Nain', ic: 'construction', demo: 'dwarf' },
    elfe: { l: 'Elfe', ic: 'park', demo: 'elf' },
};

function classeDe(entite) {
    return CLASSES[(entite.classe ?? '').toLowerCase()] ?? null;
}

/** « Durik Forgefer » → « DUR » (jetons d'initiative). */
export function labelCourt(nom) {
    return (nom ?? '?').replace(/[^A-Za-zÀ-ÿ0-9]/g, '').toUpperCase().slice(0, 3) || '?';
}

/** Acteur courant = première entrée d'initiative qui n'a pas encore joué. */
export function acteurCourant(initiative) {
    return (initiative ?? []).find((o) => !o.a_joue) ?? null;
}

function estCourant(entite, initiative) {
    const cur = acteurCourant(initiative);
    if (!cur) return false;
    const type = entite.type === 'heros' ? 'heros' : 'monstre';
    return cur.entite === type && cur.id === entite.id;
}

/** carte (contrat) → { C, R, cells } pour DungeonMap. */
export function carteVersMap(carte) {
    if (!carte) return null;
    const C = carte.largeur;
    const R = carte.hauteur;
    const cells = [];
    for (let y = 0; y < R; y++) {
        for (let x = 0; x < C; x++) {
            cells.push({ x, y, t: TUILES[carte.cases?.[y]?.[x]] ?? 'void', range: false });
        }
    }
    return { C, R, cells };
}

/** entites (contrat) → figurines [{x, y, k, l, ic, hp?, cur?}] pour DungeonMap. */
export function entitesVersFigurines(entites, initiative) {
    return (entites ?? [])
        .filter((e) => e.type !== 'monstre' || ((e.etat ?? 'actif') === 'actif' && e.pv_body > 0))
        .map((e) => ({
            x: e.x,
            y: e.y,
            k: e.type === 'heros' ? 'hero' : 'foe',
            l: labelCourt(e.nom),
            ic: e.type === 'heros' ? classeDe(e)?.ic : 'sentiment_very_dissatisfied',
            hp: e.type === 'monstre' ? e.pv_body : undefined,
            cur: estCourant(e, initiative),
        }));
}

/** entites héros (contrat) → cartes du GroupPanel [{l, c, ic, body, mind, conds…}]. */
export function entitesVersGroupe(entites, initiative) {
    return (entites ?? [])
        .filter((e) => e.type === 'heros')
        .map((e) => ({
            l: e.nom,
            c: classeDe(e)?.l ?? e.classe,
            ic: classeDe(e)?.ic ?? 'person',
            body: [e.tombe ? 0 : e.pv_body, e.pv_body_max],
            mind: [e.pv_mind, e.pv_mind_max],
            conds: [], // états/conditions : pas encore dans le contrat
            acting: estCourant(e, initiative),
            low: !e.tombe && e.pv_body > 0 && e.pv_body * 4 <= e.pv_body_max,
        }));
}

/** initiative (contrat) → InitiativeBar [{l, cur?, foe?}]. */
export function initiativeVersBarre(initiative) {
    const cur = acteurCourant(initiative);
    return (initiative ?? []).map((o) => ({
        l: labelCourt(o.nom),
        cur: o === cur,
        foe: o.entite === 'monstre',
    }));
}

/** initiative (contrat) → InitMini de la manette [{k, foe}] (+ jeton courant). */
export function initiativeVersMini(initiative) {
    return (initiative ?? []).map((o) => ({ k: labelCourt(o.nom), foe: o.entite === 'monstre' }));
}

/* =========================================================================
   Mapping EtatMarche (contrat « Phase marché ») → MarketTab / écran de table
   ========================================================================= */

/** rarete du catalogue (commun, peu_commun…) → clés CSS rar-* des maquettes. */
const RARETES = {
    commun: 'common', peu_commun: 'uncommon', rare: 'rare', unique: 'unique',
    common: 'common', uncommon: 'uncommon',
};
export const RARETE_LABELS = {
    common: 'Commun', uncommon: 'Peu commun', rare: 'Rare', unique: 'Unique',
};
/** categorie du catalogue → icône Material Symbols (maquettes). */
const CATEGORIE_ICONES = {
    arme: 'swords', armure: 'shield', outil: 'build',
    consommable: 'science', parchemin: 'description',
};

export function rareteVersCle(rarete) {
    return RARETES[(rarete ?? '').toLowerCase()] ?? 'common';
}

export const PROFILS_MARCHE = {
    village: 'Village', bourg: 'Bourg', cite: 'Cité',
    comptoir: 'Comptoir', marche_noir: 'Marché noir',
};

/** inventaire marchand (EtatMarche) → lignes de l'échoppe de MarketTab. */
export function marcheVersEchoppe(marche) {
    return (marche?.inventaire ?? []).map((it) => ({
        id: it.objet_id,
        name: it.nom,
        rar: rareteVersCle(it.rarete),
        rarLabel: RARETE_LABELS[rareteVersCle(it.rarete)],
        icon: CATEGORIE_ICONES[(it.categorie ?? '').toLowerCase()] ?? 'category',
        price: it.prix ?? 0,
        stock: it.stock ?? 0,
    }));
}

/** Panier d'un joueur dans EtatMarche.paniers (ou null). */
export function panierDuJoueur(marche, joueurId) {
    return (marche?.paniers ?? []).find((p) => p.joueur_id === joueurId) ?? null;
}

/**
 * Inventaire vendable du personnage du joueur. Le contrat ne fixe pas où il
 * voyage : on cherche, dans l'ordre, le panier du joueur (EtatMarche), son
 * entité d'EtatGroupe, puis ses personnages (GET /moi). Chaque entrée est
 * normalisée vers {inventaire_id, name, rar, rarLabel, icon, quantite,
 * revente} — revente = 50 % du prix marchand courant (M1), à défaut 50 % du
 * prix de base, à défaut le champ fourni par le serveur.
 */
export function inventaireVendable(marche, joueurId, etat, personnages) {
    const monPanier = panierDuJoueur(marche, joueurId);
    const ids = new Set((personnages ?? []).map((p) => p.id));
    const monEntite = (etat?.entites ?? []).find((e) => e.type === 'heros' && ids.has(e.id));
    const monPerso = (personnages ?? []).find((p) => p.groupe_actif_id != null && ids.has(p.id));

    const brut = monPanier?.inventaire
        ?? monPanier?.inventaire_personnage
        ?? monEntite?.inventaire
        ?? monPerso?.inventaire
        ?? [];

    const marchand = new Map((marche?.inventaire ?? []).map((it) => [it.objet_id, it]));

    return brut.map((it) => {
        const objetId = it.objet_id ?? it.objet?.id;
        const courant = objetId != null ? marchand.get(objetId) : null;
        const revente = courant?.prix != null
            ? Math.floor(courant.prix / 2)
            : (it.revente
                ?? it.prix_revente
                ?? Math.floor((it.prix ?? it.prix_base ?? it.objet?.prix_base ?? 0) / 2));
        const rar = rareteVersCle(it.rarete ?? it.objet?.rarete);
        return {
            inventaire_id: it.inventaire_id ?? it.id,
            name: it.nom ?? it.objet?.nom ?? `Objet n°${objetId ?? '?'}`,
            rar,
            rarLabel: RARETE_LABELS[rar],
            icon: CATEGORIE_ICONES[(it.categorie ?? it.objet?.categorie ?? '').toLowerCase()] ?? 'category',
            quantite: it.quantite ?? 1,
            revente,
        };
    });
}

/** Montant net d'un panier {achats, ventes} : ventes − achats (or). */
export function montantPanier(panier, marche, inventaire = []) {
    const marchand = new Map((marche?.inventaire ?? []).map((it) => [it.objet_id, it]));
    const vendable = new Map((inventaire ?? []).map((it) => [it.inventaire_id, it]));

    const achats = (panier?.achats ?? []).reduce((s, a) => {
        const prix = a.prix ?? marchand.get(a.objet_id)?.prix ?? 0;
        return s + prix * (a.quantite ?? 1);
    }, 0);
    const ventes = (panier?.ventes ?? []).reduce((s, v) => {
        const id = typeof v === 'object' ? v.inventaire_id : v;
        const revente = (typeof v === 'object' ? (v.revente ?? v.prix) : null)
            ?? vendable.get(id)?.revente ?? 0;
        return s + revente;
    }, 0);

    return { achats, ventes, net: ventes - achats };
}

/**
 * Panier consolidé pour l'écran de table : une carte par joueur,
 * lignes achats/ventes étiquetées, montants résolus via l'inventaire
 * marchand (les ventes affichent ce que le serveur fournit).
 */
export function marcheVersConsolide(marche) {
    const marchand = new Map((marche?.inventaire ?? []).map((it) => [it.objet_id, it]));

    return (marche?.paniers ?? []).map((p) => {
        const lignes = [];
        for (const a of p.achats ?? []) {
            const it = marchand.get(a.objet_id);
            const prix = a.prix ?? it?.prix;
            lignes.push({
                type: 'achat',
                nom: a.nom ?? it?.nom ?? `Objet n°${a.objet_id}`,
                quantite: a.quantite ?? 1,
                montant: prix != null ? -prix * (a.quantite ?? 1) : null,
            });
        }
        for (const v of p.ventes ?? []) {
            const id = typeof v === 'object' ? v.inventaire_id : v;
            const revente = typeof v === 'object' ? (v.revente ?? v.prix ?? null) : null;
            lignes.push({
                type: 'vente',
                nom: (typeof v === 'object' && v.nom) || `Objet vendu n°${id}`,
                quantite: 1,
                montant: revente,
            });
        }
        return {
            joueur_id: p.joueur_id,
            pseudo: p.pseudo,
            confirme: !!p.confirme,
            lignes,
        };
    });
}

/* =========================================================================
   Mapping vote (contrat « Votes de groupe ») → VoteSheet de la manette
   ========================================================================= */

/** Options par défaut du retrait (le contrat laisse le serveur libre). */
const OPTIONS_RETRAIT = [
    { id: 'oui', libelle: 'Retirer le joueur' },
    { id: 'non', libelle: 'Il reste' },
];

/** Décompte (.vote.maj) normalisé en Map option_id → voix (objet ou liste). */
function decompteVersMap(decompte) {
    const map = new Map();
    const d = decompte?.decompte ?? decompte;
    if (Array.isArray(d)) {
        for (const e of d) map.set(e.option_id ?? e.id, e.voix ?? e.nombre ?? e.total ?? 0);
    } else if (d && typeof d === 'object') {
        for (const [k, v] of Object.entries(d)) map.set(k, Number(v) || 0);
    }
    return map;
}

/**
 * vote actif + décompte + résultat → format VoteSheet
 * { q, opts: [{k, l, c}], mine, missing, spectateur, done, closeLabel }.
 * `spectateur` = le joueur est la cible d'un retrait_joueur (il ne vote
 * pas : feuille en lecture seule « le groupe délibère »).
 */
export function voteVersFeuille(vote, decompte, resultat, monBulletin, joueurId, etat) {
    if (!vote) return null;

    const options = vote.options?.length
        ? vote.options
        : (vote.type === 'retrait_joueur' ? OPTIONS_RETRAIT : []);
    const voix = decompteVersMap(decompte);

    const spectateur = vote.type === 'retrait_joueur'
        && vote.cible_joueur_id != null
        && vote.cible_joueur_id === joueurId;

    const exprimes = decompte?.exprimes ?? (monBulletin != null ? 1 : 0);
    const heros = (etat?.entites ?? []).filter((e) => e.type === 'heros').length;
    const attendus = decompte?.attendus
        ?? Math.max(1, heros - (vote.type === 'retrait_joueur' ? 1 : 0));

    const q = vote.question
        ?? (vote.type === 'retrait_joueur'
            ? `Retirer ${vote.cible_pseudo ?? 'ce joueur'} du groupe ?`
            : 'Vote de groupe');

    return {
        q,
        opts: options.map((o) => ({
            k: o.id,
            l: o.libelle ?? o.id,
            c: voix.get(o.id) ?? voix.get(String(o.id)) ?? 0,
            gagnant: resultat ? resultat.option_id === o.id : false,
        })),
        mine: monBulletin ?? null,
        missing: resultat ? 0 : Math.max(0, attendus - exprimes),
        spectateur,
        done: !!resultat,
        closeLabel: resultat
            ? (resultat.applique ? 'Décision appliquée — Fermer' : 'Décision prise — Fermer')
            : null,
    };
}
