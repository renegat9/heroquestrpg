<?php

declare(strict_types=1);

use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * La Potion de force donne aussi ses +2 dés au prochain JET DE BODY (René, 2026-11-06 : « une potion de force devrait
 * aussi donner un bonus de 2 dés au test de force », au PREMIER des deux — attaque ou jet).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

function boirePotionDeForce(App\Models\Personnage $heros): void
{
    $potion = Objet::where('nom', 'Potion de force')->firstOrFail();
    $ligne = Inventaire::create(['personnage_id' => $heros->id, 'objet_id' => $potion->id, 'emplacement' => 'consommable', 'quantite' => 1]);
    app(MoteurSorts::class)->appliquerBuffPotion($heros, $potion, $ligne->id);
}

it('ajoute 2 dés au jet de Body qui vient d\'abord, et se dépense — l\'attaque suivante n\'a plus rien', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    boirePotionDeForce($ctx['heros']);
    $sorts = app(MoteurSorts::class);

    expect($sorts->bonusJetBody($ctx['heros']))->toBe(['valeur' => 2, 'sources' => ['Potion de force']])
        ->and($sorts->bonusDes($ctx['heros'], 'bonus_des_attaque'))->toBe(2);

    // Le point de passage des jets de Body, tel que les cinq résolutions l'appellent.
    $desDeBody = new ReflectionMethod(App\Partie\ResolveurTour::class, 'desDeBody');
    $jet = $desDeBody->invoke(app(App\Partie\ResolveurTour::class), $ctx['heros']->fresh());

    expect($jet['des'])->toBe((int) $ctx['heros']->attribut_body + 2)
        ->and($jet['modificateurs'])->toBe([['source' => 'Potion de force', 'valeur' => 2, 'sur' => 'body']])
        // Une seule gorgée, un seul bonus : le buff entier est parti, attaque comprise.
        ->and($sorts->bonusJetBody($ctx['heros']->fresh())['valeur'])->toBe(0)
        ->and($sorts->bonusDes($ctx['heros']->fresh(), 'bonus_des_attaque'))->toBe(0);
});

it('se dépense à l\'attaque si elle vient d\'abord : le jet de Body suivant n\'a plus les 2 dés', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    boirePotionDeForce($ctx['heros']);

    app(MoteurSorts::class)->expirerBuffs($ctx['heros'], App\Engine\DureeEffet::PROCHAINE_ATTAQUE);

    expect(app(MoteurSorts::class)->bonusJetBody($ctx['heros']->fresh())['valeur'])->toBe(0);
});
