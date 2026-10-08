// SYMBOLES DE LA CARTE — la table des icônes, partagée par le RENDU
// (DungeonGrid) et par la LÉGENDE (LegendeCarte).
//
// ⚠ Un seul fichier pour les deux, et c'est le point : une légende tenue à part
// du rendu se périme au premier symbole ajouté, et elle ment alors avec
// l'autorité d'une légende. Ici, ajouter une icône la fait apparaître aux deux
// endroits, ou à aucun.
//
// ⚠ Chaque table a un REPLI générique : un catalogue s'étend (8 épreuves
// aujourd'hui, davantage demain), et une entrée non listée doit rendre une
// icône neutre plutôt que le nom du glyphe en toutes lettres — Material Symbols
// affiche sa ligature telle quelle quand le nom est inconnu.

/**
 * Pièges (`PiegeSeeder`) → icône Material Symbols.
 *
 * ⚠ La Fosse et la Chute de blocs partageaient toutes deux une FLÈCHE VERS LE
 * BAS (`vertical_align_bottom` / `keyboard_double_arrow_down`) : les deux se
 * lisaient comme un trou (René, 2026-09-24 : « je semble toujours avoir des
 * trous »). Or seule la Fosse EST un trou — la Chute de blocs, elle, DEVIENT
 * un mur une fois déclenchée (« a permanent block », livret p. 14). Son icône
 * de piège (encore caché/détecté) devient donc un DANGER de chute de pierres
 * (`landslide`), jamais une flèche : plus aucune collision visuelle avec la
 * Fosse, et le vocabulaire annonce déjà ce que la case va devenir.
 */
export const PIEGE_ICONES = {
    'Fosse': 'vertical_align_bottom',
    'Piège à lances': 'north',
    'Chute de blocs': 'landslide',
    'Piège de coffre': 'lock',
    'Aiguille empoisonnée': 'vaccines',
    'Fiole de poison': 'coronavirus',
    // Against the Ogre Horde (lot B, 2026-10-02) : `content_cut` lit comme une
    // lame, distinct de la flèche du Piège à lances et du danger de chute de
    // la Chute de blocs — même silhouette de FAMILLE (carré), la zone de
    // 3 cases se dessine en plus (voir `DungeonGrid.vue`, `.dg-trap-zone`).
    'Lame balançoire': 'content_cut',
    // `nightlight` (la lune) dit « ténèbres » plutôt que de reprendre la
    // flèche de la Fosse ordinaire, qu'elle imite pourtant mécaniquement :
    // visuellement proche suffit, identique aurait confondu les deux sur une
    // carte qui mêlerait les deux thèmes.
    'Fosse des ténèbres': 'nightlight',
    // Wizards of Morcar (pièges magiques, 2026-10-06, lot B) — trois pièges
    // qui ne peuvent pas être découverts par la fouille ni trouvés au hasard
    // (`detectable: false`), posés par la génération de donjon elle-même
    // (`AssembleurCarte::placerPiegesMorcar()`). Chacun se lit différemment.
    'Piège de téléportation': 'flight_takeoff',
    "Piège de l'ouragan": 'air',
    "Piège d'embrasement": 'local_fire_department',
    // Poison (carte de TRÉSOR, pas un piège : `detectable: true`,
    // `declencheur: 'ouverture_tresor'`, tiré au hasard dans les coffres,
    // 2026-10-06, lot « Cartes de trésor ») — même famille que Piège de
    // coffre (« roll 1 combat die »), si bien que la tête de crâne les
    // rassemble d'une icône neutre plutôt que de distinguer "poison"
    // (vivant) de "piège" (mécanique).
    'Poison': 'skull',
};
export const PIEGE_ICONE_DEFAUT = 'warning';

/**
 * BLOC DE PIERRE tombé (Chute de blocs déclenchée, `MoteurPieges::ETAT_BLOC`,
 * livret p. 14) → icône DISTINCTE de la Chute de blocs non déclenchée
 * (`landslide` ci-dessus) : le piège était un DANGER, le bloc est un
 * OBSTACLE — les confondre est exactement le défaut que cette entrée corrige.
 * Rendu comme un bloc plein (voir `DungeonGrid.vue`, `.dg-trap.bloc`), jamais
 * comme un trou.
 */
export const BLOC_ICONE = 'square';

/** Épreuves (`EpreuveSeeder`) → icône Material Symbols. */
export const EPREUVE_ICONES = {
    'Fresque en langue morte': 'history_edu',
    'Grimoire à demi calciné': 'auto_stories',
    'Autel fêlé': 'temple_buddhist',
    'Inscription menaçante': 'notes',
    'Crâne accusateur': 'skull',
    'Dalle descellée': 'layers',
    'Mécanisme gripé': 'settings',
    // Oracle (First Light, lot C) : un œil qui juge — bénédiction ou
    // malédiction selon le jet, jamais un gain neutre comme les sept autres.
    "L'Oracle de Zargon": 'visibility',
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
    // Against the Ogre Horde (lot B, 2026-10-02) : une caisse, comme le
    // Coffre, mais une icône distincte pour ne pas laisser croire qu'elle
    // paie le coffre de la quête.
    'Caisse de ravitaillement': 'package_2',
    // Mobilier ATTAQUABLE (PV + défense, 2026-10-04) — Jungles of Delthrak et
    // Wizards of Morcar. Trois icônes distinctes des meubles ordinaires
    // ci-dessus : un losange (cristal), un temple (autel), un cadenas (coffre
    // scellé) — rien qui se confonde avec `Coffre`/`Trône`.
    'Amas de cristal': 'diamond',
    'Haut Autel': 'temple_buddhist',
    'Coffre du Dread': 'lock',
    // Mur de Pierre (Wall of Stone, sort de héros, 2026-10-06) : posé EN
    // COURS DE QUÊTE, jamais à la génération — un bloc plein, distinct des
    // trois icônes ci-dessus (aucune ne dit « mur »).
    'Mur de Pierre': 'block',
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
 * Voile d'ombre (*Cloak of Shadows*, Wizards of Morcar) : une ZONE (rectangle
 * 3×2) posée par un sort de héros, avec un compteur de jetons. Rendue comme une
 * surcouche SOMBRE TRANSLUCIDE — jamais un bloc plein : on marche dessous, on y
 * voit les figures (la carte ne les cache pas au joueur, elle coupe la ligne de
 * vue du JEU) — et un chapelet de pastilles dit combien de jetons restent.
 */
export const OMBRE_ICONE = 'visibility_off';

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

/**
 * ESCALIER D'ENTRÉE (chantier escalier-entrée, 2026-10-05) — le repère du
 * plateau d'origine : chaque quête commence et finit à son escalier, posé
 * 2×2 dans la salle de départ.
 *
 * ⚠ Il n'est PAS un objet posé SUR la case comme les cinq familles
 * ci-dessus : comme le terrain, on s'y TIENT — mais à la différence du
 * terrain (une simple teinte, sans rien de plus à repérer), il faut pouvoir
 * le localiser d'un coup d'œil pour savoir où sortir. Un seul motif, pas de
 * table — comme le levier et le mur de glace.
 */
export const ESCALIER_ICONE = 'stairs';

export const icone = (table, nom, defaut) => table[nom] ?? defaut;
