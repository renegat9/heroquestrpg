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
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * CAMPAGNE RAMIFIÉE (lot K5i, `docs/plan-mode-classique.md` §2.5 C2). Trois quêtes INVENTÉES : la 1 (celle de
 * `DocumentExemple`), la 2, et une 3e copie de la 2 sous un autre slug. La 1 a une suite : si le drapeau `raccourci`
 * est posé, le groupe saute directement à la 3e ; sinon, ordre normal.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagneRamifiee(array $suite): array
{
    $campagne = DocumentExemple::campagne();
    $troisieme = DocumentExemple::secondeQuete();
    $troisieme['slug'] = 'le-raccourci';
    $troisieme['titre'] = 'Le raccourci';
    $campagne['quetes'][] = $troisieme;
    $campagne['quetes'][0]['notes'] = [
        ['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
            'alors' => [['effet' => 'drapeau', 'nom' => 'raccourci']]],
    ];
    $campagne['quetes'][0]['suite'] = $suite;

    return $campagne;
}

it('valide les embranchements et refuse ce qui cloche en le nommant', function (array $suite, ?string $chemin, ?string $code) {
    $refus = app(ValidationCampagne::class)->valider(campagneRamifiee($suite));
    if ($code === null) {
        expect($refus)->toBe([]);

        return;
    }
    expect(collect($refus)->contains(fn (array $r) => $r['chemin'] === $chemin && $r['code'] === $code))->toBeTrue(json_encode($refus, JSON_UNESCAPED_UNICODE));
})->with([
    'drapeau puis ordre' => [[['quete' => 'le-raccourci', 'si_drapeau' => 'raccourci'], ['quete' => 'la-crypte']], null, null],
    'fin de campagne' => [[['fin' => true, 'si_drapeau' => 'raccourci']], null, null],
    'quête et fin ensemble' => [[['quete' => 'la-crypte', 'fin' => true]], 'quetes.0.suite.0', ValidationCampagne::VALEUR_INVALIDE],
    'ni quête ni fin' => [[['si_drapeau' => 'raccourci']], 'quetes.0.suite.0', ValidationCampagne::VALEUR_INVALIDE],
    'quête inconnue' => [[['quete' => 'nulle-part']], 'quetes.0.suite.0.quete', ValidationCampagne::CIBLE_INCONNUE],
    'vers elle-même' => [[['quete' => 'premiere-marche']], 'quetes.0.suite.0.quete', ValidationCampagne::CIBLE_INCONNUE],
    'drapeau que personne ne pose' => [[['quete' => 'la-crypte', 'si_drapeau' => 'jamais']], 'quetes.0.suite.0.si_drapeau', ValidationCampagne::DRAPEAU_JAMAIS_POSE],
    'sans drapeau avant un autre' => [[['quete' => 'la-crypte'], ['quete' => 'le-raccourci', 'si_drapeau' => 'raccourci']], 'quetes.0.suite.0', ValidationCampagne::VALEUR_INVALIDE],
    'fin qui n\'est pas true' => [[['fin' => 'oui']], 'quetes.0.suite.0.fin', ValidationCampagne::TYPE_INVALIDE],
]);

it('EN JEU : la victoire mène à la quête que désigne l\'embranchement — avec le drapeau, au raccourci ; sans, dans l\'ordre ; « fin » clôt la campagne', function (array $suite, bool $drapeau, int $rangAttendu) {
    app(CampagnesConstruites::class)->enregistrer(campagneRamifiee($suite));
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
    if ($drapeau) {
        $quete->poserDrapeau('raccourci');
    }

    // Objectif accompli, il sort par l'escalier : la quête est gagnée.
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    $quete->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 4]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.quete.classique.rang_suivant', $rangAttendu);

    expect((int) $groupe->refresh()->rang_quete)->toBe($rangAttendu);
})->with([
    'drapeau posé : le raccourci' => [[['quete' => 'le-raccourci', 'si_drapeau' => 'raccourci']], true, 3],
    'drapeau absent : dans l\'ordre' => [[['quete' => 'le-raccourci', 'si_drapeau' => 'raccourci']], false, 2],
    'fin de campagne' => [[['fin' => true, 'si_drapeau' => 'raccourci']], true, 4],
    'embranchement sans drapeau' => [[['quete' => 'le-raccourci']], false, 3],
]);
