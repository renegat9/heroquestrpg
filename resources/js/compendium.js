// Mise en forme des données de RÉFÉRENCE du guide (GET /api/guide) : les effets
// des objets/sorts/pièges et les capacités des monstres sont des specs
// mécaniques JSON (clés destinées au moteur). On les traduit ici en libellés
// français lisibles pour la page /guide. Toute clé inconnue retombe sur un
// rendu « clé: valeur » humanisé — jamais de trou ni de JSON brut à l'écran.

/** Bonus chiffrés → « +N libellé » (valeur positive préfixée d'un +). */
const EFFETS_BONUS = {
    des_attaque: "dé(s) d'attaque",
    des_defense: 'dé(s) de défense',
    bonus_des_attaque: "dé(s) d'attaque",
    bonus_des_defense: 'dé(s) de défense',
    des_degats: 'dé(s) de dégâts',
    soin_pv_body: 'PV Body soignés',
    soin_pv_mind: 'PV Mind soignés',
};

/** Valeurs chiffrées « nombre puis libellé » (ex. « 1 dégât de Body »). */
const EFFETS_QTE = {
    degats_pv_body: 'dégât(s) de Body',
};

/** Valeurs chiffrées « libellé puis nombre » (ex. « difficulté 3 »). */
const EFFETS_VALEUR = {
    difficulte: 'Difficulté',
    difficulte_non_lanceur: 'Difficulté (hors lanceur)',
    cout: 'Coût',
    deplacement_multiplie: 'Déplacement ×',
};

/** Clés booléennes → libellé affiché quand la valeur est vraie. */
const EFFETS_BOOL = {
    deux_mains: 'Arme à deux mains',
    incompatible_deux_mains: 'Incompatible deux mains',
    attaque_diagonale: 'Attaque en diagonale',
    attaque_second_rang: 'Attaque au second rang',
    inutilisable_adjacent: 'Inutilisable au contact',
    jetable: 'Jetable',
    ligne_de_vue: 'Nécessite une ligne de vue',
    defense_applicable: 'Défense applicable',
    empeche_attaque: "Empêche d'attaquer",
    franchit_mur: 'Franchit les murs',
    invocation_ephemere: 'Invocation éphémère',
    bloque_passage: 'Bloque le passage',
    franchissable: 'Franchissable',
    permet_desamorcage: 'Permet le désamorçage',
    aleatoire: 'Aléatoire',
    automatique: 'Automatique',
};

/** Clés à valeur textuelle/énumérée → « libellé : valeur ». */
const EFFETS_ENUM = {
    portee: 'Portée',
    cible: 'Cible',
    condition_appliquee: 'Applique',
    retire_condition: 'Retire',
    duree: 'Durée',
    fin: 'Prend fin',
    resistance: 'Résistance',
    declencheur: 'Déclencheur',
    detection: 'Détection',
    jet: 'Jet',
    frequence: 'Fréquence',
    jetable_frequence: 'Fréquence',
    si: 'Si',
    condition: 'Condition',
    contexte: 'Contexte',
    cout: 'Coût', // capté ici quand le coût est textuel (ex. « déplacement du tour »)
};

/** Capacités de monstres (tags) → libellé lisible. */
const CAPACITES = {
    charge: 'Charge',
    invocation: 'Invocation',
    frappe_de_zone: 'Frappe de zone',
    choix_attaque: 'Attaque massive (au choix)',
    vol: 'Vol',
    peur: 'Peur',
    regeneration: 'Régénération',
};

/** Humanise une valeur brute (snake_case → « snake case »). */
function humaniser(v) {
    if (v === true) return 'oui';
    if (v === false) return 'non';
    if (typeof v === 'string') return v.replace(/_/g, ' ');
    return String(v);
}

/**
 * Un objet d'effet JSON → liste de chips lisibles [{ texte }].
 * Ignore les clés purement internes (références d'id).
 */
export function effetVersChips(effet) {
    if (!effet || typeof effet !== 'object') return [];
    const IGNORE = new Set(['sort_id']);
    const chips = [];
    for (const [k, v] of Object.entries(effet)) {
        if (IGNORE.has(k) || v == null) continue;
        if (k in EFFETS_BONUS && typeof v === 'number') {
            chips.push({ texte: `${v > 0 ? '+' : ''}${v} ${EFFETS_BONUS[k]}` });
        } else if (k in EFFETS_QTE && typeof v === 'number') {
            chips.push({ texte: `${v} ${EFFETS_QTE[k]}` });
        } else if (k in EFFETS_VALEUR && typeof v === 'number') {
            const label = EFFETS_VALEUR[k];
            chips.push({ texte: label.endsWith('×') ? `${label}${v}` : `${label} ${v}` });
        } else if (k in EFFETS_BOOL) {
            if (v) chips.push({ texte: EFFETS_BOOL[k] });
        } else if (k in EFFETS_ENUM) {
            chips.push({ texte: `${EFFETS_ENUM[k]} : ${humaniser(v)}` });
        } else {
            chips.push({ texte: `${humaniser(k)} : ${humaniser(v)}` });
        }
    }
    return chips;
}

/**
 * Capacités d'un monstre (liste de tags OU map {tag: params}) → chips.
 */
export function capacitesVersChips(capacites) {
    if (!capacites) return [];
    const tags = Array.isArray(capacites) ? capacites : Object.keys(capacites);
    return tags
        .filter((t) => typeof t === 'string')
        .map((t) => ({ texte: CAPACITES[t] ?? humaniser(t) }));
}

/** Libellés d'affichage des énumérations de catalogue. */
export const CATEGORIE_OBJET = {
    arme: 'Armes', armure: 'Armures', outil: 'Outils', consommable: 'Consommables', parchemin: 'Parchemins',
};
export const RARETE = {
    commun: 'Commun', peu_commun: 'Peu commun', rare: 'Rare', unique: 'Unique',
};
export const EMPLACEMENT = {
    arme_principale: 'Main principale', arme_secondaire: 'Main secondaire', armure: 'Armure', sac: 'Sac', consommable: 'Consommable',
};
export const TIER_MONSTRE = { base: 'Sbires', sous_boss: 'Sous-boss', boss: 'Boss' };
export const ELEMENT = {
    feu: { l: 'Feu', ic: 'local_fire_department' },
    eau: { l: 'Eau', ic: 'water_drop' },
    terre: { l: 'Terre', ic: 'landslide' },
    air: { l: 'Air', ic: 'air' },
};
export const TYPE_SORT = { degats: 'Dégâts', mental: 'Mental', utilitaire: 'Utilitaire' };
export const TYPE_TALENT = { passif: 'Passif', actif: 'Actif', deblocage: 'Déblocage' };
export const CLASSE = {
    barbare: { l: 'Barbare', ic: 'sports_martial_arts' },
    nain: { l: 'Nain', ic: 'hardware' },
    elfe: { l: 'Elfe', ic: 'nature' },
    magicien: { l: 'Magicien', ic: 'auto_awesome' },
};
export const DESARMABLE = { oui: 'Désamorçable', non: 'Non désamorçable', partiel: 'Désamorçage partiel' };
