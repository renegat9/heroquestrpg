<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LA MORT EN MODE HEROIC (lot H1). Sources : *Jungles of Delthrak* (F9907 p. 8-9) pour le jeton crâne, livret de règles
 * de *First Light* (G0978, « Dead Heroes ») pour la mort définitive. Story reste la politique par défaut : un héros à
 * terre n'y meurt jamais.
 *
 * Plateau et quête : ceux de `DocumentExemple`, INVENTÉS ; ses monstres sont mis hors de combat d'emblée, pour que
 * seul le jeton crâne décide. Groupe : Albrecht (barbare, alice) et Brunehaut (naine, bruno).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function partieHeroic(object $test, string $modeMort = 'heroic'): array
{
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    $alice = connecterJoueur('alice');
    $bruno = connecterJoueur('bruno');
    $albrecht = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $brunehaut = Personnage::create([
        'joueur_id' => $bruno->id, 'nom' => 'Brunehaut', 'classe' => 'nain', 'niveau' => 1,
        'attribut_body' => 3, 'attribut_mind' => 3, 'pv_body_max' => 7, 'pv_body' => 7, 'pv_mind_max' => 3, 'pv_mind' => 3,
        'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $test->actingAs($alice, 'joueur');
    $code = $test->postJson('/api/classique', [
        'nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $albrecht->id, 'mode_mort' => $modeMort,
    ])->assertCreated()->assertJsonPath('groupe.mode_mort', $modeMort)->json('groupe.identifiant');
    $test->actingAs($bruno, 'joueur')->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $brunehaut->id])->assertSuccessful();
    $test->actingAs($alice, 'joueur');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);

    return compact('code', 'groupe', 'quete', 'alice', 'bruno', 'albrecht', 'brunehaut');
}

/** Albrecht finit son tour : Brunehaut à terre est sautée, le round se referme et le suivant s'ouvre. */
function finirLeRound(object $test, Groupe $groupe, string $code, Personnage $albrecht): void
{
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $albrecht));
    $test->getJson("/api/groupes/{$code}/menu")->assertOk();
    $test->postJson("/api/groupes/{$code}/choix", ['option_id' => 'attendre'])->assertAccepted();
}

it('choisit le mode de mort à la création : story par défaut, heroic sur demande, rien d\'autre', function () {
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    $alice = connecterJoueur('alice');
    $creer = fn (array $extra) => $this->postJson('/api/classique', [
        'nom' => 'Essai', 'campagne' => 'campagne-d-essai',
        'personnage_id' => Personnage::create([
            'joueur_id' => $alice->id, 'nom' => 'H'.random_int(1, 99999), 'classe' => 'barbare', 'niveau' => 1,
            'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
            'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
        ])->id,
    ] + $extra);

    $creer([])->assertCreated()->assertJsonPath('groupe.mode_mort', 'story');
    $creer(['mode_mort' => 'heroic'])->assertCreated()->assertJsonPath('groupe.mode_mort', 'heroic');
    $creer(['mode_mort' => 'cruel'])->assertStatus(422)->assertJsonValidationErrors('mode_mort');
});

it('EN JEU, Heroic : à terre, le crâne au round suivant, la mort au suivant ; le mort quitte le groupe au hub', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'albrecht' => $albrecht, 'brunehaut' => $brunehaut, 'bruno' => $bruno]
        = partieHeroic($this);
    $etatDe = fn (Personnage $p) => $quete->etatsPersonnages()->where('personnage_id', $p->id)->first();
    $orBrunehaut = (int) $brunehaut->fresh()->or;

    $etatDe($brunehaut)->update(['tombe' => true]);
    $brunehaut->update(['pv_body' => 0]);

    // Round suivant : elle prend le crâne — publié, annoncé.
    finirLeRound($this, $groupe, $code, $albrecht);
    expect((bool) $etatDe($brunehaut)->crane)->toBeTrue();
    $entite = collect($this->getJson("/api/groupes/{$code}/etat")->json('entites'))->where('type', 'heros')->firstWhere('id', $brunehaut->id);
    expect($entite['crane'])->toBeTrue()->and($entite['mort'])->toBeFalse();

    // Le round d'après, toujours à terre avec le crâne : elle meurt. Elle reste au groupe jusqu'au hub, publiée morte.
    finirLeRound($this, $groupe, $code, $albrecht);
    expect($etatDe($brunehaut))->toBeNull()
        ->and($brunehaut->fresh()->estMort())->toBeTrue();
    $entite = collect($this->getJson("/api/groupes/{$code}/etat")->json('entites'))->where('type', 'heros')->firstWhere('id', $brunehaut->id);
    expect($entite['mort'])->toBeTrue()->and($entite['sorti'])->toBeFalse();
    expect(\App\Models\Evenement::where('groupe_id', $groupe->id)->get()
        ->contains(fn ($e) => ($e->payload['type'] ?? null) === 'heros_mort' && ($e->payload['nom'] ?? null) === 'Brunehaut'))->toBeTrue();

    // Albrecht sort, la quête est gagnée : la récompense n'est pas pour la morte, qui quitte le groupe.
    $quete->etatsPersonnages()->where('personnage_id', $albrecht->id)->update(['position_x' => 2, 'position_y' => 4]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $albrecht));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted();

    expect($quete->refresh()->etat)->toBe('terminee')
        ->and((int) $brunehaut->fresh()->or)->toBe($orBrunehaut)
        ->and($brunehaut->fresh()->groupe_actif_id)->toBeNull()
        ->and($groupe->personnages()->wherePivot('actif', true)->pluck('personnages.id')->all())->toBe([$albrecht->id]);

    // Au roster de son joueur : morte, indisponible ; elle ne rejoint plus aucun groupe.
    $this->actingAs($bruno, 'joueur');
    $fiche = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->firstWhere('id', $brunehaut->id);
    expect($fiche['mort'])->toBeTrue()->and($fiche['disponible'])->toBeFalse();
    $this->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $brunehaut->id])->assertStatus(422);
    // La ligne du héros n'est jamais supprimée.
    expect(Personnage::find($brunehaut->id))->not->toBeNull();
});

it('Heroic : un soin fait défausser le crâne', function () {
    ['quete' => $quete, 'brunehaut' => $brunehaut] = partieHeroic($this);
    $etat = $quete->etatsPersonnages()->where('personnage_id', $brunehaut->id)->firstOrFail();

    $etat->update(['tombe' => true, 'crane' => true]);
    $etat->update(['tombe' => false]);

    expect((bool) $etat->refresh()->crane)->toBeFalse();
});

it('Story : à terre, jamais de crâne ni de mort', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'albrecht' => $albrecht, 'brunehaut' => $brunehaut]
        = partieHeroic($this, 'story');
    $quete->etatsPersonnages()->where('personnage_id', $brunehaut->id)->first()->update(['tombe' => true]);

    finirLeRound($this, $groupe, $code, $albrecht);
    finirLeRound($this, $groupe, $code, $albrecht);

    expect($brunehaut->fresh()->estMort())->toBeFalse()
        ->and($quete->etatsPersonnages()->where('personnage_id', $brunehaut->id)->exists())->toBeTrue();
});

it('Heroic : un TPK tue tous les héros à terre, et une reprise ne ressuscite personne', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'albrecht' => $albrecht, 'brunehaut' => $brunehaut]
        = partieHeroic($this);
    $quete->etatsPersonnages()->update(['tombe' => true]);

    expect(app(ResolveurTour::class)->verdictDeChute($groupe->refresh(), $quete->refresh()))->toBe(ResolveurTour::CHUTE_TPK);
    expect($albrecht->fresh()->estMort())->toBeTrue()
        ->and($brunehaut->fresh()->estMort())->toBeTrue()
        ->and($groupe->refresh()->phase)->toBe('hub');

    // La reprise du début de quête recrée la quête — sans les morts.
    $this->postJson("/api/groupes/{$code}/reprise");
    expect(\App\Models\EtatPersonnageQuete::whereIn('personnage_id', [$albrecht->id, $brunehaut->id])->count())->toBe(0);
});
