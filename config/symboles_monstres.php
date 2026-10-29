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
|    nom de 1989, figurine partagée). Les autres noms prennent Str::slug($nom).
|  - `absents` : nom de catalogue → RAISON écrite, pour tout monstre sans jeton rond. « à reprendre » :
|    un jeton existe dans un livret mais n'est pas un disque (figure 1×2, jeton ovale) ou n'est pas
|    encore isolé ; ce n'est pas un jeton absent.
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
        'Sir Ragnar' => 'dread-warrior', // « Use the Dread Warrior miniature to represent him » (F6646 PDF 16) ; la quête 2 de base (F2847 PDF 4) met la figurine de Dread sorcerer : retenu le texte de la quête 9, dont le bloc de statistiques est celui du catalogue
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
        'Ogre guerrier' => 'ogre-warrior', // traduction du livret (Ogre Horde)
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
        'Haut mage' => 'morcar-sorcerer', // lieutenant de Morcar « High Mage » (G1504 PDF 13 : « Zanrath has all 6 High Mage spells ») ; jeton « one of the Sorcerers »
        'Maître des orages' => 'morcar-sorcerer', // lieutenant « Storm Master » (G1504 PDF 15 : « Boroush has all 6 Storm Master spells »)
        'Nécromancien' => 'morcar-sorcerer', // lieutenant « Necromancer » (G1504 PDF 14 : « Fanrax has all 6 Necromancer spells »)
        'Mage de guerre orque' => 'morcar-sorcerer', // lieutenant « Orc Warcaster » (G1504 PDF 16 : « Nyashak has all 6 Orc Warcaster spells »)
    ],

    // Monstres sans jeton rond : une raison par nom, lue par SymbolesMonstresTest.
    'absents' => [
        'Archer elfe' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Archimage elfe' => '« Use the elven archmage figure » : figurine sans jeton dans les livrets lus',
        'Artificière' => 'figurine de Dread Sorcerer (« Use the Dread sorcerer figure »), aucun jeton de sorcier dans les livrets lus',
        'Bakabri le Cruel' => '« Use the Goblin Warlock miniature » (F9907 PDF 21) : aucun jeton de Goblin Warlock',
        'Bellthor' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Buubhealxea, reine gobeline' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Cavalier-esprit' => 'Spirit Riders (F4193 PDF 6) : aucune figurine renvoyée dans le texte',
        'Chamane Gobelin' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Champion' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Crâne du Dread' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Cultiste du Dread' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Deathspinner la Reine du Fléau' => 'à reprendre, non rond : « Use the Blightcrawler miniature to represent her » (F9907 PDF 9), jeton ovale',
        'Demonape' => 'Gretzl (F9907 PDF 18) : aucune figurine renvoyée dans le texte',
        'Demonspider' => 'Gretzl (F9907 PDF 18) : aucune figurine renvoyée dans le texte',
        'Doralf' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Dragon' => 'aucun jeton de dragon dans les livrets lus (figurine « Dragon miniature »)',
        'Dreadshifter' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Fellmarak le Roi Sorcier' => 'figurine de Dread Sorcerer (« Use the Dread sorcerer figure »), aucun jeton de sorcier dans les livrets lus',
        'Festral' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Garde du Dread' => 'Elite Dread Warrior (G1504 PDF 13) : le texte ne relie pas la garde à ce jeton',
        'Gargouille gardienne' => 'aucun jeton : « guardian gargoyle » n’est nommé dans les livrets lus',
        'Golem Cœur-de-Forge' => 'représenté par sa tuile (« Use the Forgeheart Golem’s tile », F9907 PDF 12)',
        'Gretzl la Porte-Fléau' => 'Gretzl (F9907 PDF 18) : aucune figurine renvoyée dans le texte',
        'Gruulob, Forme Démoniaque' => 'Gruulob (F9907 PDF 14) : statistiques reprises, aucune figurine renvoyée',
        'Gruulob, Sorcier Gobelin Corrompu' => 'Gruulob (F9907 PDF 14) : statistiques reprises, aucune figurine renvoyée',
        'Gruzbella Déterminée' => 'aucun jeton : statistiques d’une autre figurine reprises (« Adopt the statistics of … »), aucun jeton propre',
        'Gruzbella Hammerhand' => 'aucun jeton : statistiques d’une autre figurine reprises (« Adopt the statistics of … »), aucun jeton propre',
        'Gruzbella Imprudente' => 'aucun jeton : statistiques d’une autre figurine reprises (« Adopt the statistics of … »), aucun jeton propre',
        'Guerrier elfe' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Horreur des Glaces' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Kedrick Gilbane' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Kessandria' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Liche' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Loup géant' => 'figurines de loup géant (« giant wolf figures ») : aucun jeton',
        'Magrian' => 'Magrian, the Dread Wraith (F6646 PDF 17) : aucune figurine renvoyée dans le texte',
        'Minotaure' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Momie-naine' => 'Arcane Mummy (F9907 PDF 15) : identité non établie, aucune figurine renvoyée',
        'Métamorphe' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Nexrael' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Ogre' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Ogre champion' => 'à reprendre, non rond : figure 1×2, jeton ovale (Ogre Champion, légende Ogre Horde (F9528 PDF 19)) ; un disque ne le tient pas',
        'Ogre commandant' => 'à reprendre, non rond : figure 1×2, jeton ovale (Ogre Commander, légende Ogre Horde (F9528 PDF 19)) ; un disque ne le tient pas',
        'Qwindrak the Warlock' => '« Replace Qwindrak with the Dragon miniature » (First Light PDF 9) : aucun jeton de dragon',
        'Rampant putride' => 'à reprendre, non rond : Blightcrawler, jeton ovale (légende Delthrak PDF 6) ; identité non confirmée',
        'Rejeton putride' => 'à reprendre, non rond : Spawnling de Delthrak (F9907 PDF 7), une tuile carrée et non un disque',
        'Roi Archaloneus' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Scion Fleur-Pourrie' => 'Bloomrot Scion (F9907 PDF 20) : aucune figurine renvoyée dans le texte',
        'Seigneur' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Seigneur Sorcier' => 'figurine de Dread Sorcerer (« Use the Dread sorcerer figure »), aucun jeton de sorcier dans les livrets lus',
        'Seigneur ogre' => 'à reprendre, non rond : figure 1×2, jeton ovale (Ogre Lord, légende Ogre Horde (F9528 PDF 19)) ; un disque ne le tient pas',
        'Serpent géant' => 'à reprendre, non rond : figure 1×2, jeton ovale (Serpent, légende Delthrak (F9907 PDF 6)) ; un disque ne le tient pas',
        'Singe géant' => 'à reprendre, non rond : figure 1×2, jeton ovale (Giant Ape, légende Delthrak (F9907 PDF 6)) ; un disque ne le tient pas',
        'Skulmar' => 'figurine de Dread Sorcerer (« Use the Dread sorcerer figure »), aucun jeton de sorcier dans les livrets lus',
        'Sorcier des Tempêtes' => 'figurine de Dread Sorcerer (« Use the Dread sorcerer figure »), aucun jeton de sorcier dans les livrets lus',
        'Sorcier du Dread' => 'figurine de Dread Sorcerer (« Use the Dread sorcerer figure »), aucun jeton de sorcier dans les livrets lus',
        'Sorcière des Marais' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Spawn of the Pit' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Spawn of the Pit déchaîné' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Squelette-roi nain' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Troll' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
        'Venim le Dragon' => 'aucun jeton de dragon dans les livrets lus',
        'Vrille' => 'Tendril : un pion de bois (F9907 PDF 20), pas une figurine',
        'Xenloth' => 'aucun jeton dans les livrets lus : ni étiquette « Wandering Monster », ni légende, ni figurine renvoyée par le texte',
    ],
];
