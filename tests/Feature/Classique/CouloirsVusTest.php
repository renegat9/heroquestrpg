<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CouloirsVus;
use App\Partie\MoteurPortes;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LE BROUILLARD DES COULOIRS d'une carte construite (René, 2026-11-17 : « respecter le plateau »). Un couloir se dévoile
 * à VUE ; ouvrir une porte ne révèle plus tous les couloirs d'un coup. Carte de `DocumentExemple`, INVENTÉE : la porte 1,
 * au nord d'`entree` en (3, 2), donne sur le couloir de la rangée y = 1 ; (15, 1) est au bout de cette rangée.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

it('EN JEU : la porte ouverte montre ce qu\'on voit par elle, pas tout le couloir ; un pas dans le couloir montre la rangée', function () {
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
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    expect($quete->refresh()->couloirs_vus)->toBeArray(); // la quête joue ce brouillard

    $visible = fn (int $x, int $y): bool => ($this->getJson("/api/groupes/{$code}/etat")->json("carte.cases.{$y}.{$x}") ?? 'b') !== 'b';

    // La porte nord ouverte, le héros sur le seuil intérieur (3, 2) : il voit (3, 1) par la porte, pas le bout de la rangée.
    $index = collect($quete->carte->grille['portes'])->search(fn ($p) => (int) $p['x'] === 3 && in_array([(int) $p['y'], $p['cote']], [[1, 's'], [2, 'n']], true));
    app(MoteurPortes::class)->ouvrir($groupe, $quete->carte, (int) $index, 'test');
    $quete->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 2]);
    app(CouloirsVus::class)->mettreAJour($quete->refresh());

    expect($visible(3, 1))->toBeTrue()
        ->and($visible(15, 1))->toBeFalse();

    // Un pas dans le couloir : toute la rangée y = 1 est en ligne de vue, et le reste vu.
    $quete->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 1]);
    app(CouloirsVus::class)->mettreAJour($quete->refresh());
    expect($visible(15, 1))->toBeTrue();

    $quete->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 2]);
    app(CouloirsVus::class)->mettreAJour($quete->refresh());
    expect($visible(15, 1))->toBeTrue(); // vu une fois, il le reste
});

it('une quête sans la colonne (démarrée avant, ou générée) garde l\'ancien brouillard', function () {
    $quete = new Quete(['couloirs_vus' => null]);

    expect(app(CouloirsVus::class)->vus($quete))->toBeNull();
});
