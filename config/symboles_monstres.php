<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Jetons de monstres des livrets — phase 2 des symboles (2026-10-29)
|--------------------------------------------------------------------------
| Le jeton d'un monstre est la tête que le livret pose pour lui : une tête dans un anneau. Il vit dans
| public/images/livrets/monstres/{cle}.webp, extrait par image-tools/symboles-livrets/ (type `monstres`).
|
| Le CATALOGUE (MonstreSeeder, noms français) et les LIVRETS (anglais, 2021) ne portent pas toujours le
| même nom. Deux listes :
|  - `cles` : nom de catalogue → clé du jeton, quand le jeton ne porte pas le slug du nom (traduction,
|    nom de 1989, ou figurine partagée avec un autre monstre). Les autres noms prennent Str::slug($nom).
|  - `absents` : nom de catalogue → RAISON écrite, pour tout monstre sans jeton. Une raison « à reprendre »
|    dit qu'un jeton existe dans un livret et qu'il reste à extraire ; ce n'est pas un jeton absent.
|
| Un nom n'est jamais dans les deux listes. Un jeton sans monstre (orphelin) est refusé par
| tests/Feature/Symboles/SymbolesMonstresTest.php, dans les deux sens.
|
| Sans jeton, l'affichage garde l'icône du monstre : le repli reste le comportement normal.
*/

return [

    // Jetons reliés par une correspondance écrite (source entre parenthèses ; « traduction » = même figure).
    'cles' => [
        'Fimir' => 'abomination', // nom de 1989 ; le livret 2021 nomme ce jeton Abomination
        'Guerrier du Chaos' => 'dread-warrior', // nom de 1989 (Chaos Warrior) ; le livret 2021 nomme ce jeton Dread Warrior
        'Sir Ragnar' => 'dread-warrior', // « Use the Dread Warrior miniature to represent » (F6646)
        'Borokk' => 'dread-warrior', // « has the same stats as a Dread warrior » (F4543). La phrase « an evil Dread sorcerer » donne une figurine de sorcier : à trancher
        'Gragor' => 'dread-warrior', // « His stats are the same as a Dread warrior » (F4543). La phrase « Use the Dread sorcerer figure » donne une figurine de sorcier : à trancher
        'Momie' => 'mummy', // traduction du livret
        'Orque' => 'orc', // traduction du livret
        'Effigie gardienne' => 'orc', // « This Orc is a magical effigy » (F9528)
        'Squelette' => 'skeleton', // traduction du livret
        'Squelette de minotaure' => 'skeleton', // « Place a skeleton miniature on the board to represent this monster » (G1798, PDF p. 7)
        'Gobelin' => 'goblin', // traduction du livret
        'Spectre' => 'specter', // traduction du livret
        'Garde-mage' => 'magus-guard', // traduction du livret (« Garde Magus » dans les textes français)
        'Ogre guerrier' => 'ogre-warrior', // traduction du livret (Ogre Horde)
        'Gremlin des glaces' => 'ice-gremlin', // traduction du livret (Frozen Horror)
        'Ours polaire de guerre' => 'polar-warbear', // traduction du livret (Frozen Horror)
        'Crâne putride' => 'skullblight', // rapprochement : même livret (Delthrak), capacité « racines entravantes » ; le livret écrit Skullblight
    ],

    // Monstres sans jeton extrait : une raison par nom, lue par SymbolesMonstresTest.
    'absents' => [
        'Archer elfe' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Archer squelette' => 'à reprendre : archer squelette dans la légende Delthrak (F9907, PDF p. 6), non extrait',
        'Archimage elfe' => 'aucun jeton dans les livrets lus : figurine d’archimage elfe (« Use the elven archmage figure »), sans jeton',
        'Artificière' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Bakabri le Cruel' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Bellthor' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Buubhealxea, reine gobeline' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Cavalier-esprit' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Chamane Gobelin' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Champion' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Crâne du Dread' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Cultiste du Dread' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Deathspinner la Reine du Fléau' => 'à reprendre : « Use the Blightcrawler miniature to represent her » (F9907, PDF p. 9), jeton non extrait',
        'Demonape' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Demonspider' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Doralf' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Dragon' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Dreadshifter' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Fellmarak le Roi Sorcier' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Festral' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Garde du Dread' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Gargouille' => 'à reprendre : Gargoyle dans la légende Ogre Horde (F9528, PDF p. 19), non extrait',
        'Gargouille gardienne' => 'à reprendre : gargouille de la légende Ogre Horde (F9528, PDF p. 19), non extraite',
        'Gargouille-statue' => 'à reprendre : la figurine est une gargouille (« The gargoyle appears to be a stone statue », F2847 PDF p. 13), jeton non extrait',
        'Gobelin archer' => 'à reprendre : archer gobelin dans la légende Delthrak (F9907, PDF p. 6), non extrait',
        'Golem Cœur-de-Forge' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Gretzl la Porte-Fléau' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Gruulob, Forme Démoniaque' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Gruulob, Sorcier Gobelin Corrompu' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Gruzbella Déterminée' => 'aucun jeton : statistiques d’une autre figurine reprises (« Adopt the statistics of … »), aucun jeton propre',
        'Gruzbella Hammerhand' => 'aucun jeton : statistiques d’une autre figurine reprises (« Adopt the statistics of … »), aucun jeton propre',
        'Gruzbella Imprudente' => 'aucun jeton : statistiques d’une autre figurine reprises (« Adopt the statistics of … »), aucun jeton propre',
        'Guerrier elfe' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Haut mage' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Horreur des Glaces' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Kavra' => 'à reprendre : « Use the Gargoyle figure to represent Kavra » (G0053 PDF p. 6), jeton de gargouille non extrait',
        'Kedrick Gilbane' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Kessandria' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Liche' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Loup géant' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Mage de guerre orque' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Magrian' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Maître des orages' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Minotaure' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Momie-naine' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Métamorphe' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Nelath, la Reine des Esprits' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Nexrael' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Nécromancien' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Ogre' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Ogre champion' => 'à reprendre : Ogre Champion dans la légende Ogre Horde (F9528, PDF p. 19), non extrait',
        'Ogre commandant' => 'à reprendre : Ogre Commander dans la légende Ogre Horde (F9528, PDF p. 19), non extrait',
        'Orque archer' => 'à reprendre : archer orque dans la légende Ogre Horde (F9528, PDF p. 19), non extrait',
        'Qwindrak the Warlock' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Rampant putride' => 'à reprendre : Blightcrawler dans la légende Delthrak (F9907, PDF p. 6), rapprochement non confirmé',
        'Rejeton putride' => 'à confirmer : le Spawnling de Delthrak est une tuile posée sur la carte du héros, rapprochement non tranché',
        'Roi Archaloneus' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Scion Fleur-Pourrie' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Seigneur' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Seigneur Sorcier' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Seigneur ogre' => 'à reprendre : Ogre Lord dans la légende Ogre Horde (F9528, PDF p. 19), non extrait',
        'Serpent géant' => 'à reprendre : Serpent dans la légende Delthrak (F9907, PDF p. 6), non extrait',
        'Singe géant' => 'à reprendre : Giant Ape dans la légende Delthrak (F9907, PDF p. 6), non extrait',
        'Skulmar' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Sorcier des Tempêtes' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Sorcier du Dread' => 'aucun jeton dans les livrets lus : figurine de magicien (« Use the Dread sorcerer figure » ou équivalent), sans jeton de sorcier',
        'Sorcière des Marais' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Spawn of the Pit' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Spawn of the Pit déchaîné' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Squelette-roi nain' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Tisseur putride' => 'à reprendre : Blightweaver dans la légende Delthrak (F9907, PDF p. 6), non extrait',
        'Troll' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Venim le Dragon' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Vrille' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Xenloth' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
    ],
];
