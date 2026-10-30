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
    // Spirit Queen's Torment (lot E, 2026-10-29) : un coffre piégé qui lance Boule de Flammes. `auto_awesome` (l'étincelle
    // magique), et non la flamme de la Fournaise (`whatshot`) ni celle du Piège d'embrasement (`local_fire_department`).
    // Éphémère : jamais posé sur la carte, seule la légende le montre.
    'Piège de Boule de Flammes': 'auto_awesome',
    // The Crypt of Perpetual Darkness (chantier EXT-CRYPTE, 2026-10-23) : une flaque d'acide, « science » (l'alchimie),
    // distincte de la fosse (un trou) et du feu. Persistante, elle se dessine avec la fosse ouverte — voir `DungeonGrid.vue`.
    "Mare d'acide": 'science',
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
    // Jungles of Delthrak (lot E, 2026-10-09) : des lianes qui happent.
    'Piège de lianes': 'nature',
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
    // Spirit Queen's Torment (G0053 p. 13, quête 4 : « brewing strange concoctions on this table ») : une table de fioles,
    // distincte de l'établi d'alchimiste ci-dessus, qui ne dit que la chimie et de la Table de base.
    'Table des brasseurs': 'local_bar',
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
    // Murs de Dread (Wall of Ice / Wall of Flame, Sorciers de Morcar, 2026-10-08) :
    // même rôle de barrière que le Mur de Pierre, un symbole par élément.
    'Mur de Glace': 'ac_unit',
    'Mur de Feu': 'local_fire_department',
    // Jungles of Delthrak (lot E, 2026-10-09) : le Cocon, un obstacle de toile
    // détruit d'UNE action sans jet — l'œuf dit « enveloppe », distinct des
    // blocs ci-dessus.
    'Cocon': 'egg',
    // First Light (FL-Q p. 7, Healing Hearth) : l'âtre, un bloc dressé contre le
    // mur — la silhouette du meuble haut, pas une icône de pièce ni de feu de camp.
    'Cheminée': 'fireplace',
    // Kellar's Keep (F4543 p. 15, quête 4, note D) : la forge des nains, un marteau. Distincte du Mur de Feu et de la
    // Fournaise ci-dessous : une salle qui brûle un héros qui y finit son déplacement (`salle_dangereuse`).
    'Forge des nains': 'hardware',
    // Spirit Queen's Torment (G0053 p. 31, quête 13, note A) : la fournaise, une flamme de meuble. Pas `local_fire_department`
    // (déjà le Mur de Feu) : une chaleur qui brûle le héros qui finit son TOUR dans la salle, désamorçable comme un piège.
    'Fournaise': 'whatshot',
    // Jungles of Delthrak (F9907 p. 4-5, 2026-10-24) : la Statue, un pilier de pierre — elle bloque la vue et le passage
    // comme un pilier, et le livret la dessine comme une relique de pierre. Une seule case, aucune fouille.
    'Statue': 'account_balance',
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
 * DANGER MOBILE AUTONOME (2026-10-23 — rocher roulant de Kellar's Keep, brume de la mort de Witch Lord) : un HEXAGONE,
 * la seule silhouette de la carte qui ne ressemble à aucune autre famille (figures rondes, pièges carrés, épreuves
 * losange, leviers octogone, mobilier et glace en bloc plein, ombre en zone translucide). Un rocher ARRÊTÉ reprend le
 * BLOC_ICONE du piège bloqué : c'est le même obstacle permanent que la Chute de blocs, et il se lit pareil.
 */
export const DANGER_ICONE = 'hexagon';

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
 * Depuis le 2026-10-24 (symboles des livrets, René : « les vrais symboles des livrets à la place des icônes »), la case
 * porte AUSSI son PICTOGRAMME, posé PAR-DESSUS la teinte : `symbole_url`, publié par le serveur case par case — sur chaque
 * case d'un motif répété, sur l'ancre d'un bloc fixe (un seul dessin, étendu sur son emprise), sur une case isolée. La
 * teinte reste ce qui se lit de loin ; le pictogramme dit DE QUEL terrain il s'agit. Il ne remplace jamais la teinte, et
 * reste SOUS les figurines et les marqueurs. Sans `symbole_url`, la case est celle d'avant.
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
    // Trappe de téléportation (EXT-CARTE2, 2026-10-23) : même famille que le tunnel — on n'y
    // jette pas de dé pour la traverser, on en ressort ailleurs. Son dé de passage et sa fin de
    // tour se lisent dans le texte du guide (`avantages`), pas dans la teinte.
    'Trappe de téléportation': 'passage',
    'Glace magique': 'decor',
    'Rebord de crevasse': 'decor',
    // Jungles of Delthrak (lots B et D, 2026-10-09) : trois teintes de plus
    // pour trois risques que le joueur doit lire d'un coup d'œil — `gene`
    // (sable, toile, jungle : coûte 2 points), `eau` (la Mare : on la
    // traverse, on n'y finit pas, on y boit en fouillant) et `feu` (le
    // Brasier : on le traverse, un dé de combat, un crâne fait mal).
    // ⚠ Une teinte CHACUN depuis le 2026-10-24 : sous la teinte commune `gene`, les trois étaient identiques sur la carte.
    'Sable entravant': 'sable',
    'Toile entravante': 'toile',
    'Jungle entravante': 'jungle',
    'Mare': 'eau',
    'Brasier': 'feu',
    // The Mage of the Mirror (F7539 p. 29, 2026-10-15) : les SABLES MOUVANTS, le seul terrain
    // qui BLOQUE la marche — `sables`, une teinte sable à hachure CROISÉE (les autres hachures
    // sont simples : la croix est ce qui dit « ça ne se traverse pas à pied », même en niveaux
    // de gris). On le franchit d'un saut, une option du menu ; le serveur décide quand.
    'Sables mouvants': 'sables',
    // Spirit Queen's Torment (livret G0053 q. 11 note B, lot H des restes, 2026-10-29) : les COURANTS CONTRAIRES, une salle
    // entière. `courant` : hachure HORIZONTALE bleu-cyan — le sens du flux se lit en niveaux de gris comme une rayure.
    // Un coût (2 points), pas un jet : la teinte ne dit pas « danger ».
    'Courant contraire': 'courant',
    // Sol meuble (q. 10 note B, lot H des restes, 2026-10-29) : une salle de terre meuble. `meuble` : hachure en mouchetis
    // BRUN, la même terre que le sable en plus sombre — l'enlisement se lit à l'arrêt, pas au jet : pas une teinte « danger ».
    'Sol meuble': 'meuble',
    // Courant porteur (q. 11 note C, lot H des restes, 2026-10-29) : une salle où l'on nage plus loin. `porteur` : pointillé
    // bleu-cyan, distinct de la rayure du courant contraire (le même liquide, un effet inverse).
    'Courant porteur': 'porteur',
    // Vent de couloir (q. 12 p. 15, lot H des restes, 2026-10-29) : une case de couloir qui POUSSE. `vent` : hachure en chevrons
    // gris-bleu, le sens du souffle — distinct de la rayure du courant contraire et du pointillé du courant porteur. Une case
    // isolée (pose couloirs, sans groupe) : pas d'icône de plaque.
    'Vent de couloir': 'vent',
};
export const TERRAIN_TEINTE_DEFAUT = 'decor';

/**
 * PLAQUE DE TERRAIN (2026-10-24, Jungles of Delthrak) → icône Material Symbols de REPLI, UNE PAR GROUPE.
 *
 * Un bloc (Mare, Brasier : 2×2) ou un motif (Jungle, Toile, Sable : une forme du livret) se lit comme UNE plaque. Quand le
 * livret a un pictogramme pour le terrain, le serveur le pose case par case (`symbole_url`, voir plus haut) et cette icône
 * ne se voit pas. Sans fichier, la plaque garde UNE icône, posée sur l'ancre que le SERVEUR désigne (`carte.terrain[].ancre`),
 * et qui porte le nom du terrain : pas une icône par case, « pas quatre icônes répétées pour une mare ».
 *
 * Les terrains absents de cette table n'ont pas d'icône de repli de plaque. Une case isolée (sans groupe) n'en a pas non
 * plus, et n'affiche rien là où le serveur ne lui publie pas de pictogramme (`symbole_url`). `SymbolesCarteTest` confronte
 * cette table aux terrains à groupe, dans les deux sens.
 */
export const TERRAIN_ICONES = {
    'Mare': 'water',
    'Brasier': 'local_fire_department',
    'Sable entravant': 'landscape',
    'Toile entravante': 'pest_control',
    'Jungle entravante': 'forest',
    // Courant contraire (salle entière, `pose: salle`) : l'icône de repli de la plaque, posée sur son ancre.
    'Courant contraire': 'waves',
    'Sol meuble': 'landslide',
    'Courant porteur': 'surfing',
};
export const TERRAIN_ICONE_DEFAUT = 'texture';

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
