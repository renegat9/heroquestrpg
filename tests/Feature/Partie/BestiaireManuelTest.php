<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
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
 * BESTIAIRE AUTOMATIQUE OU MANUEL (René, 2026-09-28) : « une option
 * automatique (comme actuellement) ou manuelle (sélection possible d'une ou
 * plusieurs extensions, et si aucune sélection, système de base seulement) ».
 * Le manuel est un FILTRE : une boîte non cochée n'apparaît jamais — ni en
 * rencontre, ni en boss, ni en monstre errant.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Achats d'une rencontre (méthode privée → réflexion), pour un bestiaire donné. */
function achatsPour(BestiaireGroupe $bestiaire, array $structure, int $positionArc, int $graine): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    return $methode->invoke($demarreur, $structure, 40, 12, $positionArc, $graine, $bestiaire);
}

it('enregistre le mode à la création : absent = automatique, liste (vide comprise) = manuel', function () {
    connecterJoueur('alice');

    $creer = fn (string $id, array $extra) => $this->postJson('/api/groupes', [
        'identifiant' => $id, 'nom' => $id, 'theme' => 'Test', 'longueur' => 'courte', ...$extra,
    ]);

    $creer('auto', [])->assertStatus(201);
    $creer('base-seule', ['bestiaire_boites' => []])->assertStatus(201);
    $creer('jungle-lune', ['bestiaire_boites' => ['jungles_delthrak', 'dread_moon']])->assertStatus(201);

    expect(Groupe::where('identifiant', 'auto')->value('boites_bestiaire'))->toBeNull()
        ->and(Groupe::where('identifiant', 'base-seule')->first()->boites_bestiaire)->toBe([])
        ->and(Groupe::where('identifiant', 'jungle-lune')->first()->boites_bestiaire)->toBe(['jungles_delthrak', 'dread_moon']);

    // Une boîte inconnue, ou deux fois la même : refusé.
    $creer('inconnue', ['bestiaire_boites' => ['kellars_keep']])->assertStatus(422);
    $creer('doublon', ['bestiaire_boites' => ['horde_ogre', 'horde_ogre']])->assertStatus(422);
});

it('publie sur /moi les boîtes proposables, libellés officiels décidés côté serveur', function () {
    connecterJoueur('alice');

    $boites = $this->getJson('/api/moi')->assertOk()->json('boites_bestiaire');

    expect(collect($boites)->pluck('id')->all())->toBe(DemarreurQuete::BOITES_THEMATIQUES)
        ->and(collect($boites)->firstWhere('id', 'jungles_delthrak')['libelle'])->toBe('Jungles of Delthrak');
});

it('sans case cochée, ne fait apparaître QUE le jeu de base et nos créatures — boss compris', function () {
    $base = BestiaireGroupe::manuel([]);
    $vus = collect();

    foreach (['sous_boss', 'boss'] as $tier) {
        foreach (range(1, 12) as $graine) {
            foreach ([1, 2, 3] as $arc) {
                $achats = achatsPour($base, ['rencontre_finale' => ['tier' => $tier]], $arc, $graine);
                $vus = $vus->merge($achats);

                // Le jeu de base n'a ni sous-boss ni boss : les nôtres comblent.
                expect(collect($achats)->pluck('tier'))->toContain($tier);
            }
        }
    }

    expect($vus->pluck('boite')->unique()->reject(fn ($b) => $b === null || $b === 'base')->values()->all())->toBe([]);
});

it('avec des cases cochées, montre leur signature et jamais une boîte non cochée', function () {
    $choix = BestiaireGroupe::manuel(['jungles_delthrak', 'horde_ogre']);
    $vus = collect();

    foreach (range(1, 12) as $graine) {
        foreach (['sous_boss', 'boss'] as $tier) {
            $vus = $vus->merge(achatsPour($choix, ['rencontre_finale' => ['tier' => $tier]], 1, $graine));
        }
    }

    $boites = $vus->pluck('boite')->unique();

    expect($boites->reject(fn ($b) => in_array($b, [null, 'base', 'jungles_delthrak', 'horde_ogre'], true))->values()->all())->toBe([])
        ->and($boites)->toContain('jungles_delthrak')
        ->and($boites)->toContain('horde_ogre');
});

it('garde l\'automatique inchangé : une préférence, jamais un filtre', function () {
    $auto = BestiaireGroupe::auto('horreur_des_glaces');

    expect($auto->autorise('jungles_delthrak'))->toBeTrue()
        ->and($auto->contient('horreur_des_glaces'))->toBeTrue()
        ->and($auto->libelle())->toBe('The Frozen Horror');
});

it('démarre une vraie quête en manuel : monstres filtrés, monstre errant filtré, payload de la table', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['boites_bestiaire' => ['jungles_delthrak']]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $autorisees = [null, 'base', 'jungles_delthrak'];
    foreach ($quete->instancesMonstres()->with('monstre')->get() as $instance) {
        expect(in_array($instance->monstre->boite, $autorisees, true))->toBeTrue("{$instance->monstre->nom_base} ({$instance->monstre->boite})");
    }

    // Aucun tirage automatique en manuel.
    expect($groupe->fresh()->theme_bestiaire)->toBeNull();

    // Le monstre errant : le moins cher du bestiaire AUTORISÉ.
    $methode = new ReflectionMethod(App\Partie\ResolveurTour::class, 'spawnErrant');
    $methode->setAccessible(true);
    $etat = $quete->etatsPersonnages()->first();
    $errant = $methode->invoke(app(App\Partie\ResolveurTour::class), $quete, $etat);
    if ($errant !== null) {
        expect(in_array($errant->monstre->boite, $autorisees, true))->toBeTrue();
    }

    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe');
    expect($partage['bestiaire_mode'])->toBe('manuel')
        ->and($partage['bestiaire_boites'])->toBe(['jungles_delthrak'])
        ->and($partage['theme_bestiaire'])->toBeNull()
        ->and($partage['theme_bestiaire_libelle'])->toBe('HeroQuest Game System + Jungles of Delthrak');
});
