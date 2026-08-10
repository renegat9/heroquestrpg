<?php

declare(strict_types=1);

/**
 * REGISTRE DES CARTES SOURCES — armurerie et artefacts.
 *
 * Les armes, armures et artefacts du catalogue ne sont pas des valeurs
 * inventées : ils sont la conversion de deux paquets de cartes, carte par
 * carte (`reference/16_armurerie.md` §2.2 et §9.1) :
 *
 *   - `sjeng-equipment.pdf`  — 27 cartes, armurerie
 *   - `sjeng-artefacts.pdf`  — 34 cartes, artefacts de 5 sources officielles
 *
 * Ce fichier recense les **61 cartes**, portées ou non. Il sert trois usages :
 *
 *  1. `CartesSourcesTest` le confronte au catalogue DANS LES DEUX SENS — toute
 *     carte marquée portée doit exister en base, et aucun objet du catalogue ne
 *     peut apparaître sans carte. C'est ce qui rend la phrase « les cartes sont
 *     la source » VÉRIFIABLE plutôt que déclarative.
 *  2. `GET /api/guide` l'expose, et la page /guide affiche la provenance de
 *     chaque pièce ainsi que les cartes non portées — le joueur voit ce qui
 *     existe au plateau et ne tourne pas encore ici.
 *  3. Il documente, pour chaque carte écartée, LA MÉCANIQUE QUI MANQUE. Une
 *     carte non portée est une dette nommée ; une carte portée à moitié serait
 *     une règle promise au joueur et jamais tenue.
 *
 * ⚠ Les deux paquets sont des RÉVISIONS de Ye Olde Inn, pas les composants
 * officiels Avalon Hill : l'auteur de l'armurerie écrit lui-même « I have
 * changed some item costs and functionality ». Les prix et les dés viennent
 * donc de lui. Ce que les livrets officiels corroborent par ailleurs est dit
 * ligne à ligne dans `reference/16_armurerie.md`.
 *
 * Trois familles du catalogue ne viennent PAS de ces paquets, et c'est voulu :
 *  - la **Trousse à outils**, attestée par le livret de règles (LR p. 19) ;
 *  - les **potions** (soin, esprit clair, héroïsme, force, défense, rage,
 *    antidote, fiole), qui sont le deck de TRÉSOR du plateau (doc 01 §8) ;
 *  - les **parchemins**, dérivés un à un des sorts (doc 02 §6).
 */
return [

    /*
    |---------------------------------------------------------------------------
    | Armurerie — sjeng-equipment.pdf (27 cartes)
    |---------------------------------------------------------------------------
    |
    | `objet` = nom dans notre catalogue quand la carte est portée.
    | `manque` = mécanique absente du moteur quand elle ne l'est pas.
    */
    'armurerie' => [
        'source' => 'sjeng-equipment.pdf',
        'url' => 'https://english.yeoldeinn.com/downloads/cards/sjeng-equipment.pdf',
        'libelle' => 'Armurerie (Ye Olde Inn)',
        'cartes' => [
            ['carte' => 'Bastard Sword', 'objet' => 'Épée bâtarde'],
            ['carte' => 'Battle Axe', 'objet' => 'Hache de bataille'],
            ['carte' => 'Broadsword', 'objet' => 'Épée large'],
            ['carte' => 'Cane', 'objet' => 'Canne'],
            ['carte' => 'Crossbow', 'objet' => 'Arbalète'],
            ['carte' => 'Dagger', 'objet' => 'Dague'],
            ['carte' => 'Flail', 'objet' => 'Fléau'],
            ['carte' => 'Greatsword', 'objet' => 'Espadon'],
            ['carte' => 'Halberd', 'objet' => 'Hallebarde'],
            ['carte' => 'Hand Axe', 'objet' => 'Hachette'],
            ['carte' => 'Longbow', 'objet' => 'Arc long'],
            ['carte' => 'Longsword', 'objet' => 'Épée longue'],
            ['carte' => 'Mace', 'objet' => 'Masse'],
            ['carte' => 'Rapier', 'objet' => 'Rapière'],
            ['carte' => 'Shortbow', 'objet' => 'Arc court'],
            ['carte' => 'Shortsword', 'objet' => 'Épée courte'],
            ['carte' => 'Sling', 'objet' => 'Fronde'],
            ['carte' => 'Spear', 'objet' => 'Lance'],
            ['carte' => 'Staff', 'objet' => 'Bâton'],
            ['carte' => 'Whip', 'objet' => 'Fouet'],
            ['carte' => 'Bracers', 'objet' => 'Brassards'],
            ['carte' => 'Chain Mail', 'objet' => 'Cotte de mailles'],
            ['carte' => 'Cloak of Protection', 'objet' => 'Cape de protection'],
            ['carte' => 'Helmet', 'objet' => 'Casque'],
            ['carte' => 'Plate Mail', 'objet' => 'Armure de plates'],
            ['carte' => 'Shield', 'objet' => 'Bouclier'],

            // La seule carte d'armurerie écartée.
            ['carte' => 'Torch', 'nom' => 'Torche',
                'texte' => '2 dés d\'attaque, dégâts de feu, éclaire toute case que son porteur peut voir sans être gêné par les héros ou les monstres, dure une quête.',
                'manque' => 'Aucun système d\'éclairage, et aucun type de dégât « feu » : la semer annoncerait au joueur deux règles que le moteur n\'applique pas.'],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Artefacts — sjeng-artefacts.pdf (34 cartes, 5 sources officielles)
    |---------------------------------------------------------------------------
    */
    'artefacts' => [
        'source' => 'sjeng-artefacts.pdf',
        'url' => 'https://english.yeoldeinn.com/downloads/cards/sjeng-artefacts.pdf',
        'libelle' => 'Artefacts (Ye Olde Inn)',
        'cartes' => [

            // ---- Boîte de base (9) ----
            ['carte' => 'Borin\'s Armour', 'paquet' => 'Boîte de base', 'objet' => 'Armure de Borin'],
            ['carte' => 'Orcs Bane', 'paquet' => 'Boîte de base', 'objet' => 'Fléau des Orques'],
            ['carte' => 'Spirit Blade', 'paquet' => 'Boîte de base', 'objet' => 'Lame des Esprits'],
            ['carte' => 'Talisman of Lore', 'paquet' => 'Boîte de base', 'objet' => 'Talisman du Savoir'],
            ['carte' => 'Elixir of Life', 'paquet' => 'Boîte de base', 'nom' => 'Élixir de Vie',
                'texte' => 'Restaure entièrement les points de Body et de Mind du buveur. Peut aussi ressusciter un héros mort si le porteur est adjacent à la case où il est tombé. Défaussé après usage.',
                'manque' => 'Ni restauration totale ni résurrection : notre « relever » ramène à 1 PV et ne peut pas cibler une case.'],
            ['carte' => 'Ring of Return', 'paquet' => 'Boîte de base', 'nom' => 'Anneau du Retour',
                'texte' => 'Ramène le porteur et tous les héros de la même salle ou du même couloir au point de départ de la quête. Usage unique.',
                'manque' => 'Aucune téléportation, ni individuelle ni de groupe.'],
            ['carte' => 'Spell Ring', 'paquet' => 'Boîte de base', 'objet' => 'Anneau de Sort'],
            ['carte' => 'Wand of Recall', 'paquet' => 'Boîte de base', 'objet' => 'Baguette de Rappel'],
            ['carte' => 'Rod of Memory', 'paquet' => 'Boîte de base', 'objet' => 'Sceptre de Mémoire'],

            // ---- Kellar's Keep & Return of the Witch Lord (5) ----
            ['carte' => 'Magical Throwing Dagger', 'paquet' => 'Kellar\'s Keep / Witch Lord', 'objet' => 'Dague de jet magique'],
            ['carte' => 'Fire Ring', 'paquet' => 'Kellar\'s Keep / Witch Lord', 'objet' => 'Anneau de Feu'],
            ['carte' => 'Anti-Poison Quill', 'paquet' => 'Kellar\'s Keep / Witch Lord', 'objet' => 'Plume anti-poison'],
            ['carte' => 'Armband of Healing', 'paquet' => 'Kellar\'s Keep / Witch Lord', 'nom' => 'Bracelet de Guérison',
                'texte' => 'Rend 2 points de Body au porteur, une fois par quête. Si le porteur tombe à 0 et que le bracelet n\'a pas servi, il le relève aussitôt avec 2 points de Body.',
                'manque' => 'Les charges existent (2026-08-09) mais pas leur RECHARGE par quête, ni le déclenchement automatique quand le porteur tombe à 0 PV — neuf endroits posent `tombe`, il faudrait un point d\'ancrage unique.'],
            ['carte' => 'Dust of Disappearance', 'paquet' => 'Kellar\'s Keep / Witch Lord', 'nom' => 'Poudre d\'Invisibilité',
                'texte' => 'Jetée sur un héros, elle lui permet de passer à travers tous les monstres rencontrés à son prochain tour. Défaussée après usage.',
                'manque' => 'Traverser les FIGURES (le sort Traverser la Pierre traverse la roche, pas les créatures).'],

            // ---- The Frozen Horror (5) ----
            ['carte' => 'Amulet of the North', 'paquet' => 'The Frozen Horror', 'objet' => 'Amulette du Nord'],
            ['carte' => 'Rabbit Boots', 'paquet' => 'The Frozen Horror', 'nom' => 'Bottes du Lièvre',
                'texte' => 'Permettent de sauter par-dessus une fosse par tour en obtenant autre chose qu\'un bouclier noir sur un dé de combat. Inutiles si le porteur déclenche la fosse par accident.',
                'manque' => 'Franchir un piège par un saut : notre franchissement est un jet de Body, sans notion de saut par tour.'],
            ['carte' => 'Armband of Ice', 'paquet' => 'The Frozen Horror', 'nom' => 'Bracelet de Glace',
                'texte' => 'Immunise contre Gel de l\'Esprit et réduit d\'un point les dégâts de froid de tout sort ou effet, pour le seul porteur.',
                'manque' => 'Le mécanisme de types de dégât existe depuis le 2026-08-09 (App\\Engine\\TypeDegat) — mais AUCUNE SOURCE de froid : les 6 sorts de The Frozen Horror sont nommés par le livret sans que leurs effets figurent nulle part. Il n\'y a donc rien contre quoi résister.'],
            ['carte' => 'Ring of Warmth', 'paquet' => 'The Frozen Horror', 'nom' => 'Anneau de Chaleur',
                'texte' => 'Confère une résistance au froid : réduit d\'un point les dégâts de froid de tout sort ou effet.',
                'manque' => 'Idem le Bracelet de Glace : le mécanisme existe, la source de froid non.'],
            ['carte' => 'Snowshoes of Speed', 'paquet' => 'The Frozen Horror', 'nom' => 'Raquettes de Vitesse',
                'texte' => 'Ajoutent 2 cases au déplacement et annulent la glace glissante, tant qu\'elles sont portées. Ne fonctionnent que dans les régions froides et glacées.',
                'manque' => 'Régions de terrain : nos donjons n\'ont ni glace ni climat, la carte perdrait sa condition et deviendrait un bonus permanent.'],

            // ---- The Mage of the Mirror (6) ----
            ['carte' => 'Elven Bracers', 'paquet' => 'The Mage of the Mirror', 'objet' => 'Brassards elfiques'],
            ['carte' => 'Ancient Staff', 'paquet' => 'The Mage of the Mirror', 'nom' => 'Bâton Ancien',
                'texte' => 'Contre n\'importe quel sort lancé sur son porteur. Lancez un dé de combat : crâne, rien ne se passe ; bouclier blanc, le sort est annulé ; bouclier noir, le bâton est détruit.',
                'manque' => 'Contre-sort : rien ne peut interrompre un sort de Dread en cours de résolution.'],
            ['carte' => 'Bone Wand', 'paquet' => 'The Mage of the Mirror', 'nom' => 'Baguette d\'Os',
                'texte' => 'Permet à n\'importe quel héros de contrôler tous les squelettes d\'une salle pendant un tour. Une fois par quête.',
                'manque' => 'Contrôle de monstre : un monstre est joué par le moteur, jamais par un héros. (Le « une fois par quête » de la carte, lui, est couvert par les charges depuis le 2026-08-09.)'],
            ['carte' => 'Elven Boots', 'paquet' => 'The Mage of the Mirror', 'nom' => 'Bottes elfiques',
                'texte' => 'Un elfe qui les porte peut lancer un dé rouge de plus pour son déplacement. Si trois dés donnent le même chiffre, les bottes sont détruites.',
                'manque' => 'Notre déplacement est base + 1d6, pas des dés rouges cumulables — et rien ne détruit un objet porté sur un jet.'],
            ['carte' => 'Elven Bow of Vindication', 'paquet' => 'The Mage of the Mirror', 'objet' => 'Arc elfique de Vindication'],
            ['carte' => 'Sky Orb', 'paquet' => 'The Mage of the Mirror', 'nom' => 'Orbe Céleste',
                'texte' => 'Absorbe en tout 4 points de dégât de Mind : chaque point perdu retire un jeton au lieu d\'entamer le Mind. L\'orbe se brise quand les 4 jetons sont retirés.',
                'manque' => 'Interception des dégâts avant application — et surtout : AUCUN chemin ne réduit les PV de Mind d\'un héros aujourd\'hui, il n\'y a donc rien à absorber (`ResolveurTour`, note ligne ~1705). Les charges, elles, existent depuis le 2026-08-09.'],

            // ---- White Dwarf Magazine (6) ----
            ['carte' => 'Scroll of Spells', 'paquet' => 'White Dwarf', 'objet' => 'Parchemin de Sorts'],
            ['carte' => 'Ring of Brilliance', 'paquet' => 'White Dwarf', 'nom' => 'Anneau de Brillance',
                'texte' => 'Accorde un bonus permanent au choix : +1 dé d\'attaque, +1 dé de défense, +1 point de Body ou +1 point de Mind. Choix unique et définitif ; perdu à jamais si le héros meurt.',
                'manque' => 'Choix du joueur au moment d\'acquérir un objet : nos butins s\'appliquent tels quels.'],
            ['carte' => 'Sognirstane', 'paquet' => 'White Dwarf', 'nom' => 'Sognirstane',
                'texte' => 'Marteau à 2 dés d\'attaque, lançable. Si la cible meurt, le marteau reste sur sa case et il faut y consacrer une attaque pour le reprendre ; sinon il revient en main automatiquement. Les sorts élémentaires n\'ont aucun effet sur son porteur.',
                'manque' => 'Retour automatique d\'une arme lancée, arme au sol à ramasser, et immunité aux sorts élémentaires.'],
            ['carte' => 'Thor\'s Hammer', 'paquet' => 'White Dwarf', 'nom' => 'Marteau de Thor',
                'texte' => '3 dés d\'attaque. Utilisable seulement par un héros portant les Gants du Dieu du Tonnerre. Tue tous les orques de la salle quand on le ramasse.',
                'manque' => 'Un objet qui en EXIGE un autre pour fonctionner.'],
            ['carte' => 'Thunder God\'s Gloves', 'paquet' => 'White Dwarf', 'nom' => 'Gants du Dieu du Tonnerre',
                'texte' => 'Permettent de manier le Marteau de Thor.',
                'manque' => 'Sans le marteau, la carte n\'a aucun effet propre : la porter seule serait une pièce d\'équipement qui ne fait rien.'],
            ['carte' => 'Thunder God\'s Belt', 'paquet' => 'White Dwarf', 'nom' => 'Ceinture du Dieu du Tonnerre',
                'texte' => 'Permet de lancer un dé de défense supplémentaire.',
                'manque' => 'Aucune mécanique — il manque un emplacement de CEINTURE. La ranger dans le slot talisman la ferait concurrencer les bijoux de classe, pour un effet d\'une autre nature.'],

            // ---- Cartes « custom » de l'auteur (3) ----
            ['carte' => 'Magister\'s Hood', 'paquet' => 'Custom (contrepartie)', 'objet' => 'Capuche du Magister'],
            ['carte' => 'Dwarven Runestones', 'paquet' => 'Custom (contrepartie)', 'objet' => 'Runes naines'],
            ['carte' => 'Wand of Galimatias', 'paquet' => 'Custom (contrepartie)', 'objet' => 'Baguette de Galimatias'],
        ],
    ],
];
