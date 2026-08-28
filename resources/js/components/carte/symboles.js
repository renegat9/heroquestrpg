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

export const icone = (table, nom, defaut) => table[nom] ?? defaut;
