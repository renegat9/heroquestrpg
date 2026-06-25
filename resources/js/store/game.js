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

    /** Dernier .niveau.monte ({personnages: [{id, nom, niveau,
     *  points_competence, gains}]}) — null tant qu'aucun jalon franchi. */
    niveauMonte: null,
    /** Catalogue des arbres de compétences (GET /api/competences). */
    competences: null,

    /** EtatCloture brut (contrat « Clôture de campagne » : issue,
     *  or_a_partager, parts, equipements, confirmations) — null hors
     *  fenêtre de clôture. */
    cloture: null,
    /** Payload .cloture.terminee ({resumes: [{personnage_id, resume}]}) —
     *  le groupe n'existe plus, les clients retournent à l'accueil. */
    clotureTerminee: null,

    /** Statuts « prêt » du hub (contrat : [{personnage_id, pret}]).
     *  Peuplé par EtatGroupe.groupe.prets et le broadcast .prets.maj. */
    prets: [],
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
            // Un vrai EtatGroupe est arrivé → on n'est PAS (ou plus) en démo.
            // Évite l'écran « collé » sur les données de test après un échec
            // transitoire de la 1re requête (le temps réel reprend la main).
            state.modeDemo = false;
            state.etat = etat;
            if (typeof etat?.narration === 'string' && etat.narration) state.narration = etat.narration;
            if (typeof etat?.mj_reflechit === 'boolean') state.mjReflechit = etat.mj_reflechit;
            // prets du hub (contrat : EtatGroupe.groupe.prets au hub)
            if (Array.isArray(etat?.groupe?.prets)) state.prets = etat.groupe.prets;
            state.menuEnAttente = false; // le moteur a résolu : on rend la main
        },
        /** Nouveau menu personnel (.menu.propose sur joueur.{id}). */
        setMenu(menu) {
            state.menu = menu;
            state.menuEnAttente = false;
        },
        /** Retire le menu courant (ex. mon tour est fini : il devient périmé). */
        viderMenu() {
            state.menu = null;
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

        // ---- montée de niveau ----

        /** .niveau.monte ({personnages: [...]}) — émis à la clôture
         *  victorieuse d'une quête à jalon, avant .groupe.etat. */
        setNiveauMonte(payload) {
            state.niveauMonte = payload ?? null;
        },
        /** Fermeture du bandeau/celebration (table et manette). */
        fermerNiveauMonte() {
            state.niveauMonte = null;
        },
        /** Catalogue GET /api/competences (toléré : enrobage {competences}). */
        setCompetences(catalogue) {
            state.competences = catalogue?.competences ?? catalogue ?? null;
        },

        // ---- clôture de campagne ----

        /** EtatCloture (GET cloture, .cloture.ouverte, .cloture.maj). Les
         *  broadcasts portent l'EtatCloture directement ; on tolère un
         *  éventuel enrobage {cloture: …}. */
        appliquerCloture(etatCloture) {
            const c = etatCloture?.issue || etatCloture?.confirmations
                ? etatCloture
                : etatCloture?.cloture;
            if (c) state.cloture = c;
        },
        /** Annulation (DELETE cloture) : la fenêtre se referme, rien appliqué. */
        fermerCloture() {
            state.cloture = null;
        },
        /** .cloture.terminee ({resumes}) — finalisation appliquée, groupe purgé. */
        setClotureTerminee(payload) {
            state.clotureTerminee = payload ?? { resumes: [] };
        },
        /** .prets.maj ({prets: [{personnage_id, pret}]}) — broadcast hub. */
        appliquerPrets(payload) {
            state.prets = Array.isArray(payload?.prets) ? payload.prets : (payload ?? []);
        },

        /** Le groupe n'existe plus (clôture terminée) : purge de tout
         *  l'état rattaché avant le retour à l'accueil. */
        purgerGroupe() {
            state.groupe = null;
            state.etat = null;
            state.menu = null;
            state.menuEnAttente = false;
            state.mjReflechit = false;
            state.marche = null;
            state.marcheFinalise = null;
            state.vote = null;
            state.voteDecompte = null;
            state.voteResultat = null;
            state.niveauMonte = null;
            state.cloture = null;
            state.clotureTerminee = null;
            state.prets = [];
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

/* =========================================================================
   Conditions (contrat « Sorts de Dread & capacités des boss ») :
   entites.conditions: [{nom, duree}] → badges (GroupPanel, FicheTab) et
   pictogramme d'état sur les jetons (DungeonMap).
   ========================================================================= */

/** Habillage par nom de condition du contrat (docs 02/09) : clé CSS des
 *  maquettes (var(--cond-*) / .b-*), libellé court et icône Material
 *  Symbols. Une condition inconnue reste affichée (habillage neutre). */
export const CONDITIONS = {
    endormi: { t: 'sleep', l: 'Endormi', ic: 'bedtime' },
    frayeur: { t: 'fear', l: 'Frayeur', ic: 'mood_bad' },
    commande: { t: 'fear', l: 'Commandé', ic: 'cyclone' },
    tempete: { t: 'fear', l: 'Tempête', ic: 'thunderstorm' },
    poison: { t: 'poison', l: 'Poison', ic: 'coronavirus' },
    brulure: { t: 'burn', l: 'Brûlure', ic: 'local_fire_department' },
    saignement: { t: 'bleed', l: 'Saignement', ic: 'bloodtype' },
    courage: { t: 'buff', l: 'Courage', ic: 'swords' },
    peau_de_pierre: { t: 'buff', l: 'Peau de pierre', ic: 'shield' },
    voile_de_brume: { t: 'buff', l: 'Voile de brume', ic: 'foggy' },
    vent_veloce: { t: 'buff', l: 'Vent véloce', ic: 'sprint' },
    renforce: { t: 'buff', l: 'Renforcé', ic: 'shield_with_heart' },
    regeneration: { t: 'buff', l: 'Régénération', ic: 'ecg_heart' },
};

/** nom de condition (contrat) → habillage {t, l, ic} (repli neutre). */
export function conditionInfo(nom) {
    const cle = (nom ?? '').toLowerCase();
    return CONDITIONS[cle] ?? {
        t: '',
        l: cle ? cle.charAt(0).toUpperCase() + cle.slice(1).replaceAll('_', ' ') : 'Condition',
        ic: 'emergency_heat',
    };
}

/** conditions ([{nom, duree}] — on tolère une liste de noms) → badges
 *  [{nom, t, l, ic, d}] pour GroupPanel et FicheTab (d = durée en tours,
 *  null si le serveur ne la fournit pas). */
export function conditionsVersBadges(conditions) {
    return (conditions ?? []).map((c) => {
        const objet = c !== null && typeof c === 'object';
        const nom = objet ? c.nom : c;
        const info = conditionInfo(nom);
        return { nom, t: info.t, l: info.l, ic: info.ic, d: objet ? (c.duree ?? null) : null };
    });
}

/** Conditions de contrôle (doc 09 §4) : le héros ne joue pas (endormi)
 *  ou est joué par le moteur (commande). */
const CONDITIONS_CONTROLE = ['endormi', 'commande'];

/** Nom de la condition de contrôle active d'une entité, ou null. */
export function conditionControle(entite) {
    return (entite?.conditions ?? [])
        .map((c) => String((c !== null && typeof c === 'object' ? c.nom : c) ?? '').toLowerCase())
        .find((n) => CONDITIONS_CONTROLE.includes(n)) ?? null;
}

/** conditions → pictogramme d'état du jeton ({t, ic, titre}) ou undefined.
 *  Une seule pastille par figurine : la condition de contrôle (endormi /
 *  commandé) prime, sinon la première condition reçue. */
export function conditionDeJeton(conditions) {
    const badges = conditionsVersBadges(conditions);
    if (!badges.length) return undefined;
    const b = badges.find((x) => CONDITIONS_CONTROLE.includes(String(x.nom ?? '').toLowerCase()))
        ?? badges[0];
    return {
        t: b.t,
        ic: b.ic,
        titre: b.d != null ? `${b.l} — ${b.d} tour${b.d > 1 ? 's' : ''}` : b.l,
    };
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

/** entites (contrat) → figurines [{x, y, k, l, ic, hp?, cur?, cond?}]
 *  pour DungeonMap (cond = pictogramme d'état discret sur le jeton). */
export function entitesVersFigurines(entites, initiative) {
    return (entites ?? [])
        .filter((e) => e.type !== 'monstre' || ((e.etat ?? 'actif') === 'actif' && e.pv_body > 0))
        .map((e) => ({
            x: e.x,
            y: e.y,
            k: e.type === 'heros' ? 'hero' : 'foe',
            l: labelCourt(e.nom),
            ic: e.type === 'heros' ? classeDe(e)?.ic : 'sentiment_very_dissatisfied',
            img: e.image_url ?? null,
            hp: e.type === 'monstre' ? e.pv_body : undefined,
            cur: estCourant(e, initiative),
            cond: conditionDeJeton(e.conditions),
        }));
}

/** Libellés des états de piège visibles (les cachés n'arrivent jamais). */
export const PIEGE_ETATS = {
    detecte: 'détecté',
    desarme: 'désarmé',
    declenche: 'déclenché',
};

/** carte.pieges (contrat « Pièges ») → marqueurs [{x, y, etat, nom, titre}]
 *  pour la couche pièges de DungeonMap. Seuls les états du contrat sont
 *  rendus — un état inconnu est ignoré plutôt que mal affiché. */
export function piegesVersMarqueurs(carte) {
    return (carte?.pieges ?? [])
        .filter((p) => PIEGE_ETATS[p.etat])
        .map((p) => ({
            x: p.x,
            y: p.y,
            etat: p.etat,
            nom: p.nom ?? 'Piège',
            titre: `${p.nom ?? 'Piège'} — ${PIEGE_ETATS[p.etat]}`,
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
            img: e.image_url ?? null,
            body: [e.tombe ? 0 : e.pv_body, e.pv_body_max],
            mind: [e.pv_mind, e.pv_mind_max],
            conds: conditionsVersBadges(e.conditions),
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
   Mapping sorts (contrat « Sorts des héros ») → SpellsTab / ActionTab
   ========================================================================= */

/** Les 4 éléments du contrat (doc 02) : libellé, clé CSS des maquettes
 *  (var(--elem-*) / .el-*) et icône Material Symbols. */
export const ELEMENTS = {
    feu: { l: 'Feu', cle: 'fire', ic: 'local_fire_department' },
    eau: { l: 'Eau', cle: 'water', ic: 'water_drop' },
    terre: { l: 'Terre', cle: 'earth', ic: 'landscape' },
    air: { l: 'Air', cle: 'air', ic: 'air' },
};

/** element (contrat, FR) → entrée ELEMENTS ; tolère les clés EN des démos. */
export function elementInfo(element) {
    const e = (element ?? '').toLowerCase();
    const alias = { fire: 'feu', water: 'eau', earth: 'terre', wind: 'air' };
    return ELEMENTS[e] ?? ELEMENTS[alias[e]] ?? null;
}

/** Types de sort du contrat → badge + icône. */
export const TYPES_SORT = {
    degats: { l: 'Dégâts', ic: 'swords' },
    mental: { l: 'Mental', ic: 'psychology' },
    utilitaire: { l: 'Utilitaire', ic: 'auto_fix_high' },
};

/** sorts de /moi ([{sort_id, nom, element, type, disponible}]) → groupes
 *  par élément dans l'ordre canonique [{element, l, cle, ic, sorts}]. */
export function sortsParElement(sorts) {
    const groupes = new Map();
    for (const s of sorts ?? []) {
        const info = elementInfo(s.element);
        const cle = info ? Object.keys(ELEMENTS).find((k) => ELEMENTS[k] === info) : (s.element ?? 'autre');
        if (!groupes.has(cle)) {
            groupes.set(cle, {
                element: cle,
                l: info?.l ?? (s.element ?? 'Autre'),
                cle: info?.cle ?? '',
                ic: info?.ic ?? 'auto_awesome',
                sorts: [],
            });
        }
        groupes.get(cle).sorts.push(s);
    }
    const ordre = Object.keys(ELEMENTS);
    return [...groupes.values()].sort((a, b) => {
        const ia = ordre.indexOf(a.element);
        const ib = ordre.indexOf(b.element);
        return (ia === -1 ? ordre.length : ia) - (ib === -1 ? ordre.length : ib);
    });
}

/** L'option de menu type "sort" qui lance ce sort_id, ou null (pas son
 *  tour / sort non proposé) — c'est elle que la manette envoie au moteur. */
export function optionPourSort(menu, sortId) {
    return (menu?.options ?? []).find((o) => o.type === 'sort'
        && String(o.parametres?.sort_id ?? o.sort_id ?? '') === String(sortId)) ?? null;
}

/** Sorts épuisés (disponible === false) — candidats à la Concentration. */
export function sortsEpuises(sorts) {
    return (sorts ?? []).filter((s) => s.disponible === false);
}

/**
 * parametres.cibles d'une option (sort/parchemin/attaque ciblés) → liste
 * affichable pour la feuille de ciblage. Le contrat ne fige pas la forme
 * d'une cible : on tolère un id scalaire ou un objet {type|entite,
 * id|cible_id, nom?} et on complète depuis EtatGroupe.entites. `brut` est
 * l'entrée telle que reçue — c'est elle qu'on renvoie au moteur
 * (parametres.cible), pour matcher sa propre liste. Les héros sont des
 * cibles légales (tir ami S3) → `ami` pilote la confirmation « ⚠ allié ».
 */
export function ciblesVersListe(cibles, entites = []) {
    const parCle = new Map((entites ?? [])
        .map((e) => [`${e.type === 'heros' ? 'heros' : 'monstre'}:${e.id}`, e]));

    return (cibles ?? []).map((c, i) => {
        const objet = c !== null && typeof c === 'object';
        const id = objet ? (c.id ?? c.cible_id ?? c.entite_id ?? null) : c;
        let type = objet ? String(c.type ?? c.entite ?? '').toLowerCase() : '';
        const entite = type
            ? parCle.get(`${type}:${id}`)
            : (entites ?? []).find((e) => e.type === 'monstre' && e.id === id)
                ?? (entites ?? []).find((e) => e.id === id);
        if (!type) type = entite?.type === 'heros' ? 'heros' : 'monstre';
        const ami = type === 'heros';
        const nom = (objet && c.nom) || entite?.nom || `${ami ? 'Héros' : 'Cible'} n°${id ?? i + 1}`;
        const pv = entite && entite.pv_body != null ? `PV ${entite.pv_body}/${entite.pv_body_max}` : '';
        return {
            brut: c,
            cle: `${type}:${id ?? i}`,
            nom,
            ami,
            ic: ami
                ? (CLASSES[((objet ? c.classe : null) ?? entite?.classe ?? '').toLowerCase()]?.ic ?? 'person')
                : 'sentiment_very_dissatisfied',
            meta: ami ? `⚠ allié${pv ? ` · ${pv}` : ''}` : pv,
        };
    });
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
        img: it.image_url ?? null,
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

/* =========================================================================
   Mapping montée de niveau (contrat « Montée de niveau ») →
   bandeau de table, manette et MonteeNiveauView
   ========================================================================= */

/** Habillage par type de nœud (passif appliqué par le moteur, actif et
 *  déblocage seulement enregistrés — résolution ultérieure). */
export const TYPES_COMPETENCE = {
    passif: { l: 'Passif', ic: 'trending_up' },
    actif: { l: 'Actif', ic: 'bolt' },
    deblocage: { l: 'Déblocage', ic: 'key' },
};

/** Effets passifs chiffrés du contrat (effet JSON : clé → +n). */
const EFFETS_PASSIFS = {
    attribut_body: { l: 'Body (attr.)', ic: 'fitness_center' },
    attribut_mind: { l: 'Mind (attr.)', ic: 'psychology' },
    des_attaque: { l: "dé d'attaque", ic: 'swords' },
    des_defense: { l: 'dé de défense', ic: 'shield' },
    pv_body_max: { l: 'PV Body max', ic: 'favorite' },
    pv_mind_max: { l: 'PV Mind max', ic: 'psychology' },
    deplacement_base: { l: 'déplacement', ic: 'directions_walk' },
    bonus_sac: { l: 'capacité de sac', ic: 'backpack' },
};

/** effet du catalogue (JSON, chaîne JSON ou texte libre) → [{texte, ic}]. */
export function effetVersListe(effet) {
    let e = effet;
    if (typeof e === 'string') {
        try { e = JSON.parse(e); } catch { return e ? [{ texte: e, ic: null }] : []; }
    }
    if (e == null) return [];
    if (typeof e !== 'object') return [{ texte: String(e), ic: null }];
    const lignes = [];
    for (const [k, v] of Object.entries(e)) {
        const connu = EFFETS_PASSIFS[k];
        if (connu && typeof v === 'number') {
            lignes.push({ texte: `${v > 0 ? '+' : ''}${v} ${connu.l}`, ic: connu.ic });
        } else if (typeof v === 'string' && (k === 'description' || k === 'texte' || k === 'libelle')) {
            lignes.push({ texte: v, ic: null });
        }
    }
    return lignes;
}

/**
 * Catalogue (GET /api/competences) + personnage (/moi enrichi) → l'arbre
 * du héros, aplati en ordre parent → enfants avec profondeur.
 * États : `acquis` / `dispo` (prérequis OK + point disponible) /
 * `verrouille` (verrou = 'prerequis' ou 'points').
 */
export function competencesVersArbre(catalogue, classe, acquis = [], points = 0) {
    const cls = (classe ?? '').toLowerCase();
    const noeuds = (catalogue ?? []).filter((c) => (c.classe ?? '').toLowerCase() === cls);
    const parId = new Map(noeuds.map((n) => [n.id, n]));
    const enfants = new Map();
    const racines = [];
    for (const n of noeuds) {
        if (n.prerequis_id != null && parId.has(n.prerequis_id)) {
            if (!enfants.has(n.prerequis_id)) enfants.set(n.prerequis_id, []);
            enfants.get(n.prerequis_id).push(n);
        } else {
            racines.push(n);
        }
    }
    // ids acquis : tolère une liste d'ids ou d'objets {id}.
    const acquisSet = new Set((acquis ?? []).map((c) => (typeof c === 'object' ? c.id : c)));

    const liste = [];
    const visiter = (n, profondeur) => {
        const type = (n.type ?? 'passif').toLowerCase();
        const estAcquis = acquisSet.has(n.id);
        const prerequisOk = n.prerequis_id == null || acquisSet.has(n.prerequis_id);
        const effets = effetVersListe(n.effet);
        liste.push({
            id: n.id,
            nom: n.nom,
            type: TYPES_COMPETENCE[type] ? type : 'passif',
            profondeur,
            effets,
            ic: (type === 'passif' && effets.find((e) => e.ic)?.ic)
                || TYPES_COMPETENCE[type]?.ic || 'hub',
            etat: estAcquis ? 'acquis' : (prerequisOk && points > 0 ? 'dispo' : 'verrouille'),
            verrou: estAcquis || (prerequisOk && points > 0)
                ? null
                : (prerequisOk ? 'points' : 'prerequis'),
            prerequisNom: n.prerequis_id != null ? (parId.get(n.prerequis_id)?.nom ?? null) : null,
        });
        for (const e of enfants.get(n.id) ?? []) visiter(e, profondeur + 1);
    };
    for (const r of racines) visiter(r, 0);
    return liste;
}

/** Un gain du payload .niveau.monte → texte affichable (format serveur
 *  libre : chaîne, {libelle|texte|nom} ou effet passif {clé: +n}). */
function gainVersTexte(gain) {
    if (typeof gain === 'string') return gain;
    if (gain == null || typeof gain !== 'object') return null;
    if (gain.libelle || gain.texte || gain.nom) return gain.libelle ?? gain.texte ?? gain.nom;
    const lignes = effetVersListe(gain);
    return lignes.length ? lignes.map((l) => l.texte).join(' · ') : null;
}

/* =========================================================================
   Mapping EtatCloture (contrat « Clôture de campagne ») →
   ClotureCampagneView, bandeaux de table et toast de manette
   ========================================================================= */

/** Habillage par issue (doc 05 §6) : la victoire brille (braises dorées),
 *  l'échec et l'abandon retombent en cendres — fidèle à la maquette
 *  "Cloture de campagne.html" (ton `cendres` = variante grise). */
export const ISSUES_CLOTURE = {
    victoire: {
        crumb: 'Campagne achevée · Victoire',
        titre: 'La Lumière Revient',
        sous: 'Le boss final est tombé — la compagnie partage ses trophées.',
        ic: 'military_tech',
        ton: 'or',
    },
    echec: {
        crumb: 'Campagne perdue · Défaite',
        titre: 'Les Cendres Retombent',
        sous: 'La quête a échoué — la compagnie remonte, vaincue mais vivante.',
        ic: 'skull',
        ton: 'cendres',
    },
    abandon: {
        crumb: 'Campagne close · Abandon',
        titre: 'La Compagnie se Sépare',
        sous: 'Les héros rangent leurs armes — chacun reprend sa route.',
        ic: 'wb_twilight',
        ton: 'cendres',
    },
};

/** issue (contrat) → habillage ; issue inconnue = victoire (ton doré). */
export function issueCloture(issue) {
    return ISSUES_CLOTURE[(issue ?? '').toLowerCase()] ?? ISSUES_CLOTURE.victoire;
}

/** EtatCloture.parts → liste affichable [{personnage_id, joueur_id, nom,
 *  court, montant, ic}] — l'icône de classe vient des entités d'EtatGroupe
 *  quand elles sont connues (au hub la liste peut être vide). */
export function clotureVersParts(cloture, entites = []) {
    const classes = new Map((entites ?? [])
        .filter((e) => e.type === 'heros')
        .map((e) => [e.id, (e.classe ?? '').toLowerCase()]));
    return (cloture?.parts ?? []).map((p) => ({
        personnage_id: p.personnage_id,
        joueur_id: p.joueur_id,
        nom: p.nom,
        court: labelCourt(p.nom),
        montant: p.montant ?? 0,
        ic: CLASSES[classes.get(p.personnage_id) ?? '']?.ic ?? 'person',
    }));
}

/** EtatCloture.equipements → cartes du partage du butin [{inventaire_id,
 *  nom, rar, rarLabel, ic, personnage_id}] (rareté/catégorie : mêmes clés
 *  CSS et icônes que le marché). */
export function clotureVersEquipements(cloture) {
    return (cloture?.equipements ?? []).map((e) => {
        const rar = rareteVersCle(e.rarete);
        return {
            inventaire_id: e.inventaire_id,
            nom: e.nom,
            rar,
            rarLabel: RARETE_LABELS[rar],
            ic: CATEGORIE_ICONES[(e.categorie ?? '').toLowerCase()] ?? 'category',
            img: e.image_url ?? null,
            personnage_id: e.personnage_id ?? null,
        };
    });
}

/** EtatCloture.confirmations → {liste, confirmes, total, mienne} —
 *  alimente le « Confirmer (k/n) » par joueur. */
export function clotureVersConfirmations(cloture, joueurId = null) {
    const liste = (cloture?.confirmations ?? []).map((c) => ({
        joueur_id: c.joueur_id,
        pseudo: c.pseudo,
        confirme: !!c.confirme,
    }));
    return {
        liste,
        confirmes: liste.filter((c) => c.confirme).length,
        total: liste.length,
        mienne: joueurId != null
            ? (liste.find((c) => c.joueur_id === joueurId)?.confirme ?? false)
            : false,
    };
}

/** Payload .cloture.terminee ({resumes: [{personnage_id, resume}]}) →
 *  le résumé du héros du joueur connecté (ses personnages /moi), ou null. */
export function resumeDuJoueur(payload, personnages = []) {
    const ids = new Set((personnages ?? []).map((p) => p.id));
    return (payload?.resumes ?? []).find((r) => ids.has(r.personnage_id)) ?? null;
}

/* =========================================================================
   Mapping roster « Joueur » (contrat « Modèle de session »)
   ========================================================================= */

/**
 * Personnage de /moi enrichi → statut affichable pour le roster joueur.
 * Retourne { disponible, groupe?, narrateur_actif?, identifiant? }.
 */
export function statutPersonnage(personnage) {
    if (!personnage) return { disponible: true };
    if (personnage.disponible !== false && personnage.groupe == null) {
        return { disponible: true };
    }
    const g = personnage.groupe ?? {};
    return {
        disponible: false,
        identifiant: g.identifiant ?? null,
        nom: g.nom ?? null,
        phase: g.phase ?? null,
        narrateur_actif: g.narrateur_actif ?? false,
    };
}

/**
 * prets ([{personnage_id, pret}]) + liste de personnages → carte
 * [{personnage_id, nom, pret}] pour l'affichage du hub.
 */
export function pretsVersEtat(prets, personnages = []) {
    const parId = new Map((personnages ?? []).map((p) => [p.id, p.nom ?? `Perso n°${p.id}`]));
    return (prets ?? []).map((r) => ({
        personnage_id: r.personnage_id,
        nom: parId.get(r.personnage_id) ?? `Perso n°${r.personnage_id}`,
        pret: !!r.pret,
    }));
}

/** .niveau.monte ({personnages}) → cartes du bandeau de célébration
 *  [{id, nom, niveau, points, ic, gains: [textes]}] — l'icône de classe
 *  vient des entités d'EtatGroupe quand elles sont connues. */
export function niveauMonteVersListe(payload, entites = []) {
    const classes = new Map((entites ?? [])
        .filter((e) => e.type === 'heros')
        .map((e) => [e.id, (e.classe ?? '').toLowerCase()]));
    return (payload?.personnages ?? []).map((p) => ({
        id: p.id,
        nom: p.nom,
        niveau: p.niveau,
        points: p.points_competence ?? 0,
        ic: CLASSES[classes.get(p.id) ?? (p.classe ?? '').toLowerCase()]?.ic ?? 'person',
        gains: (p.gains ?? []).map(gainVersTexte).filter(Boolean),
    }));
}
