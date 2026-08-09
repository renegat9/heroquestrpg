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

/** Soins exprimés en DÉS (`soin_pv_body_de: 6` = 1d6) — la Fiole de soin du
 *  deck de fouille, à ne pas confondre avec la Potion de soin du marché qui
 *  rend un montant fixe (`soin_pv_body`). Le guide affichait « soin pv body
 *  de : 6 », ce qui se lisait comme 6 PV fixes : l'inverse de la règle. */
const EFFETS_DE = {
    soin_pv_body_de: 'PV Body soignés',
    soin_pv_mind_de: 'PV Mind soignés',
};

/** Valeurs chiffrées « libellé puis nombre » (ex. « difficulté 3 »). */
const EFFETS_VALEUR = {
    difficulte: 'Difficulté',
    difficulte_non_lanceur: 'Difficulté (hors lanceur)',
    cout: 'Coût',
    deplacement_multiplie: 'Déplacement ×',
};

/** Valeurs chiffrées « libellé puis −nombre » (malus). */
const EFFETS_MALUS = {
    malus_deplacement: 'Déplacement',
};

/** Clés booléennes → libellé affiché quand la valeur est vraie. */
const EFFETS_BOOL = {
    attaque_supplementaire: 'Attaque supplémentaire ce tour',
    deux_mains: 'Arme à deux mains',
    incompatible_deux_mains: 'Incompatible deux mains',
    attaque_diagonale: 'Attaque en diagonale',
    inutilisable_adjacent: 'Inutilisable au contact',
    jetable: 'Jetable',
    defense_applicable: 'Défense applicable',
    saute_tour: 'La cible passe son prochain tour',
    ouvre_porte: 'Ou : ouvre une porte à distance',
    franchit_mur: 'Franchit les murs',
    bloque_passage: 'Bloque le passage',
    franchissable: 'Franchissable',
    permet_desamorcage: 'Permet le désamorçage',
    aleatoire: 'Aléatoire',
    automatique: 'Automatique',
};

/**
 * Vocabulaires de mots-clés (App\Engine\DureeEffet, App\Engine\MotsClesSort) →
 * français lisible. Sans ces tables, le repli générique affichait la valeur
 * brute : « Durée : premier degat subi », « Résistance : jet mind » — lisible
 * de justesse, et sans accents.
 */
const VALEURS = {
    duree: {
        prochaine_attaque: "jusqu'à la prochaine attaque",
        prochaine_defense: "jusqu'à la prochaine défense",
        premier_degat_subi: "jusqu'au premier dégât subi",
        ce_tour: 'ce tour',
        prochain_tour: "jusqu'au prochain tour",
        fin_du_combat: "jusqu'à la fin du combat",
    },
    resistance: { jet_mind: 'jet de Mind' },
    cible: {
        soi: 'soi-même',
        heros: 'un héros (soi compris)',
        monstre: 'un monstre',
        monstres_zone: 'des monstres (zone)',
    },
    portee: { distance: 'à distance', corps_a_corps: 'au contact' },
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
    // `sort_nom` double le nom de la pièce (un parchemin porte le nom de son
    // sort) ; `sort_id` est une référence interne. Ni l'un ni l'autre n'apprend
    // quoi que ce soit au joueur.
    // Mots dont la MÉCANIQUE N'EXISTE PAS (App\Engine\MotsClesSort::NON_IMPLEMENTES).
    // Le guide annonçait « Invocation éphémère » sur Génie alors qu'aucun
    // mécanisme d'invocation n'est implémenté : promettre au joueur une règle
    // que le moteur n'applique pas est pire que se taire. À réafficher le jour
    // où la mécanique existe.
    const NON_IMPLEMENTES = new Set(['invocation_ephemere']);
    const IGNORE = new Set(['sort_id', 'sort_nom', ...NON_IMPLEMENTES]);
    const chips = [];
    for (const [k, v] of Object.entries(effet)) {
        if (IGNORE.has(k) || v == null) continue;
        // `duree: 0` n'est pas une durée : c'est l'absence de durée. Le guide
        // affichait « Durée : 0 » sur les potions de force et de défense, ce
        // qui se lit comme « expire immédiatement ». La clé est désormais
        // CÂBLÉE (App\Engine\DureeEffet, doc 19 §2) : les potions portent un
        // mot-clé — `prochaine_attaque`, `fin_du_combat` — que le guide traduit
        // via VALEURS.duree. Le filtre ne reste que pour les catalogues d'avant.
        if (k === 'duree' && (v === 0 || v === '0')) continue;
        if (k in EFFETS_DE && typeof v === 'number') {
            chips.push({ texte: `1d${v} ${EFFETS_DE[k]}` });
        } else if (k in EFFETS_BONUS && typeof v === 'number') {
            chips.push({ texte: `${v > 0 ? '+' : ''}${v} ${EFFETS_BONUS[k]}` });
        } else if (k in EFFETS_QTE && typeof v === 'number') {
            chips.push({ texte: `${v} ${EFFETS_QTE[k]}` });
        } else if (k in EFFETS_VALEUR && typeof v === 'number') {
            const label = EFFETS_VALEUR[k];
            chips.push({ texte: label.endsWith('×') ? `${label}${v}` : `${label} ${v}` });
        } else if (k in EFFETS_MALUS && typeof v === 'number') {
            // Signe explicite : « Déplacement 2 » se lirait comme un bonus.
            chips.push({ texte: `${EFFETS_MALUS[k]} −${v}` });
        } else if (k in EFFETS_BOOL) {
            if (v) chips.push({ texte: EFFETS_BOOL[k] });
        } else if (k in EFFETS_ENUM) {
            chips.push({ texte: `${EFFETS_ENUM[k]} : ${VALEURS[k]?.[v] ?? humaniser(v)}` });
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
    arme_principale: 'Main principale', arme_secondaire: 'Main secondaire', casque: 'Casque', armure: 'Armure', sac: 'Sac', consommable: 'Consommable',
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

/** Maîtrises d'équipement (doc 01 §7) : `objets.tag_equipement` exige,
 *  `classes_heros.tags_equipement` accorde, un talent `deblocage` ajoute. */
export const TAG_EQUIPEMENT = {
    arme_legere: 'Armes légères',
    arme_courante: 'Armes courantes',
    arme_distance: 'Armes de jet et de tir',
    arme_deux_mains: 'Armes à deux mains',
    arme_arc_long: 'Arcs longs',
    arme_arc_court: 'Arcs courts',
    arme_erudit: 'Armes d\u2019érudit',
    armure_legere: 'Armures légères',
    armure_lourde: 'Armures lourdes',
    armure_magicien: 'Protections arcaniques',
    bouclier: 'Boucliers',
};

/**
 * Qui peut porter quoi.
 *
 * Croise les trois sources de la règle : les tags de base de chaque classe, les
 * tags qu'un nœud `deblocage` de son arbre ajoute
 * (`effet.mecanique === 'acces_equipement'`), et le tag exigé par la pièce.
 *
 * ⚠ Une classe SANS tags déclarés n'a AUCUNE restriction (le moteur échoue
 * ouvert : une donnée de référence manquante ne doit jamais enfermer un héros
 * hors de son propre équipement de départ). On la rend donc « autorisée », sans
 * quoi le guide annoncerait une interdiction que le jeu n'applique pas.
 *
 * @param {Array} classes      guide.classes
 * @param {Array} competences  guide.competences
 * @returns {(tag: string|null) => {base: string[], debloque: Array<{classe: string, talent: string}>}}
 */
export function accesEquipement(classes, competences) {
    const deblocages = [];
    for (const c of competences ?? []) {
        const e = c.effet;
        if (!e || e.mecanique !== 'acces_equipement' || !Array.isArray(e.tags)) continue;
        for (const tag of e.tags) deblocages.push({ tag, classe: c.classe, talent: c.nom });
    }

    return (tag) => {
        if (!tag) return { base: [], debloque: [], libre: true }; // pièce sans maîtrise exigée
        const base = [];
        for (const c of classes ?? []) {
            const tags = Array.isArray(c.tags_equipement) ? c.tags_equipement : [];
            if (tags.length === 0 || tags.includes(tag)) base.push(c.nom);
        }
        const debloque = deblocages
            .filter((d) => d.tag === tag && !base.includes(d.classe))
            .map(({ classe, talent }) => ({ classe, talent }));

        return { base, debloque, libre: false };
    };
}
