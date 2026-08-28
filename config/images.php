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
        // ÉPREUVE (2026-08-24) : un élément de décor auquel on se MESURE, à
        // distinguer du piège (danger subi) comme du meuble (obstacle) — d'où
        // l'insistance sur l'énigme et l'usure plutôt que sur la menace.
        'epreuve' => 'Élément de donjon à examiner : {nom}. {description} Détail énigmatique et usé par le temps, sculpté dans la pierre. {style}',
        // MOBILIER (2026-08-27) : les huit pièces n'avaient AUCUNE illustration
        // — ni gabarit, ni génération, ni `image_url` —, alors que pièges et
        // épreuves en ont depuis leur arrivée. Une pièce se FOUILLE et se
        // FRACASSE : c'est un élément de jeu, pas un décor. Le prompt insiste
        // sur la vue de dessus et l'emprise, parce qu'un meuble se lit sur la
        // carte à plat, contrairement à un piège cadré en couloir.
        'mobilier' => 'Meuble de donjon vu de dessus : {nom}. {description} Pièce isolée sur fond sombre, occupant {largeur} sur {hauteur} cases. {style}',
        'sort' => 'Illustration de sort de magie : {nom}, magie élémentaire ({element}), effet {type} spectaculaire, symbole arcanique lumineux. {style}',

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
        // Les 8 classes d'extension. Les descriptions suivent l'illustration
        // de leur carte officielle (numérisation du 2026-08-11).
        'barde' => 'barde orc à la peau verte, chapeau à plume violet, luth dans le dos et rapière à la ceinture',
        'druide' => 'druide, femme aux cheveux tressés et couronne de fleurs, bâton de bois noueux, vêtue de cuir et de feuilles',
        'warlock' => 'sorcière halfeline frêle, flamme verte spectrale flottant à sa main, longue robe en lambeaux',
        'rogue' => 'voleur elfe blond en manteau à col de fourrure, dague et épée courte, silhouette agile',
        'moine' => 'moine en tunique de lin, mains nues en garde, sans armure, posture de combat',
        'chevalier' => 'chevalier en armure de plates bleue et rouge, grand bouclier au lion doré, cape',
        'berserker' => 'berserker torse nu sous des fourrures, hache à deux mains levée, hurlant',
        'explorateur' => 'exploratrice encapuchonnée de jaune, sac de voyage, piolet à la main, lampe à la ceinture',
    ],
];
