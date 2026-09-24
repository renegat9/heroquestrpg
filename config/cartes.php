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

            // ---- Trois cartes de plus (2026-09-10) : audit du plan glace —
            // quatre dettes écrites le 2026-09-06 décrivaient un monde qui
            // n'existait déjà plus (Gel de l'Esprit porté, ResolveurTour
            // blessant déjà par le terrain). Une dette dont la raison a changé
            // doit être réécrite : une dette périmée ment aussi sûrement qu'une
            // clé décorative (`docs/regles/artefacts.md`). Trois se sont
            // révélées portables une fois relues contre le code réel ; la
            // quatrième (Brassard de Glace, ci-dessous) ne l'est toujours pas
            // — pour une raison différente de celle qu'on lui prêtait.

            // « Orbe Céleste » / Sky Orb : sa moitié « le producteur existe
            // mais Mind Freeze est une phase à part » était fausse — *Gel de
            // l'Esprit* est porté (SortDreadSeeder, MoteurDread::sortDreadMind())
            // et appelle bien `MoteurDegats::infligerMindAHeros()`. Restait
            // vrai : l'ABSORPTION à jetons elle-même n'avait pas de lecteur —
            // `MoteurSorts::absorbePartielDegatMind()` en est un, couturé sur
            // le même patron à charges qu'`absorbeDegat()` (Anneau de Feu),
            // mais grignotant un MONTANT plutôt que bloquant une NATURE
            // entière, puisque *Gel de l'Esprit* ne porte aucun `type_degat`.
            ['carte' => 'Sky Orb', 'objet' => 'Orbe Céleste'],

            // « Anneau de Chaleur » / Ring of Warmth : sa dette du 2026-09-06
            // disait « aucun lecteur n'applique terrains.effet en jeu » — déjà
            // faux CE JOUR-LÀ : `ResolveurTour::saignerParTerrain()` /
            // `saignerSurRiviere()` existaient et blessaient déjà (Chambre
            // forte de glace, Rivière gelée). Ce qui restait réellement
            // manquant, une fois le code relu : le dégât de terrain ne portait
            // aucun `type_degat`, donc `absorbeDegat()` ne pouvait pas
            // l'intercepter. Les deux terrains nommés par la carte portent
            // désormais `effet.type_degat: froid` (`TerrainSeeder`), lu par
            // `absorbeDegat()` aux DEUX call sites AVANT `infligerAHeros()` —
            // la même couture qui couvre déjà tout sort de type froid (Chill
            // compris, s'il touchait un jour le porteur). Sans charge sur sa
            // carte : immunité PERMANENTE tant qu'elle est portée, comme tout
            // objet sans compteur.
            ['carte' => 'Ring of Warmth', 'paquet' => 'Frozen Horror', 'objet' => 'Anneau de Chaleur'],

            // « Brassard de Glace » / Armband of Ice : NON porté, mais plus
            // pour la raison écrite le 2026-09-06. Cette carte partage la
            // MOITIÉ terrain de l'Anneau de Chaleur — désormais portable, et
            // portée sur ce bracelet-ci aussi (`immunite_degat: froid` couvrirait
            // les mêmes coffres/rivières). Ce qui reste RÉELLEMENT manquant,
            // deux clauses sur quatre : (1) l'immunité à *Gel de l'Esprit*
            // SPÉCIFIQUEMENT — la carte protège du SORT, pas d'un `type_degat`
            // (Mind Freeze n'en porte aucun), et rien n'exclut un porteur de
            // `MoteurDread::cibleMindFreeze()` ; (2) la réduction d'1 point sur
            // *Ice Storm* côté héros — non portée, `Ice Storm` visant plusieurs
            // monstres à la fois (`MotsClesSort::CIBLE_MONSTRES_ZONE`,
            // explicitement `NON_IMPLEMENTES`). Porter ce bracelet sur sa seule
            // moitié terrain laisserait un héros « immunisé au gel » se faire
            // geler par Gel de l'Esprit sans la moindre résistance : exactement
            // la carte à moitié tenue que ce registre existe pour ne jamais
            // afficher.
            ['carte' => 'Armband of Ice', 'paquet' => 'Frozen Horror', 'nom' => 'Brassard de Glace',
                'texte' => "Immunise contre Mind Freeze et Chill, contre les coffres de glace et les rivières gelées, et réduit d'1 point les dégâts d'Ice Storm.",
                'manque' => "GLACE — la moitié TERRAIN (coffres de glace, rivières gelées) est désormais "
                    ."portable (même mécanisme que l'Anneau de Chaleur, `immunite_degat: froid`), mais DEUX "
                    ."clauses sur quatre manquent encore un lecteur propre : l'immunité à *Gel de l'Esprit* "
                    ."elle-même (le sort ne porte aucun `type_degat` que resterait à intercepter — il "
                    ."faudrait exclure le porteur de `MoteurDread::cibleMindFreeze()`, rien ne le fait) et la "
                    ."réduction d'1 point sur *Ice Storm* côté héros (le sort vise plusieurs monstres à la "
                    ."fois, `MotsClesSort::CIBLE_MONSTRES_ZONE` reste `NON_IMPLEMENTES`). Porter ce bracelet "
                    ."sur sa seule moitié terrain vendrait une immunité au gel qui laisse geler par Gel de "
                    ."l'Esprit : exactement la carte à moitié tenue que ce registre existe pour ne jamais "
                    .'afficher.'],

            // « Raquettes de Vitesse » / Snowshoes of Speed : sa dette disait
            // les deux appelants de `deDeplacementAnnule()` (MenuMoteur,
            // ResolveurTour) « hors périmètre de cette phase » — un découpage
            // de travail, jamais un blocage technique, et les deux fichiers ont
            // bougé depuis. `Equipement::bonusDeplacementActif()` est le
            // symétrique de `deDeplacementAnnule()`, lu aux DEUX mêmes points de
            // passage. « Annule la glace glissante » cible nommément la tuile
            // Glace glissante (`ResolveurTour::tronquerSurGlace()`), pas la
            // Glissière (tuile distincte, non nommée par la carte). « Région
            // gelée » n'est sourcée nulle part au-delà du nom : arbitrage
            // écrit — le thème de boîte FIGÉ du groupe
            // (`groupes.theme_bestiaire === horreur_des_glaces`) est la plus
            // proche notion de « région » que le moteur connaisse, une case de
            // terrain ne suffisant pas (Glace glissante elle-même n'est jamais
            // posée hors de ce thème). La boîte est active depuis le
            // 2026-09-06 (`DemarreurQuete::BOITES_THEMATIQUES`) : l'objet joue
            // réellement en jeu, pas seulement au catalogue.
            ['carte' => 'Snowshoes of Speed', 'paquet' => 'Frozen Horror', 'objet' => 'Raquettes de Vitesse'],
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

            // ---- Boîte GLACE : 1 portée, 4 non prises en compte (phase 5,
            // 2026-09-06 — examen carte par carte, plus « écartée en bloc »).
            //
            // ⚠ *Warmth* est le cas limite honnête que CLAUDE.md annonçait
            // depuis le 2026-08-15 : un soin fixe de 3, sans zone, sans
            // résistance, sans terrain — rien ne manquait que l'examen.
            ['carte' => 'Spell Scroll — Warmth', 'paquet' => 'Frozen Horror', 'sort' => 'Chaleur'],

            // ⚠ *Chill* est la carte la plus proche d'être portable — et elle
            // ne l'est pas, pour une raison précise trouvée en confrontant
            // les deux côtés de la table : côté Dread, `Morsure de Froid` est
            // déclarée `zone => ZONE_CONTACT` (les 4 cases orthogonales du
            // lanceur, PLUSIEURS monstres à la fois) — pas une cible unique.
            // Le texte du parchemin (« 1 PV à TOUT monstre orthogonalement
            // adjacent ») confirme qu'il s'agit de la même règle, pas d'une
            // carte plus simple. Or côté HÉROS, `MotsClesSort::CIBLE_MONSTRES_ZONE`
            // est explicitement `NON_IMPLEMENTES` — « le sort touche une seule
            // cible » — et son seul lecteur possible, `ResolveurTour::sortDegats()`,
            // est hors périmètre de cette phase. Le porter en dégradant
            // silencieusement « tous les monstres adjacents » en « un seul »
            // serait exactement la carte à moitié tenue à ne pas afficher.
            ['carte' => 'Spell Scroll — Chill', 'paquet' => 'Frozen Horror', 'nom' => 'Morsure du Froid',
                'texte' => '1 PV à tout monstre orthogonalement adjacent au lanceur ; la victime ne peut pas se défendre.',
                'manque' => "GLACE — cible multiple (les 4 cases orthogonales du lanceur, comme son jumeau "
                    ."Dread `zone => ZONE_CONTACT`) : `MotsClesSort::CIBLE_MONSTRES_ZONE` est explicitement "
                    ."NON_IMPLEMENTES côté héros (« le sort touche une seule cible »), et son seul lecteur "
                    ."possible vit dans ResolveurTour::sortDegats(), hors périmètre de cette phase."],
            ['carte' => 'Spell Scroll — Ice Storm', 'paquet' => 'Frozen Horror', 'nom' => 'Tempête de Glace',
                'texte' => 'Zone de 2×2 ; chaque figure y est attaquée séparément à 3 dés, sans défense possible. Interdit en couloir.',
                'manque' => "GLACE — même manque que Chill, un cran plus loin : une zone carrée 2×2 existe "
                    ."déjà côté Dread (`MotsClesSortDread::ZONE_CARRE_2X2`, lue par `MoteurDread::casesZone()`), "
                    ."mais rien d'équivalent n'existe côté héros — `ResolveurTour::sortDegats()`, hors périmètre."],
            ['carte' => 'Spell Scroll — Ice Bridge', 'paquet' => 'Frozen Horror', 'nom' => 'Pont de Glace',
                'texte' => 'Crée un pont permanent permettant de franchir fosse, piège, gouffre, crevasse ou case glacée.',
                'manque' => "GLACE — poser du TERRAIN en cours de quête (la couche `cartes.grille['terrain']` "
                    ."n'est peuplée qu'à l'assemblage, doc 18 §4/phase 4a) ET la résolution de sort qui le "
                    ."ferait, toutes deux hors périmètre — un mécanisme neuf, pas une couture existante."],
            ['carte' => 'Spell Scroll — Skate', 'paquet' => 'Frozen Horror', 'nom' => 'Patinage',
                'texte' => '+6 au jet de déplacement et traversée des monstres et héros, pour un tour.',
                'manque' => "GLACE — la traversée des figures a un précédent qui MARCHE (`franchit_figures`, "
                    ."posé par le sort Voile de Brume), mais le « +6 au jet de déplacement » n'a aucun lecteur "
                    ."symétrique câblable ici : le seul point qui module l'allonge (`Deplacement::calculer()`) "
                    ."n'est appelé que par `MenuMoteur`/`ResolveurTour`, hors périmètre de cette phase."],
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
            // ⚠ Porté le 2026-09-06 SANS son « état de choc » : la carte délègue
            // cette règle à une section du livret Frozen Horror que nous n'avons
            // pas. Ce qui est tenu — le jet à 1 dé par point de Mind POSSÉDÉ
            // (la jauge, pas l'attribut), et la chute à 0 Mind — l'est
            // entièrement ; l'état de choc reste une dette ÉCRITE, pas un oubli.
            ['carte' => 'Mind Freeze', 'paquet' => 'Frozen Horror', 'sort_dread' => "Gel de l'Esprit"],

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

            // ---- Terrain et déplacement (portés le 2026-09-06) ----
            // ⚠ Le Mur de Glace vit sur SA couche `cartes.grille['glace']`, et
            // non dans le catalogue `terrains` : une entrée de ce catalogue
            // serait candidate au tirage STATIQUE de `AssembleurCarte::placerTerrains()`,
            // ce qu'aucune carte ne décrit — un mur de glace se pose en cours de
            // partie, comme les chausse-trappes. C'est l'architecture du terrain
            // qui est réutilisée (boucle unique de `FabriqueGrille::pour()`),
            // jamais son catalogue.
            ['carte' => 'Ice Wall', 'paquet' => 'Frozen Horror', 'sort_dread' => 'Mur de Glace'],
            ['carte' => 'Skate', 'paquet' => 'Frozen Horror', 'sort_dread' => 'Patinage'],

            // ---- Non portées : chacune avec la mécanique qui lui manque ----
            ['carte' => "Werewolf's Curse", 'paquet' => 'Mage of the Mirror', 'nom' => 'Malédiction du loup-garou',
                'texte' => 'Le héros lance un dé rouge : sur un 6 le sort est sans effet, sinon il contracte la malédiction du loup-garou.',
                'manque' => 'TRANSFORMATION D\'UN HÉROS EN MONSTRE — la carte délègue toute sa règle à la section « Turning Heroes into Werewolves » d\'un livret que nous n\'avons pas.'],
            ['carte' => 'Rust', 'paquet' => 'Base', 'sort_dread' => 'Rouille'],
            ['carte' => 'Dispel', 'paquet' => 'Dread Moon', 'nom' => 'Dissipation',
                'texte' => "Pendant le tour d'un héros, pour annuler un sort qu'il vient de lancer : le lanceur Dread ajoute 1 dé rouge à ses points de Mind, le héros fait de même ; le plus haut total l'emporte.",
                'manque' => "RÉACTION DU MJ PENDANT LE TOUR D'UN HÉROS — `MoteurReactions` ne parle qu'aux héros (offre sur canal privé, réponse d'un téléphone). Ici c'est le MJ qui réagit, sans joueur à consulter : la résolution serait synchrone, mais le point d'entrée n'existe pas."],
            ['carte' => 'Mirror Magic', 'paquet' => 'Dread Moon', 'nom' => 'Magie Miroir',
                'texte' => "Pendant le tour d'un héros, renvoie sur lui le sort qu'il vient de lancer sur le lanceur Dread. Le héros subit l'effet destiné au lanceur.",
                'manque' => 'RÉACTION DU MJ (même manque que Dissipation) + RÉFLEXION DE SORT, déjà nommée comme dette par la doc 16 §9.1 (Bouclier de l\'Aube, Serre du Corbeau).'],
        ],
    ],
];
