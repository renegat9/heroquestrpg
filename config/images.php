<?php

/*
|--------------------------------------------------------------------------
| Illustrations du jeu (Gemini image) — style + gabarits de prompt
|--------------------------------------------------------------------------
| Sert à la pré-génération hors-ligne du catalogue (php artisan images:generer)
| et aux jobs dynamiques (boss/scène/hub/portrait). Un préfixe de STYLE commun
| assure la cohérence artistique ; chaque gabarit interpole les champs de
| l'entité ({nom}, {detail}, {categorie}, {tier}, {description}, {intro}…).
| Sans clé/asset, le front retombe sur les icônes — ces prompts ne sont jamais
| nécessaires au jeu.
*/

return [

    // Préfixe de style ajouté à TOUS les prompts (cohérence visuelle).
    'style' => 'Illustration de jeu de plateau dark-fantasy heroic, peinture numérique '
        .'détaillée, éclairage de torche dramatique, fond sombre neutre, sujet centré, '
        .'sans texte, sans bordure, sans filigrane.',

    // Gabarits par type. {style} est remplacé par la valeur ci-dessus.
    'gabarits' => [
        'classe' => 'Portrait héroïque en buste d\'un {detail}, personnage de jeu de rôle fantasy. {style}',
        'monstre' => 'Figurine de monstre de donjon : {nom} (tier {tier}), créature menaçante en pied. {style}',
        'objet' => 'Icône d\'inventaire : {nom}, un objet de catégorie « {categorie} », objet seul présenté sur fond sombre. {style}',
        'piege' => 'Piège de donjon : {nom}, mécanisme dangereux dans un couloir de pierre. {style}',

        // Dynamiques (jobs) :
        'boss' => 'Boss de donjon imposant et terrifiant : {nom}. {description} {style}',
        'scene' => 'Illustration d\'ambiance, scène d\'ouverture d\'une quête de donjon fantasy : {intro} {style}',
        'hub' => 'Lieu de repos des aventuriers entre deux quêtes (campement / salle commune chaleureuse), ambiance de répit. {premisse} {style}',
        'portrait' => 'Portrait héroïque en buste de « {nom} », un {detail}, personnage unique de jeu de rôle fantasy. {style}',
    ],

    // Détail d'apparence par classe (enrichit {detail} pour classe/portrait).
    'classes' => [
        'barbare' => 'barbare musclé en fourrures avec une grande hache',
        'nain' => 'nain robuste à la barbe tressée, en armure, avec un marteau de guerre',
        'elfe' => 'elfe agile aux traits fins, avec un arc',
        'magicien' => 'magicien en longue robe, tenant un bâton, entouré d\'une aura arcanique',
        'magicienne' => 'magicienne en longue robe, tenant un bâton, entourée d\'une aura arcanique',
    ],
];
