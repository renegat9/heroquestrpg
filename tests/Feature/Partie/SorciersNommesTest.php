<?php

declare(strict_types=1);

use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Models\SortDread;
use App\Partie\DemarreurQuete;
use App\Partie\MoteurDread;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Sorciers ennemis nommés à deck dédié (Phase 2, 3.8 — doc 09 §4).
 * Un monstre avec `archetype_lanceur` résout le RÉPERTOIRE COMPLET de l'archétype
 * (config/archetypes_lanceurs.php) plutôt que sa liste `sorts_dread` propre.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Noms de sorts disponibles pour une instance (méthode privée → réflexion). */
function repertoireDe(string $nomMonstre): array
{
    $monstre = Monstre::where('nom_base', $nomMonstre)->firstOrFail();

    $instance = new InstanceMonstre(['elite' => false]);
    $instance->setRelation('monstre', $monstre);

    $moteur = app(MoteurDread::class);
    $methode = new ReflectionMethod($moteur, 'sortsDisponibles');
    $methode->setAccessible(true);

    /** @var \Illuminate\Support\Collection<int, SortDread> $sorts */
    $sorts = $methode->invoke($moteur, $instance, new Quete());

    return $sorts->pluck('nom')->all();
}

it('résout le répertoire complet de l\'archétype pour un lanceur nommé', function () {
    $sorts = repertoireDe('Sorcier des Tempêtes'); // archétype maitre_tempetes

    expect($sorts)
        ->toContain('Tempête de feu')
        ->toContain('Trait de Chaos')
        ->toContain('Fuite')
        // L'invocation appartient au Nécromancien, pas au Maître des tempêtes.
        ->not->toContain('Invocation de morts-vivants');
});

it('donne au Nécromancien son propre répertoire (invocation + contrôle)', function () {
    $sorts = repertoireDe('Liche'); // archétype necromancien

    expect($sorts)
        ->toContain('Invocation de morts-vivants')
        ->toContain('Sommeil')
        ->not->toContain('Tempête de feu');
});

it('retombe sur la liste sorts_dread propre quand aucun archétype n\'est défini', function () {
    // Seigneur (catalogue de base) n'a pas d'archétype : il garde sa liste propre.
    $sorts = repertoireDe('Seigneur');

    expect($sorts)
        ->toContain('Invocation de morts-vivants')
        ->toContain('Fuite');
});

it('assigne le lanceur nommé demandé comme rencontre finale (indice de gabarit)', function () {
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    // Gabarit demandant un boss « necromancien » → la Liche, pas le Seigneur.
    $achats = $methode->invoke(
        $demarreur,
        ['rencontre_finale' => ['tier' => 'boss', 'archetype' => 'necromancien']],
        30,
        5,
        1, // positionArc
    );
    expect($achats[0]->nom_base)->toBe('Liche');

    // Sans indice d'archétype : leader de coût du tier (Seigneur, comportement d'origine).
    $achatsDefaut = $methode->invoke(
        $demarreur,
        ['rencontre_finale' => ['tier' => 'boss']],
        30,
        5,
        1, // positionArc
    );
    expect($achatsDefaut[0]->nom_base)->toBe('Seigneur');
});

it('fait lancer en jeu un sort du répertoire de l\'archétype (champ sorts_dread vide)', function () {
    // Le Sorcier des Tempêtes a sorts_dread = [] mais l'archétype maitre_tempetes
    // doit lui ouvrir son répertoire : il lance un de ses sorts au tour des monstres.
    $ctx = demarrerQueteAvecMonstre('Sorcier des Tempêtes', ['attribut_mind' => 1, 'pv_mind' => 1, 'pv_mind_max' => 1]);

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $sortLance = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'sort_dread');

    $repertoire = config('archetypes_lanceurs.maitre_tempetes.sorts');

    expect($sortLance)->not->toBeNull()
        ->and($sortLance['sort'])->toBeIn($repertoire)
        ->and($sortLance['sort'])->not->toBe('Invocation de morts-vivants');
});

it('garantit que chaque sort d\'archétype existe dans le catalogue SortDread', function () {
    $catalogue = SortDread::pluck('nom')->all();

    foreach (config('archetypes_lanceurs') as $archetype) {
        foreach ($archetype['sorts'] as $nom) {
            expect($catalogue)->toContain($nom);
        }
    }
});
