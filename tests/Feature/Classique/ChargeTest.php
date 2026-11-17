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
 * LA CHARGE À PORTER ET À RAMENER (lot K5k, R5/F4 — décisions de René du 2026-11-17 : un dé de déplacement au lieu de
 * deux, action normale, reprise sur la case de celui qui tombe, quête terminée quand tous les vivants sont sortis).
 * Plateau et quête de `DocumentExemple`, INVENTÉS : la charge, « le coffre du trésorier », est en (3, 3) dans `entree` ;
 * la sortie est une porte de couloir en (19, 7), côté est.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagneACharge(): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['fin'] = ['type' => 'ramener', 'libelle' => 'Ramener le coffre du trésorier.'];
    $campagne['quetes'][0]['sortie'] = ['type' => 'porte', 'x' => 19, 'y' => 7, 'cote' => 'e'];
    $campagne['quetes'][0]['charge'] = ['nom' => 'le coffre du trésorier', 'x' => 3, 'y' => 3];

    return $campagne;
}

function partieACharge(object $test): array
{
    app(CampagnesConstruites::class)->enregistrer(campagneACharge());
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
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);

    return compact('code', 'groupe', 'quete', 'heros');
}

function menuACharge(object $test, Groupe $groupe, Personnage $heros): \Illuminate\Support\Collection
{
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));

    return collect($test->getJson("/api/groupes/{$groupe->identifiant}/menu")->assertOk()->json('menu.options'));
}

it('valide la charge et la fin « ramener », et refuse ce qui cloche en le nommant', function () {
    expect(app(ValidationCampagne::class)->valider(campagneACharge()))->toBe([]);

    $sansCharge = campagneACharge();
    unset($sansCharge['quetes'][0]['charge']);
    expect(collect(app(ValidationCampagne::class)->valider($sansCharge))->map(fn ($r) => $r['chemin'].' '.$r['code'])->all())
        ->toContain('quetes.0.charge '.ValidationCampagne::CLE_MANQUANTE);

    $surMeuble = campagneACharge();
    $surMeuble['quetes'][0]['charge'] = ['nom' => '', 'x' => 4, 'y' => 4];
    $refus = collect(app(ValidationCampagne::class)->valider($surMeuble))->map(fn ($r) => $r['chemin'].' '.$r['code'])->all();
    expect($refus)->toContain('quetes.0.charge '.ValidationCampagne::CASE_PARTAGEE)
        ->toContain('quetes.0.charge.nom '.ValidationCampagne::VALEUR_INVALIDE);
});

it('EN JEU : la charge se prend au contact, alourdit le porteur d\'un dé, et la carte la montre au sol', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieACharge($this);
    expect($this->getJson("/api/groupes/{$code}/etat")->json('carte.charge'))->toBe(['nom' => 'le coffre du trésorier', 'x' => 3, 'y' => 3]);

    // Loin d'elle : pas d'option. Au contact : « Prendre ».
    expect(menuACharge($this, $groupe, $heros)->pluck('id'))->not->toContain('prendre_charge');
    $quete->etatsPersonnages()->update(['position_x' => 4, 'position_y' => 3]);
    expect(menuACharge($this, $groupe, $heros)->firstWhere('id', 'prendre_charge')['libelle'] ?? null)->toBe('Prendre : le coffre du trésorier');
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'prendre_charge'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'charge_prise');

    $etat = $this->getJson("/api/groupes/{$code}/etat")->json();
    expect($etat['carte']['charge'])->toBeNull()
        ->and(collect($etat['entites'])->firstWhere('id', $heros->id)['charge'])->toBe('le coffre du trésorier');

    // Au tour suivant, le porteur lance ses deux dés et le second est barré.
    $quete->etatsPersonnages()->update(['deplacement_tour' => null, 'detail_deplacement_tour' => null, 'a_joue' => false, 'a_deplace' => false, 'a_agi' => false]);
    desFiges([3, 5]);
    menuACharge($this, $groupe, $heros);
    $detail = $quete->etatsPersonnages()->firstOrFail()->detail_deplacement_tour;
    // (Plus aucun monstre debout : chaque dé compte 4, *Unthreatened Movement* — c'est le dé BARRÉ qui compte ici.)
    expect($detail['des'])->toHaveCount(2)
        ->and($detail['des_annules'])->toBe([false, true])
        ->and($detail['de_annule_par'])->toBe('Charge : le coffre du trésorier');
});

it('le porteur qui tombe lâche la charge sur sa case ; un autre peut la reprendre', function () {
    ['quete' => $quete, 'heros' => $heros] = partieACharge($this);
    $quete->update(['charge' => [...$quete->charge, 'porteur_id' => (int) $heros->id]]);
    $etat = $quete->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => 8, 'position_y' => 3]);

    $etat->update(['tombe' => true]);

    expect($quete->refresh()->charge)->toMatchArray(['porteur_id' => null, 'x' => 8, 'y' => 3]);
    $annonce = \App\Models\Evenement::where('groupe_id', $quete->groupe_id)->get()->first(fn ($e) => ($e->payload['type'] ?? null) === 'charge_lachee');
    expect(app(\App\Partie\JournalCombat::class)->depuisResultat($annonce->payload, 'Albrecht')[0]['texte'])
        ->toBe('Albrecht tombe et lâche le coffre du trésorier : elle reste sur sa case');
});

it('fin « ramener » : sortir AVEC la charge gagne la quête, sortir SANS la perd', function (bool $avec, string $etatFinal) {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieACharge($this);
    if ($avec) {
        $quete->update(['charge' => [...$quete->charge, 'porteur_id' => (int) $heros->id]]);
    }
    $quete->etatsPersonnages()->update(['position_x' => 19, 'position_y' => 7]);
    menuACharge($this, $groupe, $heros);
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted();

    expect($quete->refresh()->etat)->toBe($etatFinal)
        ->and($groupe->refresh()->phase)->toBe('hub')
        ->and((int) $groupe->rang_quete)->toBe($avec ? 2 : 1);
})->with(['avec la charge' => [true, 'terminee'], 'sans la charge' => [false, 'echouee']]);
