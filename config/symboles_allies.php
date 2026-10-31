<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Jetons des ALLIÉS — le pendant de `symboles_monstres.php` (René, 2026-10-30 : « Oui »)
|--------------------------------------------------------------------------
| Un allié (table `mercenaires` : mercenaires, compagnons, alliés invoqués, captifs à libérer) porte sur la carte le jeton
| que le livret lui dessine, comme un monstre — l'anneau reste celui de sa famille (allié bleu, captif ambre). Sans jeton,
| il garde son portrait (table) ou ses lettres (manette) : le repli reste le comportement normal.
|
|  - `jetons` : nom de catalogue → `type/clé` du fichier sous public/images/livrets/. Le type `allies` tient les jetons
|    propres aux alliés ; un allié qui EST une figure du bestiaire reprend le jeton du monstre (`monstres/…`), parce que
|    son texte nomme cette figure.
|  - `tuiles` : les alliés que le livret pose en TUILE (fichier carré) : `symbole_forme` = 'tuile'.
|  - `absents` : nom de catalogue → RAISON écrite.
|
| Un nom est dans `jetons` OU dans `absents`, jamais les deux, et chaque allié du catalogue est dans l'un des deux :
| tests/Feature/Symboles/SymbolesAlliesTest.php, dans les deux sens (comme les fichiers et le manifeste).
|
| ⚠ Les quatre mercenaires humains : nos cartes (© 2023, reference/01 §4 quater) les nomment Scout, Arbalist, Glaive et
| Striker ; les livrets qui les DESSINENT (The Frozen Horror, Wizards of Morcar) les nomment Scout, Crossbowman,
| Halberdier et Swordsman. Mêmes figurines : mêmes rôles et mêmes capacités — l'arbalète et l'épée large au contact,
| la frappe en diagonale, la défense haute et la lenteur (« The Swordsman […] are slower. Their high defense
| capability… », G1504 PDF 5) —, et le même prix pour l'arbalétrier (75 po, F5815 PDF 6).
*/

return [

    'jetons' => [
        'Éclaireur' => 'allies/scout',               // Scout — légende des Mercenaires, F5815 PDF 7
        'Arbalétrier' => 'allies/crossbowman',       // Arbalist = Crossbowman — légende des Mercenaires, F5815 PDF 7
        'Fauchard' => 'allies/halberdier',           // Glaive = Halberdier — légende des Mercenaires, F5815 PDF 7
        'Estafier' => 'allies/swordsman',            // Striker = Swordsman — légende des Mercenaires, F5815 PDF 7
        'Sir Ragnar (Wizards of Morcar)' => 'allies/sir-ragnar', // planche « Design Your Own », G1504 PDF 22
        'Le Prospecteur' => 'allies/prospector',     // « This tile represents the old prospector », F7539 PDF 4
        'Princesse Millandriel' => 'allies/princess-millandriel', // « This tile represents the kidnapped daughter… », F7539 PDF 4
        // Alliés qui SONT une figure du bestiaire : leur texte la nomme.
        'Raptor apprivoisé' => 'monstres/raptor',    // Fangwarden Armlet : « call forth a Raptor » (reference/18) — le jeton du Raptor de Delthrak
        'Squelette Hearthkin' => 'monstres/skeleton', // Hearthkin Horn : « Each hero places a skeleton » (First Light, quêtes p. 6)
    ],

    'tuiles' => [
        'Le Prospecteur',
        'Princesse Millandriel',
    ],

    // Cherchés dans les 21 livrets le 2026-10-31 : légendes des cartes de quête, planches « Design Your Own », listes de
    // composants. Un allié qui n'y paraît qu'en PHOTO de figurine n'a pas de jeton dessiné.
    'absents' => [
        'Ogre mercenaire' => '« Ogre Mercenaries » (Against the Ogre Horde, F9528 PDF 8) : une règle de recrutement, sans figurine nommée ; aucune légende de carte ni planche ne le dessine',
        'Loup' => '« Wolf » (Against the Ogre Horde) : seulement en photo de figurine, dans la liste des composants (F9528 PDF 3) ; aucune légende de carte ni planche ne le dessine en jeton',
        'Croc-sabre' => '« Saberfang Ally » (Jungles of Delthrak) : seulement en photo de figurine, dans la liste des composants (F9907 PDF 2) ; aucune légende de carte ni planche ne le dessine en jeton',
        'Gothar' => '« Use any available hero figure for Gothar » (The Frozen Horror, F5815 PDF 10) : une figurine de héros, aucun jeton',
    ],
];
