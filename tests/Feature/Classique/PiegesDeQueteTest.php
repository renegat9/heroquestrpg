<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\MoteurPieges;
use App\Partie\ZoneFouille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * PIÈGES DE QUÊTE — INTROUVABLES, INDÉSARMABLES (lot K5d, `docs/plan-mode-classique.md` §2.3 R6). Plateau et quête :
 * ceux de `DocumentExemple`, INVENTÉS ; la fosse de la quête 1 est en (2, 2), dans `entree`.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagnePiegee(array $drapeaux): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['pieges'][0] = [...$campagne['quetes'][0]['pieges'][0], ...$drapeaux];

    return $campagne;
}

function partiePiegee(object $test, array $drapeaux, string $classe = 'nain'): array
{
    app(CampagnesConstruites::class)->enregistrer(campagnePiegee($drapeaux));
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Brunehaut', 'classe' => $classe, 'niveau' => 1,
        'attribut_body' => 3, 'attribut_mind' => 3, 'pv_body_max' => 7, 'pv_body' => 7, 'pv_mind_max' => 3, 'pv_mind' => 3,
        'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    // La campagne d'essai exige un barbare : la table passe outre les « prêt », pas les prérequis — on les retire ici.
    $groupe->campagneConstruite->update(['document' => [...$groupe->campagneConstruite->document, 'prerequis_heros' => []]]);
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    $quete->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 2]);

    return compact('groupe', 'quete', 'heros');
}

it('accepte les deux drapeaux booléens d\'un piège, refuse le reste en le nommant', function () {
    expect(app(ValidationCampagne::class)->valider(campagnePiegee(['introuvable' => true, 'indesarmable' => false])))->toBe([]);

    $refus = collect(app(ValidationCampagne::class)->valider(campagnePiegee(['introuvable' => 'oui', 'piege_magique' => true])))
        ->map(fn (array $r) => $r['chemin'].' '.$r['code'])->all();
    expect($refus)->toContain('quetes.0.pieges.0.introuvable '.ValidationCampagne::TYPE_INVALIDE)
        ->toContain('quetes.0.pieges.0.piege_magique '.ValidationCampagne::CLE_INCONNUE);
});

it('pose les drapeaux sur la carte seulement quand ils valent true', function () {
    $campagne = campagnePiegee(['introuvable' => true, 'indesarmable' => false]);
    $entree = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0])['carte']['pieges'][0];

    expect($entree['introuvable'] ?? null)->toBeTrue()
        ->and($entree)->not->toHaveKey('indesarmable')
        ->and(MoteurPieges::trouvable($entree, null))->toBeFalse()
        ->and(MoteurPieges::desarmable($entree, null))->toBeTrue()
        // La carte du catalogue décide aussi, sans drapeau.
        ->and(MoteurPieges::desarmable([], new Piege(['desarmable' => 'non'])))->toBeFalse()
        ->and(MoteurPieges::trouvable([], new Piege(['detectable' => false])))->toBeFalse();
});

it('EN JEU : un piège introuvable reste caché à la fouille ; le même sans drapeau se trouve', function (bool $introuvable) {
    ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partiePiegee($this, $introuvable ? ['introuvable' => true] : []);
    $carte = $quete->carte;
    $zone = ZoneFouille::de((array) $carte->grille, 3, 2);

    $reveles = app(MoteurPieges::class)->revelerAutour($groupe, $carte, $heros, $zone);

    expect(count($reveles))->toBe($introuvable ? 0 : 1)
        ->and(data_get($carte->fresh()->grille, 'pieges.0.etat'))->toBe($introuvable ? MoteurPieges::ETAT_CACHE : MoteurPieges::ETAT_DETECTE);
})->with(['introuvable' => [true], 'ordinaire' => [false]]);

it('EN JEU : un piège indésarmable, une fois connu, n\'offre pas « désamorcer » au nain ; sans drapeau il l\'offre', function (bool $indesarmable) {
    ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partiePiegee($this, $indesarmable ? ['indesarmable' => true] : []);
    app(MoteurPieges::class)->changerEtat($quete->carte, 0, MoteurPieges::ETAT_DETECTE);

    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $ids = collect($this->getJson("/api/groupes/{$groupe->identifiant}/menu")->assertOk()->json('menu.options'))->pluck('id');

    expect($ids->contains(fn (string $id) => str_starts_with($id, 'desamorcer_2_2')))->toBe(! $indesarmable);
})->with(['indésarmable' => [true], 'ordinaire' => [false]]);
