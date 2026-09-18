<?php

declare(strict_types=1);

use App\Models\Competence;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Joueur;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\PersonnageHistorique;
use App\Models\Sort;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Suppression d'un personnage CRÉÉ PAR ERREUR (René, 2026-09-18 :
 * « on n'est pas en mesure de supprimer un personnage créé en erreur »).
 *
 * Contrat GELÉ : docs/contrat-api.md §« Suppression d'un personnage CRÉÉ PAR
 * ERREUR ». Trois gardes, DANS CET ORDRE :
 *  1. appartenance au joueur authentifié → 404 (n'expose jamais le héros
 *     d'un autre) ;
 *  2. `disponible` (`groupe_actif_id === null`) → 422 sinon ;
 *  3. jamais joué : ni `etat_personnage_quete` ni `personnage_historique` →
 *     422 sinon, nommant LEQUEL des deux a parlé. Le vétéran est EXCLU par
 *     construction (donnée de campagne protégée par la règle dure du projet).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Un personnage minimal, DISPONIBLE (`groupe_actif_id` null), créé
 * directement en base — sert aux scénarios où l'on doit contrôler l'état
 * exact (vétéran, trace de quête) sans dérouler tout le flux applicatif.
 */
function heroLibreSansApi(Joueur $joueur, string $nom): Personnage
{
    return Personnage::create([
        'joueur_id' => $joueur->id,
        'groupe_actif_id' => null,
        'nom' => $nom,
        'classe' => 'barbare',
        'niveau' => 1,
        'attribut_body' => 4,
        'attribut_mind' => 2,
        'pv_body_max' => 8,
        'pv_body' => 8,
        'pv_mind_max' => 2,
        'pv_mind' => 2,
        'des_attaque' => 3,
        'des_defense' => 2,
        'deplacement_base' => 4,
    ]);
}

it('supprime un personnage neuf : jamais engagé, jamais joué', function () {
    connecterJoueur('alice');

    $id = $this->postJson('/api/personnages', ['nom' => 'Erreur', 'classe' => 'barbare'])
        ->assertCreated()
        ->json('personnage.id');

    $this->deleteJson("/api/personnages/{$id}")->assertNoContent();

    expect(Personnage::find($id))->toBeNull();
});

it('404 sur le héros d\'un autre joueur — sans révéler qu\'il existe', function () {
    connecterJoueur('alice');
    $id = $this->postJson('/api/personnages', ['nom' => 'Pas à toi', 'classe' => 'barbare'])
        ->assertCreated()
        ->json('personnage.id');

    connecterJoueur('bob');
    $this->deleteJson("/api/personnages/{$id}")->assertNotFound();

    expect(Personnage::find($id))->not->toBeNull();
});

it('422 si le héros est engagé dans un groupe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Engagé', 1);

    $this->deleteJson("/api/personnages/{$heros->id}")
        ->assertStatus(422)
        ->assertJsonPath('errors.personnage.0', fn ($m) => str_contains((string) $m, 'groupe'));

    expect(Personnage::find($heros->id))->not->toBeNull();
});

it('422 si le héros est déjà entré en quête, même redevenu libre entre-temps', function () {
    ['heros' => $heros] = demarrerQueteAvecMonstre('Gobelin');

    // Redevenu « disponible » (groupe_actif_id null) SANS que sa trace de
    // quête disparaisse : c'est exactement le cas que la garde #3 doit
    // intercepter là où la garde #2 (disponible) laisserait passer.
    $heros->update(['groupe_actif_id' => null]);
    expect(EtatPersonnageQuete::where('personnage_id', $heros->id)->exists())->toBeTrue();

    $this->deleteJson("/api/personnages/{$heros->id}")
        ->assertStatus(422)
        ->assertJsonPath('errors.personnage.0', fn ($m) => str_contains((string) $m, 'quête'));

    expect(Personnage::find($heros->id))->not->toBeNull();
});

it('422 si le héros a un personnage_historique — le vétéran est EXCLU', function () {
    $alice = connecterJoueur('alice');
    $heros = heroLibreSansApi($alice, 'Vétéran');

    PersonnageHistorique::create([
        'personnage_id' => $heros->id,
        'groupe_nom' => 'Ancienne campagne',
        'theme' => 'Donjon classique',
        'resume' => 'Victoire contre le seigneur des ombres.',
        'issue' => 'victoire',
        'niveau_atteint' => 3,
        'termine_le' => now(),
    ]);

    $this->deleteJson("/api/personnages/{$heros->id}")
        ->assertStatus(422)
        ->assertJsonPath('errors.personnage.0', fn ($m) => str_contains((string) $m, 'campagne'));

    expect(Personnage::find($heros->id))->not->toBeNull();
});

it('supprime TOUTES les annexes avec la ligne — aucune ligne orpheline', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'À nettoyer', 1);

    // Les CINQ annexes du contrat, toutes non vides pour ce héros.
    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::query()->firstOrFail()->id,
        'emplacement' => 'sac',
    ]);
    donnerTalent($heros, Competence::where('classe', 'barbare')->whereNull('prerequis_id')->firstOrFail()->nom);
    $heros->sorts()->attach(Sort::query()->firstOrFail()->id, ['disponible' => true]);
    $heros->conditions()->attach(Condition::query()->firstOrFail()->id, ['duree' => 2]);

    // Redevenu libre SANS que la pivot `groupe_personnages` ait été détachée
    // (cas le plus dur : rejoint puis quitté avant toute quête, la ligne
    // pivot d'un groupe encore vivant survit à ce départ).
    $heros->update(['groupe_actif_id' => null]);

    $id = $heros->id;
    expect(DB::table('groupe_personnages')->where('personnage_id', $id)->count())->toBeGreaterThan(0)
        ->and(DB::table('inventaire')->where('personnage_id', $id)->count())->toBeGreaterThan(0)
        ->and(DB::table('personnage_competences')->where('personnage_id', $id)->count())->toBeGreaterThan(0)
        ->and(DB::table('personnage_sorts')->where('personnage_id', $id)->count())->toBeGreaterThan(0)
        ->and(DB::table('personnage_conditions')->where('personnage_id', $id)->count())->toBeGreaterThan(0);

    $this->deleteJson("/api/personnages/{$id}")->assertNoContent();

    expect(Personnage::find($id))->toBeNull()
        ->and(DB::table('groupe_personnages')->where('personnage_id', $id)->count())->toBe(0)
        ->and(DB::table('inventaire')->where('personnage_id', $id)->count())->toBe(0)
        ->and(DB::table('personnage_competences')->where('personnage_id', $id)->count())->toBe(0)
        ->and(DB::table('personnage_sorts')->where('personnage_id', $id)->count())->toBe(0)
        ->and(DB::table('personnage_conditions')->where('personnage_id', $id)->count())->toBe(0);
});

it('/moi publie `supprimable` cohérent avec ce que la route accepte — dans les deux sens', function () {
    // « joué » : démarre la quête (crée alice + son groupe + son héros), puis
    // redevient libre sans que la trace de quête ne parte avec.
    ['alice' => $alice, 'heros' => $joue] = demarrerQueteAvecMonstre('Gobelin');
    $joue->update(['groupe_actif_id' => null]);

    $engage = creerHeros($alice, creerGroupe('table-2'), 'Engagé', 2);

    $libre = heroLibreSansApi($alice, 'Libre');

    $veteran = heroLibreSansApi($alice, 'Vétéran');
    PersonnageHistorique::create([
        'personnage_id' => $veteran->id,
        'groupe_nom' => 'Ancienne campagne',
        'theme' => 'Donjon classique',
        'resume' => 'Victoire.',
        'issue' => 'victoire',
        'niveau_atteint' => 2,
        'termine_le' => now(),
    ]);

    $moi = $this->getJson('/api/moi')->assertOk()->json('joueur.personnages');
    $supprimableDe = fn (string $nom) => collect($moi)->firstWhere('nom', $nom)['supprimable'];

    expect($supprimableDe($joue->nom))->toBeFalse()
        ->and($supprimableDe('Engagé'))->toBeFalse()
        ->and($supprimableDe('Libre'))->toBeTrue()
        ->and($supprimableDe('Vétéran'))->toBeFalse();

    // ET DANS L'AUTRE SENS : la route se comporte comme /moi l'a annoncé.
    $this->deleteJson("/api/personnages/{$joue->id}")->assertStatus(422);
    $this->deleteJson("/api/personnages/{$engage->id}")->assertStatus(422);
    $this->deleteJson("/api/personnages/{$veteran->id}")->assertStatus(422);
    $this->deleteJson("/api/personnages/{$libre->id}")->assertNoContent();
});
