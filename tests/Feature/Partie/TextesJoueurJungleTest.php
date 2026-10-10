<?php

declare(strict_types=1);

use App\Models\Quete;
use App\Models\Sort;
use App\Models\Terrain;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Verdict Jungle (2026-10-10) §3 — des textes que le joueur ne pouvait pas lire :
 * « Renforcé » (condition générique de TOUS les buffs), terrain « entravant »,
 * compétences de la fiche sans nom.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class,
        ObjetSeeder::class, MonstreSeeder::class, TerrainSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

it('deux buffs « Renforcé » se distinguent : chaque condition publie l\'effet PRÉCIS de sa source', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $moteur = app(MoteurSorts::class);
    $moteur->appliquerBuff($hero, Sort::where('nom', 'Courage')->firstOrFail());
    $moteur->appliquerBuff($hero, Sort::where('nom', 'Peau de Pierre')->firstOrFail());

    $etat = test()->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $conditions = collect($etat['entites'])->firstWhere('type', 'heros')['conditions'];

    $courage = collect($conditions)->firstWhere('source', 'sort:Courage');
    $peau = collect($conditions)->firstWhere('source', 'sort:Peau de Pierre');

    expect($courage['effet_source'])->toContain('attaque')
        ->and($peau['effet_source'])->toContain('défense')
        ->and($courage['effet_source'])->not->toBe($peau['effet_source']);

    // La description générique de « Renforcé » ne ment plus en parlant d'attaque seule.
    expect($courage['description'])->not->toStartWith("Bonus d'attaque")
        ->and($courage['description'])->toContain('défense');
});

it('le terrain « entravant » publie son texte joueur (carte.terrain[].avantages)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = $quete->etatsPersonnages()->firstOrFail();
    $sable = Terrain::where('nom', 'Sable entravant')->firstOrFail();

    $grille = $quete->carte->grille;
    $grille['terrain'] = [['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y, 'terrain_id' => $sable->id]];
    $quete->carte->update(['grille' => $grille]);

    $terrain = collect(test()->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.terrain'))->firstWhere('nom', 'Sable entravant');

    expect($terrain['avantages'])->not->toBe([])
        ->and(implode(' ', $terrain['avantages']))->toContain('gênant');
});

it('/moi publie les compétences AVEC leur nom et leur description', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $competence = \App\Models\Competence::query()->where('classe', 'barbare')->first()
        ?? \App\Models\Competence::query()->firstOrFail();
    $hero->competences()->syncWithoutDetaching([$competence->id]);

    $perso = collect(test()->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->firstWhere('nom', 'Albrecht');
    $entree = collect($perso['competences'])->firstWhere('id', $competence->id);

    expect($entree['nom'])->toBe($competence->nom)
        ->and(array_key_exists('description', $entree))->toBeTrue();
});
