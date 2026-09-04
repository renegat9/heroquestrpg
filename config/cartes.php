<?php

declare(strict_types=1);

/**
 * REGISTRE DES CARTES SOURCES — équipement, potions et artefacts.
 *
 * Rien du catalogue n'est inventé : chaque arme, armure, potion et artefact est
 * la conversion d'une carte, carte par carte (`reference/16_armurerie.md`
 * §2.1bis et §9.1) :
 *
 *   - `equipments.pdf` — 20 cartes officielles Hasbro, photographiées par René
 *   - `potions.pdf`    — 15 cartes officielles Hasbro
 *   - `sjeng-artefacts.pdf` — 34 cartes d'artefact de 5 sources officielles
 *
 * Ce fichier recense les **69 cartes**, portées ou non. Il sert trois usages :
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
 * ⚠ Le paquet d'armurerie FAN de Ye Olde Inn (`sjeng-equipment.pdf`, 27 cartes)
 * a été RETIRÉ le 2026-08-15 : son auteur écrivait lui-même « I have changed
 * some item costs and functionality », et les photos du matériel réel l'ont
 * rendu caduc. Douze pièces qu'il ajoutait — arcs, fouet, canne, fronde,
 * espadon, épée bâtarde… — n'existent sur aucune carte Hasbro et ont quitté le
 * catalogue avec lui. Les artefacts, eux, restent une conversion Ye Olde Inn :
 * aucune photo du matériel officiel ne les couvre encore.
 *
 * Trois familles du catalogue ne viennent d'aucun paquet, et c'est voulu :
 *  - les **potions du deck de TRÉSOR** (soin, héroïsme, force, défense, fiole),
 *    qui sont des cartes de trésor du plateau et non des articles de boutique ;
 *  - les **parchemins**, dérivés un à un des sorts (doc 02 §6) ;
 *  - rien d'autre : la Trousse à outils, qui faisait exception, a désormais sa
 *    carte officielle (Tool Kit, 250 po).
 */
return [

    /*
    |---------------------------------------------------------------------------
    | Équipement — equipments.pdf (20 cartes officielles Hasbro)
    |---------------------------------------------------------------------------
    |
    | `objet` = nom dans notre catalogue quand la carte est portée.
    | `manque` = mécanique absente du moteur quand elle ne l'est pas.
    |
    | Les vingt sont portées : c'est ce que le passage au paquet officiel a
    | apporté de plus net — plus une seule pièce d'armurerie sans carte, plus
    | une seule carte d'équipement sans lecteur.
    */
    'equipement' => [
        'source' => 'equipments.pdf',
        'url' => 'https://drive.google.com/drive/folders/1seESGzXRhVw7ijIPuRVisaE36BPPoJ53',
        'libelle' => 'Équipement (cartes officielles Hasbro)',
        'cartes' => [
            ['carte' => 'Bandolier', 'objet' => 'Bandoulière'],
            ['carte' => 'Battle Axe', 'objet' => 'Hache de bataille'],
            ['carte' => 'Bracers', 'objet' => 'Brassards'],
            ['carte' => 'Broadsword', 'objet' => 'Épée large'],
            ['carte' => 'Caltrops', 'objet' => 'Chausse-trappes'],
            ['carte' => 'Chain Mail', 'objet' => 'Cotte de mailles'],
            ['carte' => 'Crossbow', 'objet' => 'Arbalète'],
            ['carte' => 'Dagger', 'objet' => 'Dague'],
            ['carte' => 'Handaxe', 'objet' => 'Hachette'],
            ['carte' => 'Helmet', 'objet' => 'Casque'],
            ['carte' => 'Holy Water', 'objet' => 'Eau bénite'],
            ['carte' => 'Longsword', 'objet' => 'Épée longue'],
            ['carte' => 'Plate Mail', 'objet' => 'Armure de plates'],
            ['carte' => 'Rapier', 'objet' => 'Rapière'],
            ['carte' => 'Shield', 'objet' => 'Bouclier'],
            ['carte' => 'Shortsword', 'objet' => 'Épée courte'],
            ['carte' => 'Smoke Bomb', 'objet' => 'Bombe fumigène'],
            ['carte' => 'Staff', 'objet' => 'Bâton'],
            ['carte' => 'Tool Kit', 'objet' => 'Trousse à outils'],
            ['carte' => 'Wand', 'objet' => 'Baguette'],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Potions — potions.pdf (15 cartes officielles Hasbro)
    |---------------------------------------------------------------------------
    |
    | Toutes portées. Trois sont réservées au Barbare et deux à l'Elfe : ce sont
    | les premières restrictions de classe jamais portées par un consommable.
    */
    'potions' => [
        'source' => 'potions.pdf',
        'url' => 'https://drive.google.com/drive/folders/1seESGzXRhVw7ijIPuRVisaE36BPPoJ53',
        'libelle' => 'Potions (cartes officielles Hasbro)',
        'cartes' => [
            ['carte' => 'Potion of Battle', 'objet' => 'Potion de bataille'],
            ['carte' => 'Potion of Battle Rage', 'objet' => 'Potion de rage guerrière'],
            ['carte' => 'Potion of Dexterity', 'objet' => 'Potion de dextérité'],
            ['carte' => 'Potion of Frost Skin', 'objet' => 'Potion de peau de givre'],
            ['carte' => 'Potion of Healing', 'objet' => 'Potion de guérison'],
            ['carte' => 'Potion of Icy Strength', 'objet' => 'Potion de force glaciale'],
            ['carte' => 'Potion of Lesser Healing', 'objet' => 'Potion de soin mineur'],
            ['carte' => 'Potion of Magic', 'objet' => 'Potion de magie'],
            ['carte' => 'Potion of Recall', 'objet' => 'Potion de rappel'],
            ['carte' => 'Potion of Rejuvenation', 'objet' => 'Potion de régénération'],
            ['carte' => 'Potion of Restoration', 'objet' => 'Potion de restauration'],
            ['carte' => 'Potion of Speed', 'objet' => 'Potion de vitesse'],
            ['carte' => 'Potion of Superior Restoration', 'objet' => 'Potion de restauration supérieure'],
            ['carte' => 'Potion of Vision', 'objet' => 'Potion de vision'],
            ['carte' => 'Venom Antidote', 'objet' => 'Antidote au venin'],
        ],
    ],
    /*
    |---------------------------------------------------------------------------
    | Artefacts — CARTES OFFICIELLES (artifacts_part1/2.pdf, © 2021-2023 Hasbro)
    |---------------------------------------------------------------------------
    |
    | ⚠ SOURCE REMPLACÉE le 2026-09-03. Le paquet fan `sjeng-artefacts.pdf` de
    | Ye Olde Inn cède la place aux photos de René — 59 cartes, dont 34 artefacts
    | distincts et 19 sorts de parchemin. Même bascule que l'armurerie le
    | 2026-08-15 et les sorts le 2026-09-02 : quand la carte réelle arrive, elle
    | fait foi.
    |
    | ⚠ CINQ de nos artefacts n'avaient AUCUNE carte et ont quitté le catalogue
    | (migration `retirer_artefacts_hors_source`). Ils ne figurent plus ici : une
    | carte inventée n'est pas une dette, c'est une erreur.
    |
    | ⚠ Les cartes de la boîte GLACE (Frozen Horror) sont recensées mais
    | DÉLIBÉRÉMENT non portées (arbitrage de René) : leurs règles parlent de
    | sorts de froid, de coffres de glace et de rivières gelées qui n'existent
    | nulle part chez nous. Ce n'est pas un lecteur qui leur manque, c'est ce à
    | quoi résister.
    */
    'artefacts' => [
        'source' => 'artifacts_part1.pdf + artifacts_part2.pdf',
        'url' => 'https://drive.google.com/drive/folders/1seESGzXRhVw7ijIPuRVisaE36BPPoJ53',
        'libelle' => 'Artefacts (cartes officielles Hasbro)',
        'cartes' => [

            // ---- Portés : le moteur applique déjà l'effet ----
            ['carte' => "Borin's Armor", 'objet' => 'Armure de Borin'],
            ['carte' => "Orc's Bane", 'objet' => 'Fléau des Orques'],
            ['carte' => 'Spirit Blade', 'objet' => 'Lame des Esprits'],
            ['carte' => 'Talisman of Lore', 'objet' => 'Talisman du Savoir'],
            ['carte' => 'Amulet of the North', 'objet' => 'Amulette du Nord'],
            ['carte' => 'Elven Bracers', 'objet' => 'Brassards elfiques'],
            ['carte' => 'Elven Bow of Vindication', 'objet' => 'Arc elfique de Vindication'],
            ['carte' => 'Magical Throwing Dagger', 'objet' => 'Dague de jet magique'],
            ['carte' => 'Anti-Poison Quill', 'objet' => 'Plume anti-poison'],
            ['carte' => 'Wand of Magic', 'objet' => 'Baguette de Rappel'],
            ['carte' => 'Spell Ring', 'objet' => 'Anneau de Sort'],
            ['carte' => "Wizard's Cloak", 'objet' => 'Cape du Magicien'],
            ['carte' => 'Ring of Fortitude', 'objet' => 'Anneau de Vigueur'],
            ['carte' => 'Fire Ring', 'paquet' => "Kellar's Keep", 'objet' => 'Anneau de Feu'],

            // ⚠ Ces trois-là ont rejoint les portés le 2026-09-03 : elles
            // n'ont demandé AUCUNE mécanique neuve, seulement de réunir sur un
            // OBJET ce qui existait déjà sur des sorts.
            ['carte' => 'Dust of Disappearance', 'objet' => "Poudre d'Invisibilité"],
            ['carte' => 'The Cloak of Shadows', 'objet' => 'Cape des Ombres'],
            ['carte' => 'Rod of Telekinesis', 'objet' => 'Sceptre de Télékinésie'],

            // ---- Sept cartes de plus (2026-09-04) : relance par face, saut au
            // dé de combat, dé de déplacement, téléportation, relance imposée à
            // l'attaquant, renvoi de sort, contrôle de monstres. Toutes
            // attendaient une mécanique, aucune un arbitrage.
            ['carte' => 'Phoenix Ash', 'objet' => 'Cendres du Phénix'],
            ['carte' => "Fortune's Longsword", 'objet' => 'Longue épée de Fortune'],
            ['carte' => "Raven's Talon", 'objet' => 'Serre du Corbeau'],
            ['carte' => 'Phantom Blade', 'objet' => 'Lame Fantôme'],
            ['carte' => 'Dawnshield', 'objet' => "Bouclier de l'Aube"],
            ['carte' => 'The Scales of Elethorn', 'objet' => "Écailles d'Elethorn"],
            ['carte' => "Wizard's Staff", 'objet' => 'Bâton du Magicien'],
            ['carte' => 'Arm Band of Healing', 'objet' => 'Bracelet de Guérison'],
            ['carte' => 'Elixir of Life', 'objet' => 'Élixir de Vie'],
            ['carte' => 'Ring of Return', 'objet' => 'Anneau du Retour'],
            ['carte' => 'Rabbit Boots', 'objet' => 'Bottes de Lièvre'],
            ['carte' => 'Ancient Staff', 'objet' => 'Bâton Ancien'],
            ['carte' => 'Bone Wand', 'objet' => "Baguette d'Os"],
            ['carte' => 'Elven Boots', 'objet' => 'Bottes elfiques'],

            // ---- Non portés : chacun nomme la mécanique qui manque ----
            ['carte' => 'Sky Orb', 'nom' => 'Orbe Céleste',
                'texte' => 'Absorbe 4 points de dégâts de Mind, un jeton à la fois.',
                'manque' => "Absorber des dégâts de MIND : `MoteurDegats` n'intercepte que le Body."],

            // ---- Boîte GLACE (Frozen Horror) : règles absentes du jeu ----
            ['carte' => 'Ring of Warmth', 'paquet' => 'Frozen Horror', 'nom' => 'Anneau de Chaleur',
                'texte' => 'Immunise contre le sort Chill, les coffres de glace et les rivières gelées.',
                'manque' => 'GLACE — ni sort de froid, ni terrain gelé dans le jeu. Rien à quoi résister (arbitrage de René).'],
            ['carte' => 'Armband of Ice', 'paquet' => 'Frozen Horror', 'nom' => 'Brassard de Glace',
                'texte' => "Immunise contre Mind Freeze et Chill, contre les coffres de glace et les rivières gelées, et réduit d'1 point les dégâts d'Ice Storm.",
                'manque' => "GLACE — mêmes absences, et trois sorts de froid qui n'existent pas au catalogue."],
            ['carte' => 'Snowshoes of Speed', 'paquet' => 'Frozen Horror', 'nom' => 'Raquettes de Vitesse',
                'texte' => '+2 cases de déplacement et annule la glace glissante. Utilisables seulement dans les quêtes glacées.',
                'manque' => "GLACE — le bonus serait portable, mais la carte le réserve aux régions gelées, qui n'existent pas."],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Parchemins — les 19 cartes « Spell Scroll »
    |---------------------------------------------------------------------------
    |
    | Un parchemin n'est pas un objet à part chez nous : il DÉRIVE d'un sort
    | (doc 02 §6) via `sorts.difficulte_parchemin`. Cette table dit donc quel
    | sort chaque carte désigne — et lesquelles n'en désignent aucun que nous
    | ayons.
    |
    | ⚠ Elle vit dans sa propre section, et pas parmi les artefacts, parce que
    | les contrôles de `CartesSourcesTest` cherchent une ligne `objets` : un
    | parchemin n'en a pas.
    |
    | ⚠ « Any hero may use this scroll » figure sur plusieurs cartes : c'est
    | exactement notre règle (un non-lanceur tente un jet de Mind).
    */
    'parchemins' => [
        'source' => 'artifacts_part2.pdf',
        'libelle' => 'Parchemins de sort (cartes officielles Hasbro)',
        'cartes' => [
            // ---- Le sort existe : le parchemin existe donc déjà ----
            ['carte' => 'Spell Scroll — Ball of Flame', 'sort' => 'Boule de Feu'],
            ['carte' => 'Spell Scroll — Fire of Wrath', 'sort' => 'Trait de Feu'],
            ['carte' => 'Spell Scroll — Courage', 'sort' => 'Courage'],
            ['carte' => 'Spell Scroll — Sleep', 'sort' => 'Sommeil'],
            ['carte' => 'Spell Scroll — Water of Healing', 'sort' => 'Eau de Guérison'],
            ['carte' => 'Spell Scroll — Heal Body', 'sort' => 'Soin du Corps'],
            ['carte' => 'Spell Scroll — Rock Skin', 'sort' => 'Peau de Pierre'],
            ['carte' => 'Spell Scroll — Pass Through Rock', 'sort' => 'Traverser la Pierre'],
            ['carte' => 'Spell Scroll — Genie', 'sort' => 'Génie'],
            ['carte' => 'Spell Scroll — Tempest', 'sort' => 'Tempête'],
            ['carte' => 'Spell Scroll — Swift Wind', 'sort' => 'Vent Véloce'],

            // ---- Sorts que nous n'avons pas ----
            ['carte' => 'Spell Scroll — Lightning Bolt', 'sort' => 'Éclair'],
            ['carte' => 'Spell Scroll — Treasure Without Doom', 'sort' => 'Trésor sans Péril'],
            ['carte' => 'Spell Scroll — Psychic Recovery', 'sort' => 'Récupération Psychique'],

            // ---- Boîte GLACE : non prises en compte ----
            ['carte' => 'Spell Scroll — Chill', 'paquet' => 'Frozen Horror', 'nom' => 'Morsure du Froid',
                'texte' => '1 PV à tout monstre orthogonalement adjacent au lanceur ; la victime ne peut pas se défendre.',
                'manque' => 'GLACE — sort de froid absent du catalogue (arbitrage de René).'],
            ['carte' => 'Spell Scroll — Warmth', 'paquet' => 'Frozen Horror', 'nom' => 'Chaleur',
                'texte' => "Rend jusqu'à 3 PV de Body au lanceur ou à un héros de son choix.",
                'manque' => 'GLACE — la règle serait portable telle quelle, mais le sort appartient au jeu de froid, écarté en bloc.'],
            ['carte' => 'Spell Scroll — Ice Storm', 'paquet' => 'Frozen Horror', 'nom' => 'Tempête de Glace',
                'texte' => 'Zone de 2×2 ; chaque figure y est attaquée séparément à 3 dés, sans défense possible. Interdit en couloir.',
                'manque' => 'GLACE — et une zone rectangulaire, que notre vocabulaire de zone ne sait pas décrire.'],
            ['carte' => 'Spell Scroll — Ice Bridge', 'paquet' => 'Frozen Horror', 'nom' => 'Pont de Glace',
                'texte' => 'Crée un pont permanent permettant de franchir fosse, piège, gouffre, crevasse ou case glacée.',
                'manque' => "GLACE — et poser du TERRAIN en cours de quête, ce qu'aucune mécanique ne fait."],
            ['carte' => 'Spell Scroll — Skate', 'paquet' => 'Frozen Horror', 'nom' => 'Patinage',
                'texte' => '+6 au jet de déplacement et traversée des monstres et héros, pour un tour.',
                'manque' => 'GLACE — cavernes gelées inexistantes.'],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Sorts de Dread — dread_spells.pdf (29 cartes officielles Hasbro)
    |---------------------------------------------------------------------------
    |
    | La magie du MJ, transcrite carte par carte en doc 09 §4bis. Photos de René
    | du 2026-09-04 : 30 clichés, dont deux tirages de *Channel Dread* (2023 et
    | 2024, texte identique).
    |
    | `sort_dread` = nom au catalogue quand la carte est portée (22 sur 29).
    | `manque`     = la mécanique absente du moteur quand elle ne l'est pas.
    |
    | ⚠ Ce paquet a coûté un sort au catalogue plutôt que d'en ajouter un : le
    | **Trait de Chaos** n'existe sur aucune carte, c'était notre invention, et
    | il est parti par migration — comme les cinq artefacts sans carte du
    | 2026-09-03. « Les cartes sont la source » n'est une propriété que si elle
    | retire autant qu'elle ajoute.
    |
    | ⚠ Les sept écartées le sont ENTIÈREMENT. Deux d'entre elles — *Dispel* et
    | *Mirror Magic* — manquent la même chose, et c'est pour cela qu'elles sont
    | voisines ici : un monstre qui AGIT HORS DE LA PHASE DES MONSTRES.
    */
    'dread' => [
        'source' => 'dread_spells.pdf',
        'url' => 'https://drive.google.com/drive/folders/1seESGzXRhVw7ijIPuRVisaE36BPPoJ53',
        'libelle' => 'Sorts de Dread (cartes officielles Hasbro)',
        'cartes' => [
            // ---- Dégâts ----
            ['carte' => 'Ball of Flame', 'paquet' => 'Base', 'sort_dread' => 'Boule de Flammes'],
            ['carte' => 'Firestorm', 'paquet' => 'Base', 'sort_dread' => 'Tempête de feu'],
            ['carte' => 'Lightning Bolt', 'paquet' => 'Base', 'sort_dread' => 'Éclair de Chaos'],
            ['carte' => 'Chill', 'paquet' => 'Frozen Horror', 'sort_dread' => 'Morsure de Froid'],
            ['carte' => 'Channel Dread', 'paquet' => 'Dread Moon / Delthrak', 'sort_dread' => "Canaliser l'Effroi"],
            ['carte' => 'Ice Storm', 'paquet' => 'Frozen Horror', 'sort_dread' => 'Tempête de Glace'],

            // ---- Contrôle ----
            ['carte' => 'Sleep', 'paquet' => 'Base', 'sort_dread' => 'Sommeil'],
            ['carte' => 'Fear', 'paquet' => 'Base', 'sort_dread' => 'Frayeur'],
            ['carte' => 'Tempest', 'paquet' => 'Base', 'sort_dread' => 'Tourmente'],
            ['carte' => 'Command', 'paquet' => 'Base', 'sort_dread' => 'Commandement'],
            ['carte' => 'Cloud of Dread', 'paquet' => 'Base', 'sort_dread' => "Nuée d'Effroi"],
            ['carte' => 'Mind Blast', 'paquet' => 'Dread Moon', 'sort_dread' => 'Choc Mental'],
            ['carte' => 'Dreadlights', 'paquet' => 'Dread Moon', 'sort_dread' => "Feux de l'Effroi"],
            ['carte' => 'Creeping Grasp', 'paquet' => 'Delthrak', 'sort_dread' => 'Étreinte des Ronces'],

            // ---- Invocation ----
            ['carte' => 'Summon Undead', 'paquet' => 'Base', 'sort_dread' => 'Invocation de morts-vivants'],
            ['carte' => 'Summon Orcs', 'paquet' => 'Base', 'sort_dread' => "Invocation d'orques"],
            ['carte' => 'Summon Wolves', 'paquet' => 'Mage of the Mirror', 'sort_dread' => 'Invocation de loups'],
            ['carte' => 'Summon Specters', 'paquet' => 'Dread Moon', 'sort_dread' => 'Invocation de spectres'],
            ['carte' => 'Reanimation', 'paquet' => 'Dread Moon', 'sort_dread' => 'Réanimation'],

            // ---- Soin / évasion ----
            ['carte' => 'Soothe', 'paquet' => 'Frozen Horror', 'sort_dread' => 'Apaisement'],
            ['carte' => 'Restore Dread', 'paquet' => 'Dread Moon', 'sort_dread' => "Restauration de l'Effroi"],
            ['carte' => 'Escape', 'paquet' => 'Base', 'sort_dread' => 'Fuite'],

            // ---- Non portées : chacune avec la mécanique qui lui manque ----
            ['carte' => 'Mind Freeze', 'paquet' => 'Frozen Horror', 'nom' => "Gel de l'Esprit",
                'texte' => 'Le héros lance 1 dé de combat par point de Mind possédé avant l\'attaque. Au moins un bouclier blanc : il lui reste 1 Mind. Aucun : Mind à zéro, le héros entre en « état de choc ».',
                'manque' => "DÉGÂTS DE MIND — `MoteurDegats` ne voit que le Body (même dette que l'Orbe du Ciel), et « l'état de choc » est une règle du livret Frozen Horror que nous n'avons pas."],
            ['carte' => "Werewolf's Curse", 'paquet' => 'Mage of the Mirror', 'nom' => 'Malédiction du loup-garou',
                'texte' => 'Le héros lance un dé rouge : sur un 6 le sort est sans effet, sinon il contracte la malédiction du loup-garou.',
                'manque' => 'TRANSFORMATION D\'UN HÉROS EN MONSTRE — la carte délègue toute sa règle à la section « Turning Heroes into Werewolves » d\'un livret que nous n\'avons pas.'],
            ['carte' => 'Rust', 'paquet' => 'Base', 'sort_dread' => 'Rouille'],
            ['carte' => 'Ice Wall', 'paquet' => 'Frozen Horror', 'nom' => 'Mur de Glace',
                'texte' => "Jusqu'à 4 cases de glace pleine qui bloquent le déplacement mais pas la vue ; chacune dure tant que le lanceur la voit, ou jusqu'à 5 crânes cumulés d'attaques.",
                'manque' => 'TERRAIN DESTRUCTIBLE À COMPTEUR — la couche `chausse_trappes` en est le plus proche parent, mais elle n\'a ni points de vie ni entretien lié à la vue du lanceur.'],
            ['carte' => 'Skate', 'paquet' => 'Frozen Horror', 'nom' => 'Patinage',
                'texte' => 'Le lanceur patine sur 12 cases et traverse héros et monstres. Dure un tour.',
                'manque' => "MODE DE DÉPLACEMENT POUR UN MONSTRE — `franchit_figures` n'existe que sur le héros, et le déplacement des monstres est piloté par le moteur, sans buff qui le module."],
            ['carte' => 'Dispel', 'paquet' => 'Dread Moon', 'nom' => 'Dissipation',
                'texte' => "Pendant le tour d'un héros, pour annuler un sort qu'il vient de lancer : le lanceur Dread ajoute 1 dé rouge à ses points de Mind, le héros fait de même ; le plus haut total l'emporte.",
                'manque' => "RÉACTION DU MJ PENDANT LE TOUR D'UN HÉROS — `MoteurReactions` ne parle qu'aux héros (offre sur canal privé, réponse d'un téléphone). Ici c'est le MJ qui réagit, sans joueur à consulter : la résolution serait synchrone, mais le point d'entrée n'existe pas."],
            ['carte' => 'Mirror Magic', 'paquet' => 'Dread Moon', 'nom' => 'Magie Miroir',
                'texte' => "Pendant le tour d'un héros, renvoie sur lui le sort qu'il vient de lancer sur le lanceur Dread. Le héros subit l'effet destiné au lanceur.",
                'manque' => 'RÉACTION DU MJ (même manque que Dissipation) + RÉFLEXION DE SORT, déjà nommée comme dette par la doc 16 §9.1 (Bouclier de l\'Aube, Serre du Corbeau).'],
        ],
    ],
];
