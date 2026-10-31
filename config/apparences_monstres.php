<?php

/*
|--------------------------------------------------------------------------
| Apparence des monstres NOMMÉS — ce que le prompt d'image décrit (2026-10-31)
|--------------------------------------------------------------------------
| Défaut (René, 2026-10-31) : le prompt d'un monstre ne contenait que son NOM (`images.gabarits.monstre`).
| Gemini ne connaît ni « Ulag » ni « Kessandria » : il dessinait un démon cornu pour TOUS les méchants
| nommés, et écrivait « Bakabri » dans l'image. Les génériques (Orque, Squelette…) s'en sortaient : leur
| nom dit ce qu'ils sont.
|
| Ce registre donne à chaque monstre nommé une APPARENCE : une phrase française courte qui décrit CE
| QU'ON VOIT, reprise du livret officiel (son texte, ou la figurine qu'il désigne). Une ligne = un monstre
| du catalogue (`nom_base`), sa source (code du livret, page, citation) en commentaire.
| `BibliothequeImages::promptMonstre()` est le seul point de passage : c'est lui qui lit ce fichier, et
| `GenererImages` ne construit aucun autre prompt de monstre.
|
| ⚠ N'INVENTE RIEN : ni couleur, ni arme, ni trait que le livret ne donne. Un monstre dont le livret ne
| dit rien n'entre PAS ici : son prompt garde le gabarit générique, et le manque se dit dans le rapport
| (« ⚠ non trouvé »), jamais par une supposition.
| ⚠ Une apparence ne porte pas le NOM propre : c'est ce nom que Gemini recopiait dans l'image.
|
| Qui doit avoir une apparence : tout boss et sous-boss du catalogue, et tout monstre dont le jeton
| (`symboles_monstres.cles`) n'est pas le slug de son nom. `ImagesApparencesMonstresTest` tient ce
| registre DANS LES DEUX SENS : tout requis a une apparence, et toute apparence est un monstre du catalogue.
*/

return [

    // ----- Jeu de base (F2847) et monstres de base : le nom dit ce qu'ils sont, la source le confirme -----
    'Gobelin' => 'un gobelin', // F2847 p18, « Goblins » (liste des créatures de Zargon)
    'Squelette' => 'un squelette', // F2847 p18, « Skeletons »
    'Orque' => 'un orque', // F2847 p18, « Orcs are related to goblins »
    'Momie' => 'une momie, gardienne d’un tombeau', // F2847 p03, « This mummy is the guardian of Fellmarg’s tomb »
    'Gargouille' => 'une gargouille immonde', // F2847 p03, « destroy Verag, a foul gargoyle »
    'Fimir' => 'une abomination', // « Wandering Monster in this Quest: Abomination » (nom de 1989 ; le livret de 2021 dit Abomination)
    'Guerrier du Chaos' => 'un guerrier du Dread, chevalier de la Doomguard', // F2847 p18, « Zargon’s elite force of Dread knights » ; F4193 p13

    // ----- Jeu de base : les méchants nommés du livret de quêtes (F2847) -----
    'Ulag' => 'un orque chef de guerre, à la grande épée', // F2847 p04-05, « Ulag, the orc warlord » ; « Use the orc figure with the large sword to represent Ulag »
    'Grak' => 'un orque à l’air coriace, qui tient un bâton et porte une cape de magicien', // F2847 p08, « an extra tough-looking orc who holds a staff … Grak also wears a Wizard’s Cloak »
    'Balur' => 'un sorcier du Dread, mage du feu', // F2847 p10, « Use the Dread sorcerer figure for Balur » ; « Balur, the fire mage »
    'Seigneur Sorcier de Barak Tor' => 'un sorcier du Dread, le Seigneur sorcier', // F2847 p16, « Use the Dread sorcerer figure for the Witch Lord »

    // ----- Kellar's Keep (F4543) -----
    'Squelette-roi nain' => 'un squelette de roi nain', // F4543 p09, « These skeletons are those of ancient dwarven kings »
    'Métamorphe' => 'une abomination, sa forme du moment', // F4543 p13, « a shapechanger … currently in the form of an abomination »
    'Borokk' => 'un sorcier du Dread maléfique', // F4543 p13, « Place Borokk, an evil Dread sorcerer »
    'Gragor' => 'un sorcier du Dread, magicien maléfique', // F4543 p10, « led by Gragor, an evil magician » ; « Use the Dread sorcerer figure to represent Gragor »
    'Gargouille-statue' => 'une gargouille de pierre, qui ressemble à une statue', // F4543 p05, « The gargoyle appears to be a stone statue that does not move » (F2847 p07 : même texte)
    'Gargouille gardienne' => 'une gargouille', // F4543 p14, « moves, attacks, and defends as a normal gargoyle would »

    // ----- Return of the Witch Lord (F4193) -----
    'Bellthor' => 'une gargouille immonde', // F4193 p09, « Bellthor, a foul gargoyle »
    'Cavalier-esprit' => 'un cavalier squelette, de la cavalerie du Seigneur sorcier', // F4193 p06, « the Spirit Riders who once led the Witch Lord’s skeletal cavalry »
    'Garde du Dread' => 'un guerrier du Dread, de la Doomguard', // F4193 p13, « three Doomguard Dread warriors »
    'Skulmar' => 'un sorcier du Dread, capitaine de la Légion oubliée', // F4193 p04, « Skulmar, captain of the Forgotten Legion » ; p11, « Use the Dread sorcerer … to represent Skulmar »
    'Kessandria' => 'une reine sorcière, figurée par un sorcier du Dread', // F4193 p13, « Kessandria, the Witch Queen » ; « Use the Dread sorcerer figure to represent her »
    'Seigneur Sorcier' => 'un sorcier du Dread, le Seigneur sorcier', // F4193 p10, « Use the Dread sorcerer figure to represent the Witch Lord » (et p14)

    // ----- La Crypte des Ténèbres perpétuelles (G1798) -----
    'Crâne du Dread' => 'un crâne du Dread', // G1798 p03, « As soon as a hero sees a Dread Skull, place it on the board »
    'Sorcière des Marais' => 'une sorcière des marais, figurée par un sorcier du Dread', // G1798 p06, « The Dread sorcerer represents a swamp hag »
    'Kedrick Gilbane' => 'un chevalier de la mort, figuré par un sorcier du Dread', // G1798 p12, « The Dread sorcerer represents the Dread knight Kedrick » ; p06, « the death knight »
    'Squelette de minotaure' => 'un squelette géant, cornu, de minotaure, qui brandit sa hache', // G1798 p07, « the bones of a giant, horned skeleton of a minotaur … swings its axe »
    'Roi Archaloneus' => 'une momie, puissant seigneur des momies', // G1798 p09, « This mummy is King Archaloneus, a powerful mummy lord »
    'Momie-naine' => 'une momie, qui fut un nain', // G1798 p11, « The mummy in this room was a dwarf in its former life »
    'Buubhealxea, reine gobeline' => 'une reine gobeline', // G1798 p08, « The goblin queen Buubhealxea »
    'Venim le Dragon' => 'un dragon', // G1798 p13, « The Dragon Venim’s Lair »

    // ----- Against the Ogre Horde (F9528) -----
    'Ogre guerrier' => 'un ogre guerrier', // F9528 p19, légende « Ogre Warrior »
    'Ogre champion' => 'un ogre champion', // F9528 p19, légende « Ogre Champion »
    'Ogre commandant' => 'un ogre commandant', // F9528 p19, légende « Ogre Commander »
    'Seigneur ogre' => 'un seigneur ogre', // F9528 p19, légende « Ogre Lord »
    'Doralf' => 'un ogre, combattant de fosse féroce', // F9528 p23, « a ferocious ogre pit fighter called Doralf »
    'Gruzbella Hammerhand' => 'un ogre', // F9528 p23, « an ogre by the name of Gruzbella Hammerhand »
    'Gruzbella Déterminée' => 'un ogre', // F9528 p25, « Adopt the statistics of Gruzbella Hammerhand » : même figurine
    'Gruzbella Imprudente' => 'un ogre', // F9528 p25, idem : même figurine que Gruzbella Hammerhand
    'Spawn of the Pit' => 'une abomination', // F9528 p20-21, « Wave 3: Heroes versus Spawn of the Pit » ; jeton de la carte, une abomination (`symboles_monstres.cles`). Le livret ne décrit pas son apparence.
    'Spawn of the Pit déchaîné' => 'une abomination enragée', // F9528 p21, « strengthened by its rage » ; même figurine que la troisième vague
    'Nexrael' => 'un apprenti sorcier du Dread', // F9528 p31, « Nexrael, an apprentice Dread Sorcerer »
    'Festral' => 'un sorcier du Dread', // F9528 p35, « This is the domain of Festral, the Dread Sorcerer »
    'Xenloth' => 'un mage du Dread', // F9528 p37, « Festral’s assistant, Xenloth the Dread Mage »
    'Effigie gardienne' => 'un orque, effigie magique qui garde la grille de fer', // F9528 p27, « This Orc is a magical effigy that guards the iron gate of the ogre horde »
    'Gobelin archer' => 'un gobelin archer', // F9528 p19, légende « Goblin Archer »
    'Archer squelette' => 'un squelette archer', // F9528 p19, légende « Skeleton Archer »
    'Orque archer' => 'un orque archer', // F9528 p19, légende « Orc Archer »

    // ----- The Mage of the Mirror (F7539) -----
    'Ogre' => 'un ogre', // F7539 p07 (« Ogre ») ; p11, « the ogres attack »
    'Loup géant' => 'un loup géant', // F7539 p06, « giant wolf figures » ; p07, légende « Giant Wolf »
    'Archimage elfe' => 'un archimage elfe', // F7539 p17, « Use the elven archmage figure »

    // ----- Rise of the Dread Moon (F6646) -----
    'Archer elfe' => 'un archer elfe', // F6646 p04, « Elven Archers … roll 4 Attack dice » (légende : Elven Archer)
    'Guerrier elfe' => 'un guerrier elfe', // F6646 p09, « The Elven Warrior ignores disguised heroes »
    'Cultiste du Dread' => 'un cultiste du Dread, sujet dévoué aux forces du Dread', // F6646 p04, « Dread Cultist, a devoted subject to the forces of Dread »
    'Spectre' => 'un spectre, apparition éthérée de mort-vivant', // F6646 p04, « Specter, an undead and ethereal apparition »
    'Garde-mage' => 'un garde-mage portant un trousseau de clés', // F6646 p11, « Each Magus Guard and Dread Cultist carries a key ring »
    'Sir Ragnar' => 'un chevalier captif, figuré par un guerrier du Dread', // F6646 p16, « Use the Dread Warrior miniature to represent him » ; F2847 p05, « the imprisonment of Sir Ragnar »
    'Magrian' => 'un spectre du Dread, éthéré', // F6646 p17, « Magrian, the Dread Wraith » ; « Dread Wraith Magrian is ethereal »

    // ----- Jungles of Delthrak (F9907) -----
    'Rejeton putride' => 'un rejeton, petite créature engendrée par un autre monstre', // F9907 p03, « a monster with the Spawn ability creates Spawnlings of its own type »
    'Tisseur putride' => 'un tisseur putride', // F9907 p02 et p06, « Blightweaver »
    'Crâne putride' => 'un crâne putride', // F9907 p10, « the Goblin transforms into a Skullblight »
    'Rampant putride' => 'une créature rampante putride', // F9907 p02 et p09, « Blightcrawler » (figurine du Blightcrawler, « Use the Blightcrawler miniature »)
    'Deathspinner la Reine du Fléau' => 'une reine du fléau, en créature rampante putride', // F9907 p09, « Deathspinner the Blightqueen … Use the Blightcrawler miniature to represent her »
    'Serpent géant' => 'un serpent géant', // F9907 p06, légende « Serpent »
    'Singe géant' => 'un singe géant qui bondit', // F9907 p13, « the Giant Ape miniature … as it leaps »
    'Gretzl la Porte-Fléau' => 'une gobeline sorcière, figurée par un sorcier gobelin', // F9907 p18, « Gretzl the Blightspeaker, stands guard here with her lieutenants » ; jeton Goblin Warlock (`cles`)
    'Demonspider' => 'une araignée démoniaque', // F9907 p18, « Gretzl the Demonspider »
    'Demonape' => 'un singe démoniaque', // F9907 p18, « Adopt the statistics of Gretzl the Demonape »
    'Gruulob, Sorcier Gobelin Corrompu' => 'un sorcier gobelin corrompu', // F9907 p14, « Gruulob, Blighted Goblin Warlock »
    'Gruulob, Forme Démoniaque' => 'un sorcier gobelin corrompu, sous sa forme démoniaque', // F9907 p14, « do not remove the miniature from the map » (même figurine)
    'Golem Cœur-de-Forge' => 'un golem de Cœur-de-Forge, à la machinerie arcanique usée par les siècles', // F9907 p12, « The centuries have eroded its arcane hardware »
    'Scion Fleur-Pourrie' => 'un immense scion de fleur-pourrie', // F9907 p19-20, « the massive Bloomrot Scion »
    'Vrille' => 'une tuile de lianes agrippantes', // F9907 p20, « Use the grasping vines tile to represent the Tendril »
    'Bakabri le Cruel' => 'un sorcier gobelin cruel', // F9907 p21 (figurine de Goblin Warlock), « Bakabri the Cruel guards the bridge. Use the Goblin Warlock miniature to represent him »

    // ----- The Frozen Horror (F5815) -----
    'Gremlin des glaces' => 'un gremlin des glaces, voleur', // F5815 p04, « Ice Gremlins steal anything they can get their cold hands on »
    'Yéti' => 'un yéti', // F5815 p19, « Whenever the Yeti’s attack … the Yeti grabs the hero in a powerful hug »
    'Ours polaire de guerre' => 'un ours polaire de guerre', // F5815 p02 et p07, « Polar Warbear »
    'Horreur des Glaces' => 'une horreur ancienne, sortie d’un tombeau de glace', // F5815 p07, « The Frozen Horror has burst forth from the icy tomb »

    // ----- The Wizards of Morcar (G1504) -----
    'Haut mage' => 'un homme ancien à la barbe, au chapeau pointu, qui tient un bâton surmonté d’un croissant de lune', // G1504 p08, « Zanrath, High Mage of Sarako. An ancient, bearded man wearing a pointed hat and carrying a staff topped with a crescent moon »
    'Nécromancien' => 'un homme à l’air mauvais, armé d’une faux acérée, vêtu d’un habit orné de crânes', // G1504 p08, « Fanrax, the Malicious. An evil-looking man wielding a wickedly sharp scythe … adorned with skulls »
    'Maître des orages' => 'un homme en haillons, à la barbe grise emmêlée, qui tient un bâton de chêne', // G1504 p08, « Boroush, the Storm Master … a ragged figure with a tangled gray beard … oaken staff »
    'Mage de guerre orque' => 'une orque, mage de guerre du clan du Nord', // G1504 p08, « Nyashak, Orc Warcaster of the Northern Clan »
    'Artificière' => 'une sorcière artificière redoutable', // G1504 p11, « This formidable Sorceress and artificer … »
    'Minotaure' => 'un minotaure, seigneur des minotaures, qui rugit et charge', // G1504 p17, « Brak-Fellorn, Lord of Minotaurs. He snarls and rushes into a head-long charge »

    // ----- Prophecy of Telor (G0052) -----
    'Fellmarak le Roi Sorcier' => 'un roi sorcier, figuré par un sorcier du Dread', // G0052 p17, « Use the Dread Sorcerer miniature on X to represent Fellmarak »
    'Sorcier du Dread' => 'un sorcier du Dread', // « Use the Dread sorcerer figure » ; jeton du Quest Map Guide de First Light p. 9 (`symboles_monstres.cles`)

    // ----- The Spirit Queen’s Torment (G0053) -----
    'Kavra' => 'une puissante sorcière, figurée par une gargouille', // G0053 p05, « a powerful sorcerer » ; p06, « Use the Gargoyle figure to represent Kavra »
    'Nelath, la Reine des Esprits' => 'une reine des esprits, figurée par une gargouille', // G0053 p17, « The Spirit Queen is here, represented by the Gargoyle figure »

    // ----- First Light (G0978, planches et livret de quêtes) -----
    'Dragon' => 'un dragon', // FL p09 et p12, « When the Dragon is defeated, replace it with … »
    'Qwindrak the Warlock' => 'un sorcier du Dread', // FL p09, « replace it with the Dread Sorcerer miniature »
    'Abraxus' => 'un guerrier du Dread, général de Qwindrak', // FL p18, « Qwindrak’s general is a Dread Warrior called by the name of Abraxus »
];
