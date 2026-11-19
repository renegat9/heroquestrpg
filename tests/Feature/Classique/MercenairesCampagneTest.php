<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Personnage;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\MercenairesCampagne;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LES MERCENAIRES D'UNE CAMPAGNE CLASSIQUE (lot K6 ; René, 2026-11-19 : « chaque campagne choisit sa règle »). L'Éclaireur
 * du catalogue (50 po, *The Frozen Horror*) ; campagne et quêtes de `DocumentExemple`, INVENTÉES.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class, MercenaireSeeder::class]);
});

function groupeAMercenaires(object $t, ?string $regime, int $or = 100): array
{
    $campagne = DocumentExemple::campagne();
    if ($regime !== null) {
        $campagne['mercenaires'] = ['regime' => $regime, 'offerts' => ['eclaireur']];
    }
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1, 'or' => $or,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $t->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');

    return ['code' => $code, 'groupe' => Groupe::where('identifiant', $code)->firstOrFail(), 'heros' => $heros,
        'eclaireur' => Mercenaire::where('nom', 'Éclaireur')->firstOrFail()];
}

it('valide la clé de campagne `mercenaires` : un régime connu, des mercenaires recrutables', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['mercenaires'] = ['regime' => 'par_quete', 'offerts' => ['eclaireur']];
    expect(app(ValidationCampagne::class)->valider($campagne))->toBe([]);

    $campagne['mercenaires'] = ['regime' => 'au_hasard', 'offerts' => ['gothar', 'inconnu']];
    $refus = collect(app(ValidationCampagne::class)->valider($campagne))->map(fn ($r) => $r['chemin'].' '.$r['code'])->all();
    expect($refus)->toContain('mercenaires.regime '.ValidationCampagne::VALEUR_INVALIDE)
        ->toContain('mercenaires.offerts.0 '.ValidationCampagne::CATALOGUE_INCONNU)
        ->toContain('mercenaires.offerts.1 '.ValidationCampagne::CATALOGUE_INCONNU);
});

it('sans clé `mercenaires`, une campagne n\'offre personne', function () {
    ['code' => $code, 'eclaireur' => $eclaireur] = groupeAMercenaires($this, null);

    expect($this->getJson("/api/groupes/{$code}/etat")->json('groupe.recrutement_ouvert'))->toBeFalse();
    $this->postJson("/api/groupes/{$code}/mercenaires", ['mercenaire_id' => $eclaireur->id])->assertStatus(422);
});

it('par_quete : payé de la bourse du recruteur, posé au départ, congédié au départ de la quête suivante', function () {
    ['code' => $code, 'groupe' => $groupe, 'heros' => $heros, 'eclaireur' => $eclaireur] = groupeAMercenaires($this, 'par_quete');

    $this->postJson("/api/groupes/{$code}/mercenaires", ['mercenaire_id' => $eclaireur->id])->assertSuccessful();
    $allie = GroupeMercenaire::where('groupe_id', $groupe->id)->firstOrFail();
    expect((int) $heros->refresh()->or)->toBe(50)
        ->and((int) $groupe->refresh()->or)->toBe(0)
        ->and((int) $allie->paye_jusqu_au_rang)->toBe(1);

    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    expect($allie->refresh()->position_x)->not->toBeNull();

    // La quête suivante démarre sans nouvel engagement : il part.
    $groupe->refresh()->update(['phase' => 'hub', 'quete_courante_id' => null, 'rang_quete' => 2]);
    app(MercenairesCampagne::class)->auDepart($groupe->refresh(), 2);
    expect(GroupeMercenaire::where('groupe_id', $groupe->id)->exists())->toBeFalse();
});

it('retenu_moitie : au départ suivant, le recruteur paie la moitié — ou le mercenaire part', function (int $or, bool $reste) {
    ['code' => $code, 'groupe' => $groupe, 'heros' => $heros, 'eclaireur' => $eclaireur] = groupeAMercenaires($this, 'retenu_moitie', 50 + $or);
    $this->postJson("/api/groupes/{$code}/mercenaires", ['mercenaire_id' => $eclaireur->id])->assertSuccessful();

    app(MercenairesCampagne::class)->auDepart($groupe->refresh(), 2);

    expect(GroupeMercenaire::where('groupe_id', $groupe->id)->exists())->toBe($reste)
        ->and((int) $heros->refresh()->or)->toBe($reste ? $or - 25 : $or);
})->with(['bourse suffisante' => [30, true], 'bourse trop maigre' => [10, false]]);

it('debloques : rien d\'offert avant le déblocage ; ensuite payé d\'un jeton, il reste sans plus rien coûter ; un seul de chaque type', function () {
    ['code' => $code, 'groupe' => $groupe, 'heros' => $heros, 'eclaireur' => $eclaireur] = groupeAMercenaires($this, 'debloques');
    $this->postJson("/api/groupes/{$code}/mercenaires", ['mercenaire_id' => $eclaireur->id])->assertStatus(422);

    expect(app(MercenairesCampagne::class)->debloquer($groupe->refresh(), 'eclaireur'))->toBe('Éclaireur');
    $groupe->refresh()->update(['jetons_reputation' => 1]);
    expect(collect($this->getJson("/api/groupes/{$code}/etat")->json('groupe.recrutement.offres'))->first()['decisions'][0]['payable_par_jeton'] ?? null)->toBeTrue();

    $this->postJson("/api/groupes/{$code}/mercenaires", ['mercenaire_id' => $eclaireur->id, 'paiement' => 'jeton'])->assertSuccessful();
    $allie = GroupeMercenaire::where('groupe_id', $groupe->id)->firstOrFail();
    expect((int) $groupe->refresh()->jetons_reputation)->toBe(0)
        ->and((int) $heros->refresh()->or)->toBe(100)
        ->and((int) $allie->paye_jusqu_au_rang)->toBe(MercenairesCampagne::PAYE_A_VIE);

    app(MercenairesCampagne::class)->auDepart($groupe->refresh(), 2);
    expect(GroupeMercenaire::where('groupe_id', $groupe->id)->exists())->toBeTrue()
        ->and((int) $heros->refresh()->or)->toBe(100);

    // Un second Éclaireur : refusé (« one of each type »).
    $this->postJson("/api/groupes/{$code}/mercenaires", ['mercenaire_id' => $eclaireur->id])->assertStatus(422);
});
