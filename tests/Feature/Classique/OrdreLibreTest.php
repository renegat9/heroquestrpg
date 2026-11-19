<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\OrdreDuTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * L'ORDRE LIBRE (lot K6, René 2026-11-19 — *Jungles of Delthrak* F9907 p. 8-9, « Order of Play » : « heroes may decide
 * the order in which they take their turns in a round »). À chaque round, tout héros debout qui n'a pas joué a la main,
 * jusqu'à ce que l'un d'eux entame son tour.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function partieOrdre(object $t, bool $libre): array
{
    $campagne = DocumentExemple::campagne();
    if ($libre) {
        $campagne['quetes'][0]['regles'] = ['ordre_libre' => true];
    }
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $fiche = fn (string $nom) => Personnage::create([
        'joueur_id' => $alice->id, 'nom' => $nom, 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $a = $fiche('Albrecht');
    $b = $fiche('Brunehaut');
    $code = $t->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $a->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $groupe->personnages()->syncWithoutDetaching([$b->id => ['actif' => true, 'ordre_initiative' => 2]]);
    $t->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    return compact('groupe', 'quete', 'a', 'b');
}

it('sans la règle, l\'ordre est fixe : seul le premier a la main', function () {
    ['groupe' => $groupe, 'a' => $a, 'b' => $b] = partieOrdre($this, false);
    $ordre = app(OrdreDuTour::class);

    expect($ordre->estSonTour($groupe->refresh(), (int) $a->id))->toBeTrue()
        ->and($ordre->estSonTour($groupe, (int) $b->id))->toBeFalse();
});

it('ordre libre : les deux ont la main ; celui qui entame la garde seul ; puis l\'autre la reprend', function () {
    ['groupe' => $groupe, 'quete' => $quete, 'a' => $a, 'b' => $b] = partieOrdre($this, true);
    $ordre = app(OrdreDuTour::class);
    $groupe->refresh();

    expect($ordre->estSonTour($groupe, (int) $a->id))->toBeTrue()
        ->and($ordre->estSonTour($groupe, (int) $b->id))->toBeTrue();

    // Brunehaut, deuxième dans l'ordre, entame son tour la première.
    $quete->etatsPersonnages()->where('personnage_id', $b->id)->update(['a_deplace' => true]);
    expect($ordre->estSonTour($groupe, (int) $b->id))->toBeTrue()
        ->and($ordre->estSonTour($groupe, (int) $a->id))->toBeFalse();

    $quete->etatsPersonnages()->where('personnage_id', $b->id)->update(['a_joue' => true]);
    expect($ordre->estSonTour($groupe, (int) $a->id))->toBeTrue()
        ->and($ordre->estSonTour($groupe, (int) $b->id))->toBeFalse();
});
