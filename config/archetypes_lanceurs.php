<?php

/**
 * RÉPERTOIRES DES LANCEURS DE DREAD (doc 09 §4bis.3).
 *
 * Au-delà de la liste brute `monstres.sorts_dread`, un « lanceur nommé » possède
 * un RÉPERTOIRE COMPLET et thématique, assigné en bloc. Le moteur (MoteurDread)
 * le résout quand `monstres.archetype_lanceur` vaut la CLÉ d'une entrée ci-
 * dessous ; l'IA n'habille que le nom et l'apparence (Q6), jamais les effets.
 * Les noms de `sorts` DOIVENT exister au catalogue (SortDreadSeeder) — un
 * répertoire qui nomme un sort absent est silencieusement vide.
 *
 * ⚠ **Ces répertoires ne sont plus inventés** (2026-09-04). Ils transcrivent les
 * porteurs officiels documentés en doc 18, boîte par boîte : le Dread Cultist
 * connaît *Dreadlights* et *Channel Dread*, le Magus Guard *Ball of Flame* et
 * *Tempest*, le Specter lance *Channel Dread* à volonté, le Blightweaver
 * *Channel Dread* et *Creeping Grasp*, le Dread Wraith les quatre siens, la
 * Frozen Horror ses six (dont trois ne sont pas portés). Les trois archétypes
 * historiques — nécromancien, maître des tempêtes, chaman orque — restent les
 * nôtres et sont marqués comme tels : aucune carte ne les décrit, ils
 * regroupent des sorts qui, eux, viennent tous d'une carte.
 *
 * ⚠ Une entrée n'existe ici que si un monstre du catalogue la PORTE. Un
 * archétype sans porteur serait la configuration décorative que ce projet
 * traque partout ailleurs ; `SortsDreadSourcesTest` le vérifie dans les deux
 * sens.
 *
 * `porteur` = le `monstres.nom_base` qui déclare l'archétype, pour que le lien
 * se lise ici aussi et pas seulement dans le seeder ; il est vérifié.
 *
 * ⚠ Les clés `label` et `voix` ont été RETIRÉES le 2026-09-04 : personne ne les
 * lisait, et `voix` était pire que décorative — elle nommait des profils de
 * barks (« sorcier », « orque ») qui n'existent pas dans `config/barks.php`,
 * dont les profils sont gobelin/brute/mort_vivant/demon/champion/boss/defaut.
 * Une clé fausse que rien ne lit est exactement ce que ce projet supprime
 * ailleurs ; il n'y avait pas de raison d'en semer six de plus en ajoutant les
 * archétypes sourcés.
 *
 * ⚠ Le répertoire est FILTRÉ PAR PALIER à l'exécution (`sorts_dread.palier`, un
 * tier MINIMUM — doc 09 §4). Un archétype se déclare donc complet, et c'est le
 * TIER de la créature qui le porte qui décide de ce qu'elle en tire : le Chamane
 * Gobelin (sous-boss) hérite de Frayeur, Sommeil et Canaliser l'Effroi, mais ni
 * du Commandement ni de l'Invocation d'orques, réservés au palier boss. Un
 * chaman de rang boss ajouté demain commandera, sans qu'on touche à cette table.
 */
return [

    // ------------------------------------------------------------------
    // Archétypes SOURCÉS — doc 18, un porteur officiel chacun
    // ------------------------------------------------------------------

    'culte_effroi' => [
        'porteur' => 'Cultiste du Dread',
        // « connaît *Dreadlights* et *Channel Dread*, chacun 1 fois par quête »
        // (Rise of the Dread Moon, doc 18). Les deux sont de palier `base` :
        // c'est la boîte qui a donné la magie à des créatures ordinaires, et
        // c'est ce qui a fait naître ce palier chez nous.
        'sorts' => [
            'Feux de l\'Effroi',
            'Canaliser l\'Effroi',
        ],
    ],

    'spectre_hurlant' => [
        'porteur' => 'Spectre',
        // « mort-vivant et éthéré, lance *Channel Dread* À VOLONTÉ ». Le « à
        // volonté » de la carte reste borné par notre budget d'usages — une
        // créature de base en a UN par rencontre. Divergence assumée : sans
        // borne, un spectre par salle canaliserait à chaque tour de monstre.
        'sorts' => [
            'Canaliser l\'Effroi',
        ],
    ],

    'garde_magus' => [
        'porteur' => 'Garde-mage',
        // « connaît *Ball of Flame* et *Tempest*, chacun 1 fois par quête ».
        'sorts' => [
            'Boule de Flammes',
            'Tourmente',
        ],
    ],

    'spectre_effroi' => [
        'porteur' => 'Ombre du Dread',
        // « éthéré, connaît *Dreadlights, Channel Dread, Fear, Summon
        // Specters*, chacun 1 fois par quête ». Le seul répertoire officiel qui
        // couvre les trois familles à la fois : marquer, blesser, appeler.
        'sorts' => [
            'Feux de l\'Effroi',
            'Canaliser l\'Effroi',
            'Frayeur',
            'Invocation de spectres',
        ],
    ],

    'archimage_elfe' => [
        'porteur' => 'Archimage elfe',
        // **Sinestra, l'archemage** (boss final, quête 9 — Mage of the Mirror
        // p. 30) : « connaît *dispel, firestorm, mind blast, mirror magic,
        // reanimation, restore Dread, summon wolves, werewolf's curse* ».
        //
        // ⚠ TROIS de ses huit ne sont pas portés — *Dispel* et *Mirror Magic*
        // attendent qu'un monstre puisse agir hors de la phase des monstres,
        // *Werewolf's Curse* une transformation de héros. On ne les nomme donc
        // pas : un répertoire qui cite un sort absent rétrécit en silence.
        // Restent les cinq portés, et c'est elle qui donne enfin un lanceur à
        // l'*Invocation de loups* — la carte dit bien « GIANT wolves », et le
        // *Loup géant* est au bestiaire depuis le portage des extensions.
        'sorts' => [
            'Invocation de loups',
            'Choc Mental',
            'Tempête de feu',
            'Réanimation',
            'Restauration de l\'Effroi',
        ],
    ],

    'tisseur_fleau' => [
        'porteur' => 'Tisseur putride',
        // Monster Chart des Jungles of Delthrak, p. 47 : « Sorts *Channel
        // Dread*, *Creeping Grasp* ». C'est le seul lanceur du catalogue dont
        // les DEUX sorts sont du palier `base` et qui ne blesse presque pas —
        // il entrave, et laisse les autres frapper.
        'sorts' => [
            'Canaliser l\'Effroi',
            'Étreinte des Ronces',
        ],
    ],

    'horreur_glacee' => [
        'porteur' => 'Horreur des Glaces',
        // « connaît 6 sorts Dread fixes (*Chill, Ice Storm, Ice Wall, Mind
        // Freeze, Skate, Soothe*) + 6 sorts au choix du MJ » (Frozen Horror
        // p. 37).
        // ⚠ TROIS des six ne sont pas portés — Ice Wall, Mind Freeze et Skate
        // demandent chacun une mécanique entière (registre `config/cartes.php`).
        // On ne les nomme donc pas ici : un répertoire qui cite un sort absent
        // du catalogue rétrécit en silence, et personne ne verrait que la moitié
        // du boss a disparu. Les trois portés, plus le *Choc Mental* au titre
        // des « 6 au choix du MJ » que sa carte lui accorde expressément.
        'sorts' => [
            'Morsure de Froid',
            'Tempête de Glace',
            'Apaisement',
            'Choc Mental',
        ],
    ],

    // ------------------------------------------------------------------
    // Archétypes DE NOUS — aucune carte ne les décrit ; seuls leurs sorts
    // sont sourcés. Ils existaient avant le passage aux cartes officielles
    // et gardent leurs porteurs.
    // ------------------------------------------------------------------

    'seigneur_du_chaos' => [
        'porteur' => 'Seigneur',
        // ⚠ Le Seigneur REJOINT les archétypes le 2026-09-04 (René : « remets le
        // Seigneur en rotation »). Il gardait jusque-là une liste brute
        // `monstres.sorts_dread`, au motif que ce n'est pas un sorcier nommé
        // mais un bloc de stats — or le pool de rencontre finale se déclare en
        // ARCHÉTYPES, et un boss hors du pool ne peut plus apparaître du tout
        // depuis que les gabarits en nomment un. Le motif ne valait donc plus
        // son prix : il coûtait au Seigneur son existence en jeu.
        //
        // ⚠ Le **Champion** (sous-boss), lui, garde sa liste brute, et c'est
        // délibéré : il reste le seul porteur en production du repli de
        // `repertoireSorts()`. Une branche que plus aucune donnée n'emprunte est
        // une branche qu'on ne sait plus si elle marche.
        //
        // Répertoire NÔTRE, comme les trois du dessous — aucune carte ne décrit
        // ce personnage ; seuls ses sorts viennent d'une carte.
        'sorts' => [
            'Tempête de feu',
            'Invocation de morts-vivants',
            'Commandement',
            'Fuite',
        ],
    ],

    'necromancien' => [
        'porteur' => 'Liche',
        // Morts-vivants + contrôle. La *Réanimation* le distingue désormais du
        // simple invocateur : il ne fait pas qu'appeler des sbires, il relève
        // ceux que le groupe vient d'abattre — la seule carte du paquet qui
        // rende une victoire réversible.
        // ⚠ La *Rouille* lui revient (2026-09-04) : ses trois porteurs officiels
        // sont des bosses de la même famille — le Roi Archaloneus, seigneur
        // momie (« Sleep, Rust, Command »), et Fellmarak le Roi Sorcier
        // (« Firestorm, Rust, Command, Fear, Cloud of Dread »), doc 18. Une
        // liche est le plus proche des deux dans notre bestiaire.
        'sorts' => [
            'Invocation de morts-vivants',
            'Réanimation',
            'Sommeil',
            'Frayeur',
            'Rouille',
            'Canaliser l\'Effroi',
        ],
    ],

    'maitre_tempetes' => [
        'porteur' => 'Sorcier des Tempêtes',
        // Dégâts de zone + esquive : embrase, foudroie, terrifie, se dérobe.
        // ⚠ L'*Éclair de Chaos* remplace ici le *Trait de Chaos*, qui n'existait
        // sur aucune carte et qui portait à lui seul la frappe à distance du
        // répertoire.
        'sorts' => [
            'Tempête de feu',
            'Éclair de Chaos',
            'Frayeur',
            'Fuite',
        ],
    ],

    'chaman_orque' => [
        'porteur' => 'Chamane Gobelin',
        // Meneur de guerre : commande les héros, terrifie, endort, appelle les
        // siens. Le Chamane Gobelin étant sous-boss, il n'obtient en jeu que
        // Frayeur, Sommeil et Canaliser l'Effroi — la démonstration vivante du
        // filtre par palier.
        'sorts' => [
            'Commandement',
            'Frayeur',
            'Sommeil',
            'Invocation d\'orques',
            'Canaliser l\'Effroi',
        ],
    ],

];
