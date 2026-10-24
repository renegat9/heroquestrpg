<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
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
 * LA FUITE AU TIERS (*Return of the Witch Lord*, décision Q3 : seuil = 1/3 des Body de départ).
 *
 * Skulmar (q. 7, p. 21) et Kessandria (q. 9, p. 25) « should try to escape » quand ils sont très affaiblis.
 * Le mécanisme est le sort Fuite existant (`MoteurDread::sortDreadFuite()`), déclenché par la capacité
 * `fuit_si_affaibli`. Le Seigneur Sorcier, lui, ne fuit JAMAIS (« there must be no escape for him »).
 *
 * Le précédent (25 %, STRICT) : corrigé le 2026-10-23 — il comparait à un quart TRONQUÉ (plancher 1), qui ne fuyait
 * jamais pour un corps de 7 ou moins. Il compare désormais à la valeur réelle du quart ; un corps de 1 à 3 ne peut
 * pas être sous le quart en étant vivant, et c'est le tiers qui le sert. Les tests « précédent » épinglent le
 * correctif (5, 6, 7 Body fuient à 1 PV) ; le test EN JEU prouve que la fuite part réellement.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/** Une salle 16×9 tout en sol, un héros en (1, 4) — la même quête minimale que les autres tests de figures. */
function fuiteScene(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — la fuite au tiers',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => array_fill(0, 9, array_fill(0, 16, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 16, 'hauteur' => 9,
                'theme' => 'generique', 'mediane_x' => 8, 'mediane_y' => 4]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => [],
            'spawn_heros' => [['x' => 1, 'y' => 4]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);
    EtatPersonnageQuete::create(['quete_id' => $quete->id, 'personnage_id' => $albrecht->id, 'position_x' => 1, 'position_y' => 4]);

    return ['alice' => $alice, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'albrecht' => $albrecht];
}

function fuitePoser(array $ctx, string $nom, int $x, int $y, ?int $pv = null): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nom)->firstOrFail();

    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => $catalogue->id,
        'pv_body' => $pv ?? $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => $x, 'position_y' => $y, 'etat' => 'actif', 'revele' => true,
    ]);
    $instance->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $ctx['quete']);

    return $instance->fresh()->load('monstre');
}

/**
 * Le héros finit son tour par la VRAIE route ; retourne les actions de la phase des monstres, à plat.
 *
 * @return list<array<string, mixed>>
 */
function fuiteTourMonstres(array $ctx): array
{
    test()->actingAs($ctx['alice'], 'joueur');
    test()->getJson('/api/groupes/table-1/menu')->assertOk();
    $reponse = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    return collect($reponse->json('resultat.tour_monstres.actions') ?? [])
        ->flatMap(fn ($a) => ($a['type'] ?? null) === 'actions_composites' ? $a['actions'] : [$a])
        ->values()->all();
}

function fuiteEstLancee(array $actions): bool
{
    return collect($actions)->contains(fn ($a) => ($a['type'] ?? null) === 'sort_dread' && ($a['sort'] ?? null) === 'Fuite');
}

// =====================================================================
// LE SEUIL — pur, et comparé au précédent
// =====================================================================

it('le seuil du TIERS est inclusif : un corps de 3 fuit à 1 Body, pas à 2 — Skulmar et Kessandria', function () {
    $ctx = fuiteScene();

    foreach (['Skulmar', 'Kessandria'] as $nom) {
        $fuyard = fuitePoser($ctx, $nom, 6, 4);
        $seuil = fn (int $pv) => app(MoteurDread::class)->seuilFuiteAtteint($fuyard->fresh()->forceFill(['pv_body' => $pv])->load('monstre'));

        expect($seuil(2))->toBeFalse($nom)
            ->and($seuil(1))->toBeTrue($nom);
    }
});

it('le Seigneur Sorcier ne fuit JAMAIS : aucune capacité de fuite, aucune Fuite dans son répertoire', function () {
    $sei = Monstre::where('nom_base', 'Seigneur Sorcier')->firstOrFail();

    expect(array_key_exists('fuit_si_affaibli', (array) $sei->capacites)
        || in_array('fuit_si_affaibli', (array) $sei->capacites, true))->toBeFalse()
        ->and($sei->sorts_dread)->not->toContain('Fuite');
});

it('le précédent des 25 % reste STRICT, sur la valeur RÉELLE : un Seigneur ogre (10 Body, quart = 2,5) fuit à 2 (20 %), pas à 3', function () {
    // Corrigé le 2026-10-23 : l'ancien `pv < max(1, ⌊max × 0,25⌋)` tronquait 2,5 à 2 et retenait la fuite à 1 PV. 2 PV
    // (20 %) sont STRICTEMENT sous le quart : la règle le dit, le plancher tronqué le refusait.
    $ctx = fuiteScene();
    $ogre = fuitePoser($ctx, 'Seigneur ogre', 6, 4, pv: 10);
    $seuil = fn (int $pv) => app(MoteurDread::class)->seuilFuiteAtteint($ogre->fresh()->forceFill(['pv_body' => $pv])->load('monstre'));

    expect($seuil(3))->toBeFalse()
        ->and($seuil(2))->toBeTrue()
        ->and($seuil(1))->toBeTrue();
});

it('le précédent des 25 % fuit à 1 PV pour un corps de 5, 6 ou 7 Body (SANS correctif : jamais, le plancher tronqué valait 1)', function (int $corps) {
    // Le défaut signalé par le chantier Witch Lord : pour un corps de 7 ou moins, `pv < max(1, ⌊max × 0,25⌋)` valait `pv < 1`,
    // donc JAMAIS vrai pour une créature vivante. Ce test tombe sans le correctif de `seuilFuiteAtteint()`.
    $ctx = fuiteScene();
    $fuyard = fuitePoser($ctx, 'Seigneur ogre', 6, 4, pv: $corps);
    // Le corps MAXIMUM de l'instance est fixé à `$corps` (le helper prend celui du catalogue, 10).
    $fuyard->forceFill(['pv_body_max' => $corps, 'pv_body' => $corps])->save();
    $seuil = fn (int $pv) => app(MoteurDread::class)->seuilFuiteAtteint($fuyard->fresh()->forceFill(['pv_body' => $pv])->load('monstre'));

    expect($seuil(1))->toBeTrue("corps de {$corps} : 1 PV est strictement sous le quart")
        ->and($seuil($corps))->toBeFalse("corps de {$corps} : plein de PV, aucune fuite");
})->with([5, 6, 7]);

it('pile au quart n\'est PAS strictement sous le quart : un corps de 4 à 1 PV ne fuit pas', function () {
    $ctx = fuiteScene();
    $fuyard = fuitePoser($ctx, 'Seigneur ogre', 6, 4, pv: 4);
    $fuyard->forceFill(['pv_body_max' => 4, 'pv_body' => 4])->save();
    $seuil = fn (int $pv) => app(MoteurDread::class)->seuilFuiteAtteint($fuyard->fresh()->forceFill(['pv_body' => $pv])->load('monstre'));

    expect($seuil(1))->toBeFalse()   // 1 = 25 % exact : pas strictement sous
        ->and($seuil(2))->toBeFalse();
});

// =====================================================================
// EN JEU — la phase des monstres fait partir la Fuite, ou la retient
// =====================================================================

it('EN JEU — Skulmar à 1 Body sur 3 lance SA Fuite au tour des monstres, et se téléporte hors de portée', function () {
    $ctx = fuiteScene();
    $skulmar = fuitePoser($ctx, 'Skulmar', 6, 4, pv: 1);
    desFiges(array_fill(0, 200, 4));

    $actions = fuiteTourMonstres($ctx);
    $apres = $skulmar->fresh();

    expect(fuiteEstLancee($actions))->toBeTrue()
        ->and($apres->fuite_dread_utilisee)->toBeTrue()
        ->and(abs((int) $apres->position_x - 1) + abs((int) $apres->position_y - 4))->toBeGreaterThan(5);
});

it('EN JEU — Skulmar à 2 Body sur 3 ne fuit PAS : il reste au combat', function () {
    $ctx = fuiteScene();
    $skulmar = fuitePoser($ctx, 'Skulmar', 6, 4, pv: 2);
    desFiges(array_fill(0, 200, 4));

    $actions = fuiteTourMonstres($ctx);

    expect(fuiteEstLancee($actions))->toBeFalse()
        ->and($skulmar->fresh()->fuite_dread_utilisee)->toBeFalse();
});

it('EN JEU — Kessandria à 1 Body fuit, alors qu\'elle a d\'autres sorts plus tentants (la fuite passe d\'abord)', function () {
    $ctx = fuiteScene();
    $kessandria = fuitePoser($ctx, 'Kessandria', 6, 4, pv: 1);
    desFiges(array_fill(0, 200, 4));

    $actions = fuiteTourMonstres($ctx);

    expect(fuiteEstLancee($actions))->toBeTrue()
        ->and($kessandria->fresh()->fuite_dread_utilisee)->toBeTrue();
});

it('EN JEU — le Seigneur Sorcier à 1 Body ne fuit pas, même acculé : son répertoire n\'a pas la Fuite', function () {
    $ctx = fuiteScene();
    $seigneur = fuitePoser($ctx, 'Seigneur Sorcier', 6, 4, pv: 1);
    desFiges(array_fill(0, 200, 4));

    $actions = fuiteTourMonstres($ctx);

    expect(fuiteEstLancee($actions))->toBeFalse()
        ->and($seigneur->fresh()->fuite_dread_utilisee)->toBeFalse();
});
