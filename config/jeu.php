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

        // PV des BOSS/SOUS-BOSS adaptés à la TAILLE du groupe (au lieu d'un 10 fixe
        // qui punissait les petits groupes) : pv = pv_catalogue × nb_héros /
        // taille_reference, plancher à 40 %. La piétaille, elle, reste régulée par
        // le budget. `taille_reference` = groupe pour lequel les PV catalogue valent.
        'boss_pv_adaptatif' => (bool) env('JEU_BOSS_PV_ADAPTATIF', true),
        'taille_reference' => (int) env('JEU_TAILLE_REFERENCE', 4),

        // Facteurs de jalon appliqués au budget de rencontre (sous-boss / boss
        // final ont plus de monstres autour d'eux). Une RAMPE adoucit le boss
        // pour un groupe débutant : à niveau moyen 1 on part de `jalon_boss_debut`
        // (moins de serviteurs autour du boss — cas d'une campagne « très courte »
        // qui jette un boss à des héros niveau 1), et on monte vers le facteur
        // plein à partir d'un niveau moyen `jalon_boss_niveau_plein`.
        'jalon_sous_boss' => (float) env('JEU_JALON_SOUS_BOSS', 1.25),
        'jalon_boss_final' => (float) env('JEU_JALON_BOSS_FINAL', 1.5),
        'jalon_boss_debut' => (float) env('JEU_JALON_BOSS_DEBUT', 1.1),
        'jalon_boss_niveau_plein' => (int) env('JEU_JALON_BOSS_NIVEAU_PLEIN', 3),
    ],

    /*
     * Relevage d'un allié tombé (doc 03 §48, correctifs §3) : un héros relevé à
     * 1 PV retombait au moindre coup — boucle « relevé/retombe » stérile. Il se
     * relève désormais à une FRACTION de ses PV max (plancher 1 PV), pour tenir
     * au moins un échange. Valeur de départ à régler en playtest.
     */
    'relevage' => [
        'fraction_pv' => (float) env('JEU_RELEVAGE_FRACTION_PV', 0.5), // 0..1 des PV Body max
        'pv_min' => (int) env('JEU_RELEVAGE_PV_MIN', 1),
    ],

];
