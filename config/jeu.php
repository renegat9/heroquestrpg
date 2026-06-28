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

];
