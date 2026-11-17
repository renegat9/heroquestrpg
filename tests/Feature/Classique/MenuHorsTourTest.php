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
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * MENU HORS TOUR (relevé en partie d'agents, lot X1, 2026-11-17) : la naine avait fini son tour ; `GET /menu` lui rendait
 * encore ses options du tour (fouiller, se déplacer) pendant que c'était au barbare de jouer. Un menu servi hors tour
 * est un défaut déjà payé (CLAUDE.md, campagnes d'agents) : ce test le tient en mode classique, à deux joueurs.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

it('ne sert pas ses options de tour au héros qui a fini son tour pendant que l\'autre joue', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['prerequis_heros'] = [];
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $bruno = connecterJoueur('bruno');
    $creer = fn ($joueur, $nom, $classe) => Personnage::create([
        'joueur_id' => $joueur->id, 'nom' => $nom, 'classe' => $classe, 'niveau' => 1,
        'attribut_body' => 3, 'attribut_mind' => 3, 'pv_body_max' => 7, 'pv_body' => 7, 'pv_mind_max' => 3, 'pv_mind' => 3,
        'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $naine = $creer($bruno, 'Brunehaut', 'nain');
    $barbare = $creer($alice, 'Albrecht', 'barbare');

    $this->actingAs($bruno, 'joueur');
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $naine->id])
        ->assertCreated()->json('groupe.identifiant');
    $this->actingAs($alice, 'joueur')->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $barbare->id])->assertSuccessful();
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();

    // La naine joue la première : son menu, puis elle finit son tour.
    $this->actingAs($bruno, 'joueur');
    $menu = $this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu');
    expect(collect($menu['options'] ?? [])->pluck('id'))->toContain('attendre');
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'attendre'])->assertAccepted();

    // C'est au barbare. Le menu de la naine ne lui rend plus ses options du tour.
    $apres = $this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu');
    expect(collect($apres['options'] ?? [])->pluck('id')->intersect(['se_deplacer', 'fouiller', 'fouiller_tresor'])->all())->toBe([]);
});
