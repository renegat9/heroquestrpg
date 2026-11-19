<?php

declare(strict_types=1);

use App\Engine\ReactionEffet;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LES MODES DE MORT (lot K6, René 2026-11-19 — *Jungles of Delthrak* F9907 p. 8-9) : STANDARD, la règle de base (« you
 * die when your Body Points are reduced to 0, and you do not have a healing spell or healing potion to save yourself »,
 * G0978) ; STORY, où un groupe entièrement à terre meurt (« If all heroes are incapacitated, then they've died »).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

/** Un groupe de deux héros, dans le mode demandé, en quête. */
function partieDeMort(object $t, string $mode): array
{
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    $alice = connecterJoueur('alice');
    $fiche = fn (string $nom) => Personnage::create([
        'joueur_id' => $alice->id, 'nom' => $nom, 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $a = $fiche('Albrecht');
    $b = $fiche('Brunehaut');
    $code = $t->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $a->id, 'mode_mort' => $mode])
        ->assertCreated()->assertJsonPath('groupe.mode_mort', $mode)->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $t->postJson("/api/groupes/{$code}/rejoindre", ['personnage_id' => $b->id]);
    $groupe->personnages()->syncWithoutDetaching([$b->id => ['actif' => true, 'ordre_initiative' => 2]]);
    $t->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    return compact('groupe', 'quete', 'a', 'b');
}

function terrasser(Quete $quete, Personnage $heros, ?array $reaction = null): void
{
    $heros->update(['pv_body' => 0]);
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail()
        ->update(['tombe' => true, 'reaction_en_attente' => $reaction]);
}

it('STANDARD : à terre sans s\'être sauvé lui-même, il meurt ; un compagnon debout n\'y change rien', function () {
    ['groupe' => $groupe, 'quete' => $quete, 'a' => $a, 'b' => $b] = partieDeMort($this, Groupe::MORT_STANDARD);
    expect($quete->etatsPersonnages()->count())->toBe(2);
    terrasser($quete, $a);

    expect(app(ResolveurTour::class)->verdictDeChute($groupe, $quete->refresh()))->toBe(ResolveurTour::CHUTE_DEBOUT)
        ->and($a->refresh()->mort_le)->not->toBeNull()
        ->and($b->refresh()->mort_le)->toBeNull()
        ->and($quete->etatsPersonnages()->where('personnage_id', $a->id)->exists())->toBeFalse();
});

it('STANDARD : « Rester debout » encore offert — on attend sa réponse, il ne meurt pas encore', function () {
    ['groupe' => $groupe, 'quete' => $quete, 'a' => $a] = partieDeMort($this, Groupe::MORT_STANDARD);
    terrasser($quete, $a, ['action' => ReactionEffet::SOIN_URGENCE, 'nom' => 'Rester debout']);

    app(ResolveurTour::class)->verdictDeChute($groupe, $quete->refresh());

    expect($a->refresh()->mort_le)->toBeNull();
});

it('STORY : un héros à terre ne meurt pas ; TOUT le groupe à terre, tous meurent', function () {
    ['groupe' => $groupe, 'quete' => $quete, 'a' => $a, 'b' => $b] = partieDeMort($this, Groupe::MORT_STORY);
    terrasser($quete, $a);
    expect(app(ResolveurTour::class)->verdictDeChute($groupe, $quete->refresh()))->toBe(ResolveurTour::CHUTE_DEBOUT)
        ->and($a->refresh()->mort_le)->toBeNull();

    terrasser($quete, $b);
    expect(app(ResolveurTour::class)->verdictDeChute($groupe, $quete->refresh()))->toBe(ResolveurTour::CHUTE_TPK)
        ->and($a->refresh()->mort_le)->not->toBeNull()
        ->and($b->refresh()->mort_le)->not->toBeNull();
});

it('refuse un mode de mort inconnu', function () {
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    $alice = connecterJoueur('alice');
    $a = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $this->postJson('/api/classique', ['nom' => 'X', 'campagne' => 'campagne-d-essai', 'personnage_id' => $a->id, 'mode_mort' => 'immortel'])
        ->assertStatus(422)->assertJsonValidationErrors('mode_mort');
});
