<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * QUÊTE SOLO ET SPECTATEURS (lot S1, `docs/plan-mode-classique.md` §6 — *The Frozen Horror*, *The Mage of the
 * Mirror*). `regles.solo` : seul le premier héros de la classe désignée entre ; les autres héros du groupe sont
 * SPECTATEURS — ni ligne d'état, ni menu, rien à risquer ni à gagner.
 *
 * Plateau et quête : ceux de `DocumentExemple`, INVENTÉS. La campagne exige un barbare ; la quête 1 rapporte 10 pièces
 * « à partager ». Groupe : Albrecht (barbare) et Brunehaut (naine).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagneSolo(): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['regles'] = ['solo' => 'barbare'];

    return $campagne;
}

it('valide une quête solo pour une classe exigée, et refuse les autres', function (?string $classe, ?string $code, array $prerequis = ['barbare']) {
    $campagne = campagneSolo();
    $campagne['prerequis_heros'] = $prerequis;
    if ($classe !== null) {
        $campagne['quetes'][0]['regles']['solo'] = $classe;
    }

    $refus = app(ValidationCampagne::class)->valider($campagne);
    if ($code === null) {
        expect($refus)->toBe([]);

        return;
    }
    expect(collect($refus)->contains(fn (array $r) => $r['code'] === $code && str_starts_with($r['chemin'], 'quetes.0.regles')))
        ->toBeTrue(json_encode($refus, JSON_UNESCAPED_UNICODE));
})->with([
    'barbare, exigé' => [null, null],
    'classe que la campagne n\'exige pas' => ['nain', ValidationCampagne::SOLO_SANS_PREREQUIS],
    'classe inconnue' => ['marchand', ValidationCampagne::CLASSE_INCONNUE],
    'campagne sans prérequis' => [null, ValidationCampagne::SOLO_SANS_PREREQUIS, []],
]);

it('refuse une règle de quête inconnue', function () {
    $campagne = campagneSolo();
    $campagne['quetes'][0]['regles']['pleine_lune'] = true;

    expect(collect(app(ValidationCampagne::class)->valider($campagne))->pluck('chemin'))->toContain('quetes.0.regles.pleine_lune');
});

it('EN JEU : seul le barbare entre ; la naine regarde, sans tour ni menu, et ne gagne rien', function () {
    app(CampagnesConstruites::class)->enregistrer(campagneSolo());
    $alice = connecterJoueur('alice');
    $bruno = connecterJoueur('bruno');
    $barbare = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $naine = Personnage::create([
        'joueur_id' => $bruno->id, 'nom' => 'Brunehaut', 'classe' => 'nain', 'niveau' => 1,
        'attribut_body' => 3, 'attribut_mind' => 3, 'pv_body_max' => 7, 'pv_body' => 7, 'pv_mind_max' => 3, 'pv_mind' => 3,
        'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    // La naine fonde le groupe : elle est PREMIÈRE dans l'ordre du tour, et c'est pourtant le barbare qui entre.
    $this->actingAs($bruno, 'joueur');
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $naine->id])
        ->assertCreated()->json('groupe.identifiant');
    $this->actingAs($alice, 'joueur')->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $barbare->id])->assertSuccessful();
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $orNaine = (int) $naine->fresh()->or;
    $orBarbare = (int) $barbare->fresh()->or;

    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    // Un seul engagé, une seule ligne d'état.
    expect($quete->participants())->toBe([(int) $barbare->id])
        ->and($quete->etatsPersonnages()->pluck('personnage_id')->all())->toBe([(int) $barbare->id]);

    // Publié : la naine est spectatrice, pas sortie ; le barbare joue.
    $entites = collect($this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('entites'))->where('type', 'heros')->keyBy('id');
    expect($entites[$naine->id]['spectateur'])->toBeTrue()
        ->and($entites[$naine->id]['sorti'])->toBeFalse()
        ->and($entites[$barbare->id]['spectateur'])->toBeFalse();

    // La manette de la naine n'a aucun menu ; celle du barbare a le sien.
    $this->actingAs($bruno, 'joueur');
    expect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu'))->toBeNull();
    $this->actingAs($alice, 'joueur');
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $barbare));
    expect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('personnage_id'))->toBe($barbare->id);

    // Il finit son tour : le round passe (personne n'attend la naine) et la main lui revient.
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'attendre'])->assertAccepted();
    expect((bool) $quete->etatsPersonnages()->value('a_joue'))->toBeFalse();

    // Objectif accompli, il sort par l'escalier : la récompense « à partager » est TOUTE à lui.
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    $quete->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 4]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $barbare));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted();

    expect($quete->refresh()->etat)->toBe('terminee')
        ->and((int) $barbare->fresh()->or)->toBe($orBarbare + 10)
        ->and((int) $naine->fresh()->or)->toBe($orNaine);
});
