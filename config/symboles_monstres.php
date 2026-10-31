<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Jetons de monstres des livrets — phases 2 et 3 des symboles (2026-10-29)
|--------------------------------------------------------------------------
| Le jeton d'un monstre est la tête que le livret pose pour lui : une tête dans un anneau. Il vit dans
| public/images/livrets/monstres/{cle}.webp, extrait par image-tools/symboles-livrets/ (type `monstres`).
|
| Le CATALOGUE (MonstreSeeder, noms français) et les LIVRETS (anglais, 2021) ne portent pas toujours le
| même nom. Trois listes :
|  - `cles` : nom de catalogue → clé du jeton, quand le jeton ne porte pas le slug du nom (traduction,
|    nom de 1989, figurine partagée). Les autres noms prennent Str::slug($nom).
|  - `ovales` : noms de catalogue dont le jeton est un OVALE 2:1 (figure 1×2 au livret, phase 3). Le serveur
|    publie `symbole_forme` d'après cette liste. Chaque nom porte un fichier 2:1, chaque fichier 2:1 est déclaré
|    ici, et le manifeste le marque `ovale` : SymbolesMonstresTest vérifie les trois dans les deux sens.
|  - `tuiles` : noms de catalogue dont le livret pose une TUILE et non une tête dans un anneau (le Spawnling,
|    le Golem Cœur-de-Forge de Delthrak) : `symbole_forme` = 'tuile', fichier carré pour une figure d'une case,
|    2:1 pour une figure de deux cases, manifeste marqué `tuile` — même contrôle dans les deux sens que les ovales.
|  - `absents` : nom de catalogue → RAISON écrite, pour tout monstre sans jeton. « à reprendre » : un jeton
|    existe dans un livret mais n'est pas extrait (emprise qui ne correspond pas, photo illisible) ; ce n'est
|    pas un jeton absent.
|
| Un nom n'est jamais dans `absents` ET dans `cles`/`ovales`. Un jeton sans monstre (orphelin) est refusé par
| tests/Feature/Symboles/SymbolesMonstresTest.php, dans les deux sens.
|
| Sans jeton, l'affichage garde l'icône du monstre : le repli reste le comportement normal.
*/

return [

    // Jetons reliés par une correspondance écrite (source entre parenthèses ; « traduction » = même figure).
    'cles' => [
        // Planches « Design Your Own Quest » (René, 2026-10-31 : « les tokens sont affichés après les dernières quêtes ») :
        'Archer elfe' => 'elven-archer', // « Elven Archers », planche « Design Your Own », F6646 PDF 20
        'Guerrier elfe' => 'elven-warrior', // « Elven Warriors », planche « Design Your Own », F6646 PDF 20
        'Cultiste du Dread' => 'dread-cultist', // « Dread Cultists », planche « Design Your Own », F6646 PDF 20
        // 'Dreadshifter' → `dreadshifter` par son slug : « Dreadshifter (Chest) », planche « Design Your Own », G1504 PDF 22 (la version coffre).
        'Artificière' => 'artificer', // son propre jeton « Artificer » (planche « Design Your Own », G1504 PDF 22, 2026-10-31) — plus juste que celui du sorcier du Dread de First Light
        'Fellmarak le Roi Sorcier' => 'dread-sorcerer', // « Use the Dread sorcerer figure » ; jeton du « Quest Map Guide » de First Light (p. 9)
        'Seigneur Sorcier' => 'dread-sorcerer', // « Use the Dread sorcerer figure » ; jeton du « Quest Map Guide » de First Light (p. 9)
        'Skulmar' => 'dread-sorcerer', // « Use the Dread sorcerer figure » ; jeton du « Quest Map Guide » de First Light (p. 9)
        'Sorcier des Tempêtes' => 'dread-sorcerer', // « Use the Dread sorcerer figure » ; jeton du « Quest Map Guide » de First Light (p. 9)
        'Sorcier du Dread' => 'dread-sorcerer', // « Use the Dread sorcerer figure » ; jeton du « Quest Map Guide » de First Light (p. 9)
        'Fimir' => 'abomination', // nom de 1989 ; le livret 2021 nomme ce jeton Abomination
        'Guerrier du Chaos' => 'dread-warrior', // nom de 1989 (Chaos Warrior) ; le livret 2021 nomme ce jeton Dread Warrior
        'Sir Ragnar' => 'sir-ragnar', // son propre jeton « Sir Ragnar » (planche « Design Your Own », F6646 PDF 20, 2026-10-31) ; avant : « Use the Dread Warrior miniature to represent him » (F6646 PDF 16) ; la quête 2 de base (F2847 PDF 4) met la figurine de Dread sorcerer : retenu le texte de la quête 9, dont le bloc de statistiques est celui du catalogue
        'Borokk' => 'dread-warrior', // « has the same stats as a Dread warrior » (F4543) ; le texte donne aussi une figurine de sorcier : à confirmer
        'Gragor' => 'dread-warrior', // « His stats are the same as a Dread warrior » (F4543) ; le texte donne aussi une figurine de sorcier : à confirmer
        'Momie' => 'mummy', // traduction du livret
        'Orque' => 'orc', // traduction du livret
        'Effigie gardienne' => 'orc', // « This Orc is a magical effigy » (F9528 PDF 27)
        'Squelette' => 'skeleton', // traduction du livret
        'Squelette de minotaure' => 'skeleton', // « Place a skeleton miniature on the board to represent this monster » (G1798 PDF 7)
        'Gobelin' => 'goblin', // traduction du livret
        'Spectre' => 'specter', // traduction du livret
        'Garde-mage' => 'magus-guard', // traduction du livret (« Garde Magus » dans les textes français)
        'Ogre guerrier' => 'ogre-warrior', // traduction du livret (Ogre Horde) ; jeton ROND au livret (légende p. 19, étiquette p. 37)
        'Gremlin des glaces' => 'ice-gremlin', // traduction du livret (Frozen Horror)
        'Ours polaire de guerre' => 'polar-warbear', // traduction du livret (Frozen Horror)
        'Crâne putride' => 'skullblight', // rapprochement : même livret (Delthrak), capacité « racines entravantes » ; le livret écrit Skullblight
        'Gargouille' => 'gargoyle', // « destroy Verag, a foul gargoyle » (F2847 PDF 3) ; même figurine que le jeton posé sous la lettre B (F2847 PDF 7)
        'Gargouille-statue' => 'gargoyle', // « a gargoyle that appears to be a stone statue » (F2847 PDF 7, lettre B : le jeton est sur la carte)
        'Kavra' => 'gargoyle', // « Use the Gargoyle figure to represent Kavra » (G0053 PDF 6)
        'Nelath, la Reine des Esprits' => 'gargoyle', // « represented by the Gargoyle figure » (G0053 PDF 17)
        'Orque archer' => 'orc-archer', // archer, légende Ogre Horde (F9528 PDF 19)
        'Gobelin archer' => 'goblin-archer', // archer, légende Ogre Horde (F9528 PDF 19)
        'Archer squelette' => 'skeleton-archer', // archer, légende Ogre Horde (F9528 PDF 19)
        'Tisseur putride' => 'blightweaver', // Blightweaver, légende Delthrak (F9907 PDF 6)
        'Haut mage' => 'high-mage', // jeton « High Mage » (planche « Design Your Own », G1504 PDF 22, 2026-10-31) ; lieutenant de Morcar « High Mage » (G1504 PDF 13 : « Zanrath has all 6 High Mage spells ») ; jeton « one of the Sorcerers »
        'Maître des orages' => 'storm-master', // jeton « Storm Master » (planche « Design Your Own », G1504 PDF 22, 2026-10-31) ; lieutenant « Storm Master » (G1504 PDF 15 : « Boroush has all 6 Storm Master spells »)
        'Nécromancien' => 'necromancer', // jeton « Necromancer » (planche « Design Your Own », G1504 PDF 22, 2026-10-31) ; lieutenant « Necromancer » (G1504 PDF 14 : « Fanrax has all 6 Necromancer spells »)
        'Mage de guerre orque' => 'orc-warcaster', // jeton « Orc Warcaster » (planche « Design Your Own », G1504 PDF 22, 2026-10-31) ; lieutenant « Orc Warcaster » (G1504 PDF 16 : « Nyashak has all 6 Orc Warcaster spells »)
        // Figures 1×2 : la clé est le nom que le livret donne à la figure (légende, planche « Design Your Own »).
        'Ogre commandant' => 'ogre-commander', // Ogre Commander, légende Ogre Horde (F9528 PDF 19) ; jeton OVALE
        'Seigneur ogre' => 'ogre-lord', // Ogre Lord, légende Ogre Horde (F9528 PDF 19) ; jeton OVALE
        'Loup géant' => 'giant-wolf', // Giant Wolf, légende Mage of the Mirror (F7539 PDF 7) ; jeton OVALE
        'Serpent géant' => 'serpent', // Serpent, légende Delthrak (F9907 PDF 6) ; jeton OVALE
        'Singe géant' => 'giant-ape', // Giant Ape, légende Delthrak (F9907 PDF 6) ; jeton OVALE
        'Minotaure' => 'minotaur', // Minotaur, planche « Design Your Own » du Wizards of Morcar (G1504 PDF 22) ; jeton OVALE
        'Horreur des Glaces' => 'frozen-horror', // Frozen Horror, planche « Design Your Own » (F5815 PDF 20) ; jeton ROND au livret, qui ne dessine aucun ovale — figure 1×2 par son texte (F5815 PDF 6)
        'Rampant putride' => 'blightcrawler', // Blightcrawler, légende Delthrak (F9907 PDF 6), stats du Monster Chart p. 47 ; jeton OVALE
        'Deathspinner la Reine du Fléau' => 'blightcrawler', // « Use the Blightcrawler miniature » (F9907 PDF 9) ; jeton OVALE
        'Rejeton putride' => 'spawnling', // Spawnling, légende du monstre errant de la quête 1 de Delthrak (F9907 PDF 7) ; TUILE carrée
        'Golem Cœur-de-Forge' => 'forgeheart-golem', // « Use the Forgeheart Golem's tile » (F9907 PDF 12) ; TUILE 1×2 posée salle A
    ],

    // Monstres dont le jeton est un OVALE 2:1 (figure 1×2 au livret) : le serveur publie `symbole_forme` = 'ovale'.
    'ovales' => [
        'Ogre champion',
        'Ogre commandant',
        'Seigneur ogre',
        'Loup géant',
        'Serpent géant',
        'Singe géant',
        'Minotaure',
        'Rampant putride',
        'Deathspinner la Reine du Fléau',
        // Dragon : jeton du « Quest Map Guide » de First Light (p. 9), scan de René — « l'icône semble bonne » (2026-10-29).
        'Dragon',
    ],

    // Monstres que le livret pose en TUILE (pas une tête dans un anneau) : le serveur publie `symbole_forme` = 'tuile'
    // (2026-10-29). Fichier carré pour une figure d'une case, 2:1 pour deux cases ; le manifeste le marque `tuile`.
    'tuiles' => [
        'Rejeton putride',
        // Golem : tuile d'une case sur deux (F9907 PDF 12), emprise passée à 1×2 (René, 2026-10-29 : « Oui passe à 2 cases »).
        'Golem Cœur-de-Forge',
    ],

    // Monstres sans jeton : une raison par nom, lue par SymbolesMonstresTest.
    'absents' => [
        'Archimage elfe' => '« Use the elven archmage figure » : figurine sans jeton dans les livrets lus',
        'Bakabri le Cruel' => '« Use the Goblin Warlock miniature » (F9907 PDF 21) : aucun jeton de Goblin Warlock',
        'Bellthor' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Buubhealxea, reine gobeline' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Cavalier-esprit' => 'Spirit Riders (F4193 PDF 6) : aucune figurine renvoyée dans le texte',
        'Chamane Gobelin' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Champion' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Crâne du Dread' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Demonape' => 'Gretzl (F9907 PDF 18) : aucune figurine renvoyée dans le texte',
        'Demonspider' => 'Gretzl (F9907 PDF 18) : aucune figurine renvoyée dans le texte',
        'Doralf' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Festral' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Garde du Dread' => 'Elite Dread Warrior (G1504 PDF 13) : le texte ne relie pas la garde à ce jeton',
        'Gargouille gardienne' => 'aucun jeton : « guardian gargoyle » n’est nommé dans les livrets lus',
        'Gretzl la Porte-Fléau' => 'Gretzl (F9907 PDF 18) : aucune figurine renvoyée dans le texte',
        'Gruulob, Forme Démoniaque' => 'Gruulob (F9907 PDF 14) : statistiques reprises, aucune figurine renvoyée',
        'Gruulob, Sorcier Gobelin Corrompu' => 'Gruulob (F9907 PDF 14) : statistiques reprises, aucune figurine renvoyée',
        'Gruzbella Déterminée' => 'aucun jeton : statistiques d’une autre figurine reprises (« Adopt the statistics of … »), aucun jeton propre',
        'Gruzbella Hammerhand' => 'aucun jeton : statistiques d’une autre figurine reprises (« Adopt the statistics of … »), aucun jeton propre',
        'Gruzbella Imprudente' => 'aucun jeton : statistiques d’une autre figurine reprises (« Adopt the statistics of … »), aucun jeton propre',
        'Kedrick Gilbane' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Kessandria' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Liche' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Magrian' => 'Magrian, the Dread Wraith (F6646 PDF 17) : aucune figurine renvoyée dans le texte',
        'Momie-naine' => 'Arcane Mummy (F9907 PDF 15) : identité non établie, aucune figurine renvoyée',
        'Métamorphe' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Nexrael' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Qwindrak the Warlock' => '« Replace Qwindrak with the Dragon miniature » (First Light PDF 9) : la forme de sorcier n’a pas de jeton propre isolé ; la forme de dragon a le sien (Dragon)',
        'Roi Archaloneus' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Scion Fleur-Pourrie' => 'Bloomrot Scion (F9907 PDF 20) : aucune figurine renvoyée dans le texte',
        'Seigneur' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Sorcière des Marais' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Spawn of the Pit' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Spawn of the Pit déchaîné' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Squelette-roi nain' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Troll' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
        'Venim le Dragon' => 'Venim (G1798 PDF 13, « The Dragon Venim’s Lair ») : aucune figurine renvoyée dans le texte ; le jeton « Dragon » (First Light) n’est pas le sien sans texte qui le dise',
        'Vrille' => 'Tendril : un pion de bois (F9907 PDF 20), pas une figurine',
        'Xenloth' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni planche « Design Your Own », ni figurine renvoyée par le texte',
    ],
];
