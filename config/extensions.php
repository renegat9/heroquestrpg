<?php

/*
 * Les EXTENSIONS décrites au MJ IA, pour qu'il choisisse celles que le thème d'une campagne évoque (René, 2026-11-05 :
 * « donner la liste et une description des extensions à l'agent IA afin qu'il choisisse des extensions selon le prompt
 * donné »). Lu par `App\Partie\CatalogueExtensions` ; une clé par entrée de `DemarreurQuete::BOITES_THEMATIQUES`, ni plus
 * ni moins (testé dans les deux sens).
 *
 * L'AMBIANCE seulement : le lieu, le peuple, la menace, d'après le message de Mentor ou l'introduction de chaque livret.
 * Les CRÉATURES ne sont pas écrites ici : `CatalogueExtensions` les lit dans le catalogue (`monstres.boite`), pour
 * qu'une créature ajoutée ou retirée ne fasse jamais mentir la description.
 */

return [
    'descriptions' => [
        // F6646, introduction et quête 6 : suite de The Mage of the Mirror, « Elethorn's rumored underground city ».
        'dread_moon' => 'Suite de The Mage of the Mirror : le royaume elfique d\'Elethorn affaibli, sa capitale sous rationnement et couvre-feu, une cité souterraine de contrebandiers et de réfugiés, des cultistes et des spectres sous une lune de Dread.',
        // F7539 : « Elven warriors, Elven archers, Elven archmage, Giant wolves, Ogre » ; mobilier « designed for the elven world ».
        'mage_du_miroir' => 'Le royaume elfique d\'Elethorn, ses sanctuaires et ses palais : un archimage elfe, maître des miroirs, retourne contre les héros des guerriers et des archers elfes, des loups géants et des ogres.',
        // F9528 p. 2 : « the Dirgrusht people […] near the World's End mountains », « the strong lead the weak ».
        'horde_ogre' => 'Les clans ogres des confins, au pied des montagnes du Bout du Monde : une terre aride et brutale où le fort commande au faible, des guerriers et des champions ogres, des sorciers du Dread et leurs effigies.',
        // F9907 : « the perilous wilds of the jungle lands, home to the recent dwarven refugees of Kellar's Keep ».
        'jungles_delthrak' => 'La jungle de Delthrak, berceau ancestral des nains réfugiés de Kellar\'s Keep, infectée jusqu\'aux racines par un fléau démoniaque : créatures putrides, bêtes géantes de la jungle, gobelins corrompus.',
        // F5815 : « The Frozen Horror has burst forth from the icy tomb », « to threaten the Northlands once more ».
        'horreur_des_glaces' => 'Les Terres du Nord, glacées, patrie du Barbare : l\'Horreur des Glaces s\'est libérée de son tombeau de glace, avec des yétis, des ours polaires de guerre et des gremlins des glaces.',
        // G0978 et livret de quêtes : « Kessandria […] into the Plains of Death from the Ruins of Kalos », la frontière du royaume.
        'first_light' => 'Les marches du royaume et les plaines de la Mort, où l\'armée de Kessandria traque un transfuge porteur d\'un artefact ancien : un sorcier aux formes changeantes, un dragon, la garde d\'élite du Dread.',
        // G1504 : « the Northern Dread Wastes », « Morcar's lieutenants », « the Shrine of Eiretash ».
        'wizards_of_morcar' => 'Les Désolations du Dread, au nord : les lieutenants de Morcar, sorciers du Dread, veulent arracher un pouvoir scellé dans le sanctuaire d\'Eiretash ; golems, métamorphes et minotaure les servent.',
        // G0052 : « the return of the wizard Melar, creator of the Talisman of Lore », corrompu par la magie du Dread.
        'prophecy_telor' => 'Le retour du mage Melar, créateur du Talisman du Savoir, que la magie du Dread a corrompu : archives saccagées, tombeau de Telor, salles des Hauts Mages, un sorcier-roi et ses sorciers du Dread.',
        // F4193 : « He managed to escape to the fallen city of Kalos », « his army of the undead », « the parched Plains of Death ».
        'witch_lord' => 'Le Seigneur Sorcier n\'est pas mort : sous les ruines de la cité de Kalos, au-delà des plaines brûlées de la Mort, il rassemble une armée de morts-vivants que la lumière du jour ne peut souffrir.',
        // F4543 : « Kellar's Keep, the underground bastion of the fortress Kaba Karn […] within the heart of the World's End Mountains ».
        'kellars_keep' => 'Kellar\'s Keep, bastion souterrain de la forteresse naine de Kaba Karn, au cœur des montagnes du Bout du Monde : le roi et son armée y sont assiégés par les orques, les gobelins et les sorciers du Dread.',
        // G1798, quêtes 1 à 10 : taverne, évasion, marais, cryptes, jusqu'à l'antre du dragon Venim.
        'crypte_tenebres' => 'Une crypte plongée dans une obscurité perpétuelle et la ville qui la surplombe : tavernes et prisons, marais, tombeaux de rois anciens, morts-vivants, et un dragon au fond de son antre.',
        // G0053 : « the spell produced Elemental fire. This should not be possible », la Reine des Esprits, le Temple englouti.
        'spirit_queen' => 'Une magie du Dread qui produit les éléments, ce qui devrait être impossible : la Reine des Esprits, ses sorcières, quatre tours élémentaires et un temple englouti gardé par des esprits.',
    ],
];
