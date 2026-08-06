<?php

declare(strict_types=1);

use App\Models\Objet;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;

/**
 * Les sorts et parchemins sont-ils FONCTIONNELS ?
 *
 * Même garde-fou que `ObjetsFonctionnelsTest` : chaque clé de `sorts.effet` est
 * une promesse faite au joueur. On fige l'inventaire en deux ensembles — celles
 * qu'un moteur lit, et celles qu'on sait INERTES — pour qu'ajouter une clé au
 * seeder force une décision : lui écrire un lecteur, ou la déclarer décorative.
 */
beforeEach(function () {
    $this->seed([SortSeeder::class, ObjetSeeder::class]);
});

/** Clés lues par le moteur (audit du 2026-08-06, fichier applicatif en regard). */
const CLES_SORT_ACTIVES = [
    'des_degats',            // ResolveurTour::sortDegats()
    'portee',                // ciblage à distance
    'soin_pv_body',          // sort utilitaire de soin
    'bonus_des_attaque',     // MoteurSorts::bonusDes()
    'bonus_des_defense',     // idem
    'condition_appliquee',   // appliquerConditionCatalogue()
    'duree',                 // DureeEffet / expirerBuffs()
    'deplacement_multiplie', // multiplicateurDeplacement()
    'franchit_mur',          // ResolveurTour, franchissement
    'empeche_attaque',       // condition posée sur le monstre
    'cible',                 // MoteurSorts::ciblesLegales()
];

/**
 * Clés SANS lecteur. Les trois premières DÉCRIVENT fidèlement ce que fait le
 * moteur ; les trois dernières annoncent une mécanique qui n'existe pas et
 * attendent un arbitrage (verdict 2026-08-05 §6 quater).
 */
const CLES_SORT_INERTES = [
    'defense_applicable',  // exact : la défense est TOUJOURS appliquée
    'resistance',          // exact : c'est `type = mental` qui déclenche SortMental
    'fin',                 // descriptif (le réveil est câblé dans reveillerHeros)
    'cout',                // ⚠ CONTREDIT le moteur : forfait COUT_FRANCHISSEMENT
    'invocation_ephemere', // ⚠ aucun mécanisme d'invocation n'existe
];

it('n\'introduit aucune clé d\'effet inconnue dans le catalogue de sorts', function () {
    $cles = collect(Sort::all())
        ->flatMap(fn (Sort $s) => array_keys((array) $s->effet))
        ->unique()->sort()->values()->all();

    $connues = collect(CLES_SORT_ACTIVES)->merge(CLES_SORT_INERTES)->all();
    $inconnues = array_values(array_diff($cles, $connues));

    expect($inconnues)->toBe([], implode(', ', $inconnues)
        .' — clé(s) de sort sans lecteur connu. Écris-lui un lecteur, ou ajoute-la '
        .'à CLES_SORT_INERTES en connaissance de cause.');
});

it('donne à chaque sort un effet mécanique que le moteur sait appliquer', function () {
    // Un sort qui ne fait ni dégâts, ni soin, ni condition, ni bonus, ni
    // déplacement est un sort qu'on lance pour rien.
    $agissantes = ['des_degats', 'soin_pv_body', 'condition_appliquee', 'bonus_des_attaque',
        'bonus_des_defense', 'deplacement_multiplie', 'franchit_mur', 'empeche_attaque'];

    foreach (Sort::all() as $sort) {
        expect(array_intersect($agissantes, array_keys((array) $sort->effet)))
            ->not->toBeEmpty("{$sort->nom} : aucun effet mécanique applicable.");
    }
});

it('n\'expose de sorts qu\'aux classes lanceuses', function () {
    expect(MoteurSorts::LANCEURS)->toBe(['magicien', 'elfe'])
        ->and(MoteurSorts::LANCEURS)->not->toContain('barbare')
        ->and(MoteurSorts::LANCEURS)->not->toContain('nain');
});

it('donne un parchemin par sort, chacun résoluble et de difficulté synchronisée', function () {
    $sorts = Sort::all();
    $parchemins = Objet::where('categorie', 'parchemin')->get();

    expect($parchemins)->toHaveCount($sorts->count());

    foreach ($parchemins as $parchemin) {
        $effet = (array) $parchemin->effet;
        $sort = Sort::find($effet['sort_id'] ?? 0);

        // `sort_id` est ce que lit resoudreParchemin() : sans lui, le parchemin
        // est irrésoluble et l'action est refusée en 422.
        expect($sort)->not->toBeNull("{$parchemin->nom} : sort_id introuvable.")
            ->and((int) ($effet['difficulte_non_lanceur'] ?? -1))
            ->toBe((int) $sort->difficulte_parchemin, "{$parchemin->nom} : difficulté affichée ≠ lancée.")
            ->and((int) $sort->difficulte_parchemin)->toBeGreaterThan(0)
            ->and((int) $sort->difficulte_parchemin)->toBeLessThanOrEqual(3);
    }
});
