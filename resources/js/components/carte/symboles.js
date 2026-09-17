// SYMBOLES DE LA CARTE — la table des icônes, partagée par le RENDU
// (DungeonGrid) et par la LÉGENDE (LegendeCarte).
//
// ⚠ Un seul fichier pour les deux, et c'est le point : une légende tenue à part
// du rendu se périme au premier symbole ajouté, et elle ment alors avec
// l'autorité d'une légende. Ici, ajouter une icône la fait apparaître aux deux
// endroits, ou à aucun.
//
// ⚠ Chaque table a un REPLI générique : un catalogue s'étend (7 épreuves
// aujourd'hui, davantage demain), et une entrée non listée doit rendre une
// icône neutre plutôt que le nom du glyphe en toutes lettres — Material Symbols
// affiche sa ligature telle quelle quand le nom est inconnu.

/** Pièges (`PiegeSeeder`) → icône Material Symbols. */
export const PIEGE_ICONES = {
    'Fosse': 'vertical_align_bottom',
    'Piège à lances': 'north',
    'Chute de blocs': 'keyboard_double_arrow_down',
    'Piège de coffre': 'lock',
    'Aiguille empoisonnée': 'vaccines',
    'Fiole de poison': 'coronavirus',
};
export const PIEGE_ICONE_DEFAUT = 'warning';

/** Épreuves (`EpreuveSeeder`) → icône Material Symbols. */
export const EPREUVE_ICONES = {
    'Fresque en langue morte': 'history_edu',
    'Grimoire à demi calciné': 'auto_stories',
    'Autel fêlé': 'temple_buddhist',
    'Inscription menaçante': 'notes',
    'Crâne accusateur': 'skull',
    'Dalle descellée': 'layers',
    'Mécanisme gripé': 'settings',
};
export const EPREUVE_ICONE_DEFAUT = 'front_hand';

/** Mobilier (doc 17) → icône Material Symbols. */
export const MOBILIER_ICONES = {
    'Table': 'table_restaurant',
    'Coffre': 'inventory_2',
    'Trône': 'chair',
    "Établi d'alchimiste": 'science',
    'Tombeau': 'monument',
    'Bibliothèque': 'menu_book',
    "Râtelier d'armes": 'swords',
    'Armoire': 'door_sliding',
};
export const MOBILIER_ICONE_DEFAUT = 'category';

/** Levier d'ouverture (doc 14 §3.3) — un seul type, donc pas de table. */
export const LEVIER_ICONE = 'toggle_on';

/**
 * MUR DE GLACE (doc 18 §4 — *Ice Wall*, sort du boss) — un seul type, donc pas
 * de table, comme le levier.
 *
 * ⚠ C'est un MUR, pas un terrain : il ne teinte donc pas la case, il la
 * remplit — même silhouette de BLOC PLEIN que le mobilier bloquant, parce
 * qu'il se lit de la même façon (« on ne passe pas par là ») et qu'il se
 * casse comme lui. La teinte glacée et le compteur de crânes le distinguent
 * d'une armoire.
 */
export const GLACE_ICONE = 'ac_unit';

/**
 * Terrain (doc 18 §4, The Frozen Horror) → catégorie de TEINTE de case.
 *
 * ⚠ FORME DÉLIBÉRÉMENT DIFFÉRENTE des cinq familles ci-dessus : figures,
 * pièges, épreuves, leviers et mobilier sont des OBJETS posés SUR une case —
 * un rond, un carré, un losange, un octogone, un bloc. Le terrain, lui, EST
 * la case : un héros ne se tient pas À CÔTÉ de la glace, il se tient DESSUS.
 * Un marqueur de plus entrerait en concurrence visuelle avec la figurine qui
 * l'occupe (la leçon déjà payée par l'épreuve, qui a dû abandonner son disque
 * plein pour un losange — cf. DungeonGrid.vue). On teinte donc la case
 * elle-même plutôt que de poser un symbole dessus.
 *
 * Trois catégories, pas sept teintes : le vocabulaire d'EFFET compte sept
 * terrains, mais le joueur n'a besoin de distinguer que trois RISQUES —
 * `danger` (jet de dé de combat exigé au contact ou par tour : Glace
 * glissante, Glissière de glace, Rivière gelée, Chambre forte de glace),
 * `passage` (Tunnel de glace — téléportation, pas un jet) et `decor` (Glace
 * magique, Rebord de crevasse — sans effet à ce jour). Sept teintes
 * distinctes auraient demandé sept mémorisations pour un gain de lecture nul
 * — le joueur agit différemment face à un danger et face à un passage, pas
 * face à chacun des sept noms.
 */
export const TERRAIN_TEINTES = {
    'Glace glissante': 'danger',
    'Glissière de glace': 'danger',
    'Rivière gelée': 'danger',
    'Chambre forte de glace': 'danger',
    'Tunnel de glace': 'passage',
    'Glace magique': 'decor',
    'Rebord de crevasse': 'decor',
};
export const TERRAIN_TEINTE_DEFAUT = 'decor';

export const icone = (table, nom, defaut) => table[nom] ?? defaut;
