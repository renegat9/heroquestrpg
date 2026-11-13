<?php

declare(strict_types=1);

use App\Models\Monstre;
use App\Models\Personnage;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/*
 * MODE ARÈNE — la FICHE d'un héros d'arène ENGAGÉ dans un combat (validation de la PR #2, 2026-11-12). Le héros source
 * n'a pas d'état de quête : c'est sa DOUBLURE qui combat. Ses capacités doivent donc se lire sur la doublure en quête,
 * sinon le roster disait « Utilisable en quête seulement » pendant que la doublure les jouait.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    $this->joueur = connecterJoueur();
});

it('pendant un combat, Inébranlable a le MÊME statut sur le roster et sur la doublure qui la joue', function () {
    $chevalier = Personnage::findOrFail(test()->postJson('/api/personnages', ['nom' => 'Gauvain', 'classe' => 'chevalier', 'mode' => 'arene'])->json('personnage.id'));
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $doublure = $arenes->engager($arene, $chevalier);
    app(DemarreurArene::class)->demarrer($arene, [(int) Monstre::where('nom_base', 'Gobelin')->value('id')]);

    $joueur = $this->getJson('/api/moi')->json('joueur');
    $roster = collect($joueur['personnages'])->firstWhere('id', $chevalier->id);
    $doublureFiche = collect($joueur['doublures'])->firstWhere('id', $doublure->id);

    expect($roster)->not->toBeNull()
        ->and($doublureFiche)->not->toBeNull();

    // Statut et raison, lus tels que la fiche les publie : la décision, pas ses ingrédients.
    $decision = fn (array $heros) => array_intersect_key(
        collect($heros['competences'])->firstWhere('nom', 'Inébranlable') ?? [],
        array_flip(['statut', 'raison']),
    );

    expect($decision($doublureFiche))->toHaveKey('statut')
        ->and($decision($roster))->toBe($decision($doublureFiche))
        ->and($decision($roster)['raison'] ?? 'absente')->not->toBe('Utilisable en quête seulement');
});
