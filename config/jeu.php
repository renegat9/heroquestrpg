<?php

/**
 * Réglages de jeu Phase 2 — valeurs de départ à régler en playtest (doc 06 §10).
 * Tout est paramétré ici pour rester ajustable sans toucher au moteur.
 */
return [

    /*
     * Variance « élite » (3.6) : à l'apparition, un monstre de BASE peut devenir
     * élite (+1 attaque / +1 défense / +1 PV Body). Désactivée en test pour
     * garder les scénarios déterministes ; un d6 >= seuil rend le monstre élite.
     */
    'elite' => [
        'actif' => env('JEU_ELITE_ACTIF', true),
        'seuil_d6' => env('JEU_ELITE_SEUIL_D6', 6), // 6 ≈ 1/6 des monstres de base
    ],

    /*
     * Composition des rencontres (doc 06 §2) — parti pris de playtest :
     * « BEAUCOUP d'ennemis FAIBLES + QUELQUES ennemis FORTS », au lieu de remplir
     * le budget avec les monstres de base les plus chers (2 Gargouilles contre des
     * héros niveau 1). Le budget achète d'abord quelques « forts » (haut du tier
     * base), puis remplit la masse avec des « faibles » (bas du tier) pour
     * maximiser le nombre d'ennemis peu dangereux.
     */
    'rencontres' => [
        // Nombre de monstres FORTS semés par quête, EN PLUS de la rencontre finale.
        'forts_par_quete' => (int) env('JEU_FORTS_PAR_QUETE', 1),
        // + 1 fort tous les N crans d'arc (escalade douce ; 0 = pas d'escalade).
        'forts_escalade_arc' => (int) env('JEU_FORTS_ESCALADE_ARC', 2),
        // Un monstre de base est « fort » si son coût dépasse ce seuil, « faible » sinon.
        // (Bestiaire de départ : faibles = Gobelin/Orque/Squelette/Zombie/Fimir ≤ 3 ;
        //  forts = Momie 4 / Guerrier du Chaos 5 / Gargouille 6.)
        'seuil_cout_fort' => (int) env('JEU_SEUIL_COUT_FORT', 3),
    ],

];
