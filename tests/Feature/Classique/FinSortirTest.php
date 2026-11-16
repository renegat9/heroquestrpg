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
 * FIN « SORTIR » (René, 2026-11-16, après la relecture de *Kellar's Keep* : presque toutes ses quêtes demandent de
 * « find the wooden exit door »). `fin: {type: "sortir", libelle}` : la sortie est ouverte DÈS LE DÉPART, l'objectif
 * n'est jamais « accompli » en cours de quête, et la quête est gagnée quand le dernier héros debout est sorti.
 *
 * Plateau et quête : ceux de `DocumentExemple`, INVENTÉS. Entrée par l'escalier de `entree` ; sortie par une porte de
 * couloir contre le bord est du plateau, en (19, 7). Le gobelin de `entree` reste debout tout du long.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagneASortir(array $fausses = []): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['fin'] = ['type' => 'sortir', 'libelle' => 'Trouver la porte de sortie.'];
    $campagne['quetes'][0]['sortie'] = ['type' => 'porte', 'x' => 19, 'y' => 7, 'cote' => 'e'];
    if ($fausses !== []) {
        $campagne['quetes'][0]['fausses_sorties'] = $fausses;
    }

    return $campagne;
}

function partieASortir(object $test, array $fausses = []): array
{
    app(CampagnesConstruites::class)->enregistrer(campagneASortir($fausses));
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    return compact('code', 'groupe', 'quete', 'heros');
}

it('accepte une fin « sortir », sans autre clé que sa consigne', function () {
    expect(app(ValidationCampagne::class)->valider(campagneASortir()))->toBe([]);

    $campagne = campagneASortir();
    $campagne['quetes'][0]['fin']['cibles'] = [0];
    $refus = app(ValidationCampagne::class)->valider($campagne);
    expect(collect($refus)->contains(fn (array $r) => $r['code'] === ValidationCampagne::CLE_INCONNUE
        && str_starts_with($r['chemin'], 'quetes.0.fin')))->toBeTrue(json_encode($refus, JSON_UNESCAPED_UNICODE));

    $campagne = campagneASortir();
    unset($campagne['quetes'][0]['fin']['libelle']);
    expect(collect(app(ValidationCampagne::class)->valider($campagne))->pluck('code'))->toContain(ValidationCampagne::CLE_MANQUANTE);
});

it('EN JEU : la sortie est ouverte dès le départ, rien n\'est « accompli », et sortir gagne la quête', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieASortir($this);

    expect($quete->objectif())->toBe(Quete::OBJECTIF_SORTIR)
        ->and($quete->sortieDisponible())->toBeTrue()
        ->and($quete->objectifAccompli())->toBeFalse();

    $etatQuete = $this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('quete');
    expect($etatQuete['objectif_accompli'])->toBeFalse()
        ->and($etatQuete['sortie']['consigne'])->toBe('Trouvez la porte de sortie et quittez le donjon.');

    // Le gobelin est toujours debout ; devant la porte de sortie, l'option est là.
    expect(InstanceMonstre::where('quete_id', $quete->id)->where('etat', 'actif')->exists())->toBeTrue();
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->update(['position_x' => 19, 'position_y' => 7]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $option = collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'))->firstWhere('id', 'sortir_donjon');
    expect($option['libelle'] ?? null)->toBe('Quitter le donjon par la porte de sortie');

    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'sortie_en_personne');

    expect($quete->refresh()->etat)->toBe('terminee')
        ->and($groupe->refresh()->phase)->toBe('hub')
        ->and((int) $groupe->rang_quete)->toBe(2);
});

it('ne nomme pas la sortie tant qu\'une fausse porte de sortie reste inconnue', function () {
    ['quete' => $quete] = partieASortir($this, [['x' => 19, 'y' => 9, 'cote' => 'e']]);

    expect($quete->consigneDeSortie())->toBe('Trouvez la sortie et quittez le donjon.');
});
