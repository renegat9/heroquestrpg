<?php

/**
 * Sorciers ennemis nommés (Phase 2, 3.8 — doc 09 §4).
 *
 * Au-delà des sorts de Dread génériques d'un boss/sous-boss, un « lanceur nommé »
 * possède un RÉPERTOIRE COMPLET et thématique de sorts (Nécromancien, Maître des
 * tempêtes, Chaman orque…), assigné en bloc plutôt qu'une pioche partagée.
 *
 * Le moteur (MoteurDread) résout le répertoire de l'archétype quand
 * `monstres.archetype_lanceur` vaut la CLÉ d'une entrée ci-dessous ; l'IA n'habille
 * que le nom/l'apparence (Q6), jamais les effets. Les noms de `sorts` DOIVENT
 * exister dans le catalogue SortDread (SortDreadSeeder).
 *
 * `label` = nom neutre de repli (sans IA) ; `voix` = profil de barks (config/barks.php).
 */
return [

    'necromancien' => [
        'label' => 'Nécromancien',
        'voix' => 'sorcier',
        // Mort-vivants + contrôle : invoque, endort, terrifie, frappe à distance.
        'sorts' => [
            'Invocation de morts-vivants',
            'Sommeil',
            'Frayeur',
            'Trait de Chaos',
        ],
    ],

    'maitre_tempetes' => [
        'label' => 'Maître des tempêtes',
        'voix' => 'sorcier',
        // Dégâts de zone + esquive : foudroie, terrifie, se dérobe.
        'sorts' => [
            'Tempête de feu',
            'Trait de Chaos',
            'Frayeur',
            'Fuite',
        ],
    ],

    'chaman_orque' => [
        'label' => 'Chaman orque',
        'voix' => 'orque',
        // Meneur de guerre : commande les héros, terrifie, endort, frappe.
        'sorts' => [
            'Commandement',
            'Frayeur',
            'Sommeil',
            'Trait de Chaos',
        ],
    ],

];
