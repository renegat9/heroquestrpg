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
