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
     *
     * ⚠ Révisé le 2026-11-08 (René : « ajouter plus de monstres n'est pas nécessairement plus difficile car souvent
     * ils sont coincés entre des meubles ou le cadre de porte ») : la masse est PLAFONNÉE par salle
     * (`places_par_salle_en_plus_des_heros`), et le budget qu'elle ne peut plus absorber achète des forts. Le
     * compte ci-dessous devient un MINIMUM : à petit budget rien ne change, à gros budget les forts prennent la place.
     */
    'rencontres' => [
        // MINIMUM de monstres FORTS semés par quête, EN PLUS de la rencontre finale.
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

        // PLAFOND DE MONSTRES PAR SALLE (René, 2026-11-08) : au plus « héros + N » monstres posés dans une
        // salle. Au-delà, ils attendaient derrière ceux qui frappaient, coincés au seuil ou entre les meubles :
        // 42 % des monstres de nos 6 quêtes n'ont jamais frappé. Le budget qui ne trouve plus de place achète
        // des FORTS au lieu de faibles (`DemarreurQuete::acheterMonstres()`).
        'places_par_salle_en_plus_des_heros' => (int) env('JEU_PLACES_PAR_SALLE_EN_PLUS_DES_HEROS', 1),
    ],

    /*
     * Le MONSTRE ERRANT de la quête (`App\Partie\MonstreErrant`), de plus en plus fort au fil de l'arc
     * (René, 2026-11-08) : le monstre de base le plus cher dont le `cout` ne dépasse pas
     * cout_depart + (position d'arc − 1) × cout_par_quete — 2, 3, 4… comme l'Orc des quêtes 1 à 3 du livret
     * de base, puis l'Abomination et le Dread Warrior.
     */
    'errant' => [
        'cout_depart' => (int) env('JEU_ERRANT_COUT_DEPART', 2),
        'cout_par_quete' => (int) env('JEU_ERRANT_COUT_PAR_QUETE', 1),
    ],

    /*
     * Relevage d'un allié tombé (doc 03 §48, correctifs §3) : un héros relevé à
     * 1 PV retombait au moindre coup — boucle « relevé/retombe » stérile. Il se
     * relève désormais à une FRACTION de ses PV max (plancher 1 PV), pour tenir
     * au moins un échange. Valeur de départ à régler en playtest.
     */
    // `relevage` RETIRÉ (2026-08-06) : relever ramène désormais à 1 POINT, sur
    // la jauge tombée à zéro (Body ou Mind) — décision de René. La fraction des
    // PV max n'a plus de lecteur, et un réglage que personne ne lit est une
    // promesse faite au narrateur que le moteur ne tient pas.

];
