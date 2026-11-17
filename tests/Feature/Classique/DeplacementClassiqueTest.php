<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LE DÉPLACEMENT DU MODE CLASSIQUE : DEUX DÉS ROUGES, SANS BASE (décision Q1 de `docs/plan-mode-classique.md`, jamais
 * codée jusqu'au 2026-11-17 — relevé par René : « ne confonds pas le mode RPG et le mode classique »). Le mode RPG garde
 * « base de classe + 1d6 ». Plateau et quête de `DocumentExemple`, INVENTÉS.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

it('EN JEU : un héros du mode classique lance deux dés et n\'a pas de base', function () {
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    expect($groupe->socleDeDeplacement($heros))->toBe(['base' => 0, 'des' => 2]);

    desFiges([3, 5, 1, 1, 1, 1, 1, 1]);
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $option = collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'))->firstWhere('id', 'se_deplacer');

    $etat = $quete->etatsPersonnages()->firstOrFail();
    expect($etat->detail_deplacement_tour['base'])->toBe(0)
        ->and($etat->detail_deplacement_tour['des'])->toHaveCount(2)
        ->and((int) $etat->deplacement_tour)->toBe(array_sum($etat->detail_deplacement_tour['des']))
        ->and($option['parametres']['base'])->toBe(0)
        ->and($option['parametres']['des'])->toHaveCount(2);
});

it('le mode RPG garde la base de la classe et un seul d6', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe('table-campagne');
    $heros = creerHeros($alice, $groupe, 'Bertrand', 1);

    expect($groupe->socleDeDeplacement($heros))->toBe(['base' => (int) $heros->deplacement_base, 'des' => 1]);
});

it('la scène de la table ne barre que le dé décidé, et ne nomme pas une base nulle', function () {
    $alice = connecterJoueur('alice');
    $heros = creerHeros($alice, creerGroupe('table-campagne'), 'Albrecht', 1);
    $scene = app(\App\Partie\SceneDeTable::class)->deplacement($heros, [
        'base' => 0, 'des' => [3, 5], 'bonus_equipement' => 0, 'de_annule' => true, 'de_annule_par' => 'Armure de plates',
        'des_annules' => [false, true], 'sans_menace' => false, 'total_jet' => 3, 'multiplicateur' => 1, 'bonus_potion' => 0, 'portee' => 3,
    ]);

    expect($scene['deplacement']['des_annules'])->toBe([false, true])
        ->and($scene['deplacement']['calcul'])->toBe('dé 3 (Armure de plates : un dé ne compte pas) = 3');
});
