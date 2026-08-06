<?php

declare(strict_types=1);

use App\Models\Objet;
use App\Models\Sort;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;

/**
 * Les objets du catalogue sont-ils FONCTIONNELS ?
 *
 * Chaque clé de `objets.effet` est une promesse faite au joueur. Le projet a
 * déjà dû retirer `attaque_second_rang` (mécanisme inexistant) et `ligne_de_vue`
 * (clé sans lecteur) : ce sont des « clés décoratives », des règles annoncées
 * que le moteur n'applique pas.
 *
 * Ce fichier fige l'inventaire des clés en deux ensembles — celles qu'un moteur
 * lit, et celles qu'on sait INERTES et qu'on tolère. Ajouter une clé au seeder
 * casse donc le test tant qu'on n'a pas tranché : lui écrire un lecteur, ou la
 * déclarer inerte en connaissance de cause.
 */
// SortSeeder D'ABORD : les parchemins sont dérivés des sorts (ObjetSeeder
// boucle sur Sort::all()), l'ordre inverse n'en crée aucun.
beforeEach(function () {
    $this->seed([SortSeeder::class, ObjetSeeder::class]);
});

/**
 * Clés effectivement lues par le moteur (audit du 2026-08-05 : chacune a été
 * retrouvée dans app/, avec le fichier qui l'applique).
 */
const CLES_ACTIVES = [
    'attaque_diagonale',        // MenuMoteur / ResolveurTour (portée de l'attaque)
    'attaque_supplementaire',   // MoteurPotions (Potion d'héroïsme)
    'bonus_des_attaque',        // MoteurSorts::appliquerBuffPotion + bonusDes
    'bonus_des_defense',        // idem
    'condition_appliquee',      // MoteurSorts::appliquerBuffPotion
    'deplacement_sans_d6',      // Engine\Deplacement (Armure de plates)
    'des_attaque',              // Equipement (deltas sur les colonnes du héros)
    'des_defense',              // idem
    'deux_mains',               // Equipement (interdit le bouclier)
    'incompatible_deux_mains',  // Equipement (le bouclier lui-même)
    'inutilisable_adjacent',    // ResolveurTour (arbalète au contact)
    'jetable',                  // MenuMoteur / ResolveurTour (lancer l'arme)
    'permet_desamorcage',       // MoteurPieges (Trousse à outils)
    'portee',                   // MenuMoteur / ResolveurTour (arme à distance)
    'retire_condition',         // MoteurPotions (Antidote)
    'soin_pv_body',             // MoteurPotions (montant fixe)
    'soin_pv_body_de',          // MoteurPotions (1d6 — Fiole de soin)
    'soin_pv_mind',             // MoteurPotions
    'sort_id',                  // ResolveurTour::resoudreParchemin
];

/**
 * Clés SANS lecteur, tolérées en connaissance de cause (décision de René,
 * 2026-08-05 : « si ça ne cause pas de problème on peut le laisser »).
 */
const CLES_INERTES = [
    // Les potions portent `duree`, mais appliquerBuffPotion lit `duree_tours`
    // qu'aucun objet ne porte : le buff est consommé à la prochaine attaque,
    // pas à l'expiration d'un compte de tours. Sans effet de bord — et masquée
    // au guide, qui affichait « Durée : 0 ».
    'duree',
    // Libellé de confort sur les parchemins : le nom du sort double celui de la
    // pièce. Masqué au guide pour la même raison.
    'sort_nom',
    // Copie REDONDANTE de sorts.difficulte_parchemin, qui est l'autorité (voir
    // le test de synchronisation juste en dessous).
    'difficulte_non_lanceur',
];

it('n\'introduit aucune clé d\'effet inconnue dans le catalogue', function () {
    $cles = collect(Objet::all())
        ->flatMap(fn (Objet $o) => array_keys((array) $o->effet))
        ->unique()
        ->sort()
        ->values()
        ->all();

    $connues = collect(CLES_ACTIVES)->merge(CLES_INERTES)->sort()->values()->all();
    $inconnues = array_values(array_diff($cles, $connues));

    expect($inconnues)->toBe([], implode(', ', $inconnues)
        .' — clé(s) d\'effet sans lecteur connu. Écris-lui un lecteur dans le moteur, '
        .'ou ajoute-la à CLES_INERTES si elle est décorative en connaissance de cause.');
});

it('garde la difficulté des parchemins synchronisée avec celle du sort', function () {
    // `objets.effet.difficulte_non_lanceur` est inerte : ResolveurTour roule
    // contre `sorts.difficulte_parchemin`. La copie est aujourd'hui exacte, mais
    // rien ne l'y oblige — si elle dérive, le guide documente une difficulté que
    // le jeu ne lance pas.
    $parchemins = Objet::where('categorie', 'parchemin')->get();
    expect($parchemins)->not->toBeEmpty();

    foreach ($parchemins as $parchemin) {
        $effet = (array) $parchemin->effet;
        $sort = Sort::find($effet['sort_id'] ?? 0);

        expect($sort)->not->toBeNull("{$parchemin->nom} : sort_id introuvable.")
            ->and((int) ($effet['difficulte_non_lanceur'] ?? -1))
            ->toBe((int) $sort->difficulte_parchemin, "{$parchemin->nom} : difficulté affichée ≠ difficulté lancée.");
    }
});

it('donne à toute arme et armure des dés, et à tout consommable un effet réel', function () {
    foreach (Objet::whereIn('categorie', ['arme', 'armure'])->get() as $piece) {
        $effet = (array) $piece->effet;

        expect(($effet['des_attaque'] ?? 0) + ($effet['des_defense'] ?? 0))
            ->toBeGreaterThan(0, "{$piece->nom} : ne donne aucun dé, la porter ne change rien.");
    }

    // Un consommable doit soigner, retirer une condition, ou poser un buff —
    // sans quoi le boire est un clic pour rien.
    $utiles = ['soin_pv_body', 'soin_pv_body_de', 'soin_pv_mind', 'retire_condition',
        'bonus_des_attaque', 'bonus_des_defense', 'attaque_supplementaire'];

    foreach (Objet::where('categorie', 'consommable')->get() as $potion) {
        expect(array_intersect($utiles, array_keys((array) $potion->effet)))
            ->not->toBeEmpty("{$potion->nom} : aucun effet que MoteurPotions sache appliquer.");
    }
});
