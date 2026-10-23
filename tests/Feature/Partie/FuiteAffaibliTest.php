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
 * Le précédent (25 %, STRICT) est inopérant pour un corps de 3 : son seuil vaut 1, un monstre vivant a au
 * moins 1 PV. Le test « le précédent reste STRICT » épingle donc la différence, et le test EN JEU prouve que
 * la fuite part réellement.
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

it('le précédent des 25 % reste STRICT et inchangé : un Seigneur ogre (10 Body) fuit à 1, pas à 2', function () {
    $ctx = fuiteScene();
    $ogre = fuitePoser($ctx, 'Seigneur ogre', 6, 4, pv: 10);
    $seuil = fn (int $pv) => app(MoteurDread::class)->seuilFuiteAtteint($ogre->fresh()->forceFill(['pv_body' => $pv])->load('monstre'));

    expect($seuil(2))->toBeFalse()
        ->and($seuil(1))->toBeTrue();
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
