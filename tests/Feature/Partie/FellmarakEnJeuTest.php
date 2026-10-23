<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Fellmarak, le Roi Sorcier EN JEU (chantier EXT-TELOR, 2026-10-23) — dés injectés, vraies routes.
 *
 * Décision de René (2026-10-23) : à 0 Body il MEURT, comme tout boss. Le livret le fait fuir par une porte
 * secrète (quête 12) ou mourir par tirage sous Zargon's Flame (quête 13) : ces deux fins sont écartées, et
 * aucune de leurs traces ne doit subsister dans le catalogue ni dans le moteur. Il lance les sorts de sa fiche,
 * et seulement ceux-là.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

it('meurt à 0 Body EN JEU : une attaque qui l\'achève le rend `vaincu`, sans changer de fiche', function () {
    // 8 dés d'attaque, crânes partout : chaque dé touche, et les 6 Body tombent en une attaque (sa défense ne
    // pare que sur un bouclier noir, 1 face sur 6).
    $ctx = demarrerQueteAvecMonstre('Fellmarak le Roi Sorcier', ['des_attaque' => 8]);
    $ligneAvant = $ctx['instance']->monstre_id;

    desFiges(array_fill(0, 60, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    $apres = $ctx['instance']->fresh();

    expect($apres->etat)->toBe('vaincu')
        ->and((int) $apres->monstre_id)->toBe($ligneAvant, 'pas de seconde fiche : même ligne du catalogue');
});

it('un coup fatal rend `vaincu` SANS changement de phase, ni reddition, ni survie : aucune fuite', function () {
    $ctx = demarrerQueteAvecMonstre('Fellmarak le Roi Sorcier', ['des_attaque' => 8]);

    $resultat = app(MoteurDegats::class)->infligerAMonstre(
        $ctx['instance']->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS,
    );

    expect($resultat['vaincu'])->toBeTrue()
        ->and($resultat['changement_phase'])->toBeNull()
        ->and($resultat['reddition'])->toBeFalse()
        ->and($resultat['survie_increvable'])->toBeFalse();
});

it('lance EN JEU un sort de sa fiche au tour des monstres, et jamais un autre (dés injectés)', function () {
    $ctx = demarrerQueteAvecMonstre('Fellmarak le Roi Sorcier', ['attribut_mind' => 1, 'pv_mind' => 1, 'pv_mind_max' => 1]);

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $sortLance = collect($reponse->json('resultat.tour_monstres.actions'))->firstWhere('type', 'sort_dread');

    expect($sortLance)->not->toBeNull()
        ->and($sortLance['sort'])->toBeIn(config('archetypes_lanceurs.fellmarak_roi_sorcier.sorts'));
});

it('garde les CINQ sorts de sa fiche : son palier boss ne retire aucun sort de son répertoire', function () {
    $fellmarak = Monstre::where('nom_base', 'Fellmarak le Roi Sorcier')->firstOrFail();

    $instance = new InstanceMonstre(['elite' => false]);
    $instance->setRelation('monstre', $fellmarak);

    $moteur = app(MoteurDread::class);
    $methode = new ReflectionMethod($moteur, 'sortsDisponibles');
    $methode->setAccessible(true);

    expect($methode->invoke($moteur, $instance, new Quete)->pluck('nom')->all())
        ->toEqualCanonicalizing(['Tempête de feu', 'Rouille', 'Commandement', 'Frayeur', 'Nuée d\'Effroi']);
});
