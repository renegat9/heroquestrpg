<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
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
 * LES AJUSTEMENTS DU JEU DE BASE (René, 2026-10-31 : « Apporte les ajustements nécessaires »).
 *
 *  1. Grak lance ses trois sorts, chacun UNE fois (`sorts_uniques`) — joué EN JEU par les routes réelles.
 *  2. Le Seigneur Sorcier de Barak Tor : chacun une fois, Frayeur DEUX fois — six lancers, joués EN JEU.
 *  3. First Light : la Garde du Dread partage la boîte, Abraxus est sous-boss sourcé, et le jalon sous-boss d'une
 *     campagne First Light tire un sous-boss DE SA BOÎTE.
 *  4. L'Archimage elfe : `cout` remesuré à 12.
 *  5. La migration des lignes existantes donne EXACTEMENT ce que le seeder écrit (pas de dérive).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MobilierSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Une quête à DEUX héros (Albrecht, puis Brunhild), comme `SortsOgreHordeTest::ogresQuete2()`. Le héros seul que
 * Tourmente étourdit a désormais son round rejoué (2026-10-31, `RoundSansHerosTest`) : deux héros restent ici pour
 * que chaque sort de Grak et du Seigneur Sorcier soit lancé par une ronde complète.
 *
 * Le monstre est posé au contact d'Albrecht (`$ecart` = 0), ou à `$ecart` cases de lui sur une rangée nue.
 *
 * @return array<string, mixed>
 */
function ajustementsQuete2(string $nomMonstre, int $ecart = 0): array
{
    $alice = connecterJoueur('alice');
    $bob = connecterJoueur('bob');
    $groupe = creerGroupe();
    $h1 = creerHeros($alice, $groupe, 'Albrecht', 1);
    $h2 = creerHeros($bob, $groupe, 'Brunhild', 2);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $catalogue = Monstre::where('nom_base', $nomMonstre)->firstOrFail();
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update([
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'etat' => 'actif',
        'elite' => false,
    ]);
    $instance->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $quete);

    $etat1 = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h1->id)->firstOrFail();
    $etat2 = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h2->id)->firstOrFail();

    if ($ecart > 0) {
        $ctx = compact('quete', 'instance', 'etat1');
        ajustementsAligner($ctx, $ecart);
        $etat1->refresh();
    } else {
        $contact = caseAdjacenteLibre($quete, (int) $etat1->position_x, (int) $etat1->position_y);
        $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);
    }

    // Brunhild à côté d'Albrecht, sur la case libre qui n'est PAS celle du monstre.
    $voisine = caseAdjacenteLibre($quete, (int) $etat1->position_x, (int) $etat1->position_y);
    $etat2->update(['position_x' => $voisine['x'], 'position_y' => $voisine['y']]);

    return [
        'alice' => $alice, 'bob' => $bob, 'quete' => $quete->refresh(), 'instance' => $instance->refresh()->load('monstre'),
        'etat1' => $etat1->refresh(), 'etat2' => $etat2->refresh(),
    ];
}

/**
 * Une RONDE des héros sur les vraies routes : chacun qui a un tour passe (`attendre`) ; celui qui clôt la ronde déclenche
 * la phase des monstres. Un héros sans menu (422 : il a perdu son tour) est sauté. Rend les actions des monstres.
 *
 * Dés tous à 6 : un 6 rompt Sommeil, Frayeur et Commandement au premier jet. On ne mesure ici que les LANCERS : un sort
 * rompu a bien été lancé, et a coûté son usage (`consommerUsage()`).
 */
function ajustementsRonde(array $ctx): array
{
    $actions = [];

    foreach (['alice', 'bob'] as $qui) {
        desFiges(array_fill(0, 300, 6));

        $reponse = test()->actingAs($ctx[$qui], 'joueur')
            ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre']);

        if ($reponse->status() === 422) {
            continue;
        }

        $reponse->assertStatus(202);
        $actions = [...$actions, ...collect($reponse->json('resultat.tour_monstres.actions'))->all()];
    }

    return $actions;
}

/** Les noms des sorts de Dread lancés pendant une phase. */
function ajustementsSortsJoues(array $actions): array
{
    return collect($actions)->pluck('sort')->filter()->values()->all();
}

/**
 * Aligne le sorcier et le héros sur une rangée libre : le sorcier en tête, le héros $ecart cases plus loin, portes
 * ouvertes et mobilier retiré (une ligne de vue nue). Pour un sort qui ne se lance qu'à DISTANCE (invocation).
 *
 * @param  array{quete: Quete, instance: InstanceMonstre, etat1: EtatPersonnageQuete}  $ctx
 */
function ajustementsAligner(array $ctx, int $ecart): void
{
    ouvrirToutesLesPortes($ctx['quete']);

    $carte = $ctx['quete']->carte;
    $grille = $carte->grille;
    $grille['mobilier'] = [];
    $carte->update(['grille' => $grille]);
    $ctx['quete']->refresh();

    $cases = $ctx['quete']->carte->grille['cases'];
    $longueur = $ecart + 1;

    foreach ($cases as $y => $ligne) {
        for ($x = 0; $x + $longueur <= count($ligne); $x++) {
            for ($i = 0; $i < $longueur; $i++) {
                if (($cases[$y][$x + $i] ?? 'm') !== 's') {
                    continue 2;
                }
            }

            $ctx['instance']->update(['position_x' => $x, 'position_y' => $y]);
            $ctx['etat1']->update(['position_x' => $x + $ecart, 'position_y' => $y]);
            $ctx['quete']->refresh();

            return;
        }
    }

    throw new RuntimeException('Aucune rangée libre de '.$longueur.' cases : scénario invalide.');
}

/**
 * La première figure d'un tirage de sous-boss pour un thème, comme `DemarreurQuete` la achète (même méthode, par réflexion).
 */
function ajustementsSousBossDuTheme(string $theme, int $arc, int $graine): Monstre
{
    $gabarit = GabaritQuete::where('type_jalon', 'sous_boss')->firstOrFail();
    $structure = ['rencontre_finale' => (array) data_get($gabarit->structure, 'rencontre_finale')];

    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    $achats = $methode->invoke($demarreur, $structure, 40, 20, $arc, $graine, BestiaireGroupe::auto($theme));

    return $achats[0];
}

// ==================================================================
// REGISTRE — la forme à compteur de `sorts_uniques`, testée dans les DEUX sens
// ==================================================================

it('REGISTRE : `sorts_uniques` à compteur — chaque nombre nomme un sort du répertoire, et un seul monstre le porte', function () {
    $compteurs = Monstre::all()->filter(fn (Monstre $m) => is_array(($m->capacites ?? [])['sorts_uniques'] ?? null));

    // Seul le Seigneur Sorcier de Barak Tor écrit « Frayeur deux fois ». Un nouveau compteur doit se déclarer ici.
    expect($compteurs->pluck('nom_base')->all())->toBe(['Seigneur Sorcier de Barak Tor']);

    foreach ($compteurs as $m) {
        $repertoire = (array) config("archetypes_lanceurs.{$m->archetype_lanceur}.sorts", $m->sorts_dread);

        foreach ((array) $m->capacites['sorts_uniques'] as $sort => $lancers) {
            expect(in_array($sort, $repertoire, true))->toBeTrue("{$m->nom_base} : « {$sort} » compté hors de son répertoire")
                // Un sort compté UNE fois ne s'écrit pas : c'est la règle nue. Un compteur veut au moins deux lancers.
                ->and((int) $lancers)->toBeGreaterThanOrEqual(2, "{$m->nom_base} : « {$sort} » compté {$lancers} fois, la règle nue suffit");
        }
    }
});

// ==================================================================
// 1. GRAK — trois sorts, chacun une fois, un par tour
// ==================================================================

it('EN JEU — Grak lance Frayeur, Sommeil et Tourmente, chacun UNE fois, un par tour, puis plus rien (sorts_uniques)', function () {
    $ctx = ajustementsQuete2('Grak');
    $instance = $ctx['instance']->fresh()->load('monstre');

    // Budget = son répertoire entier : trois lancers, pas deux (l'ancien budget de palier).
    expect((array) $instance->monstre->capacites)->toBe(['sorts_uniques'])
        ->and((int) $instance->usages_dread)->toBe(3);

    $lances = [];

    for ($tour = 0; $tour < 6; $tour++) {
        $sorts = ajustementsSortsJoues(ajustementsRonde($ctx));

        // Un sort par tour, jamais deux d'un coup.
        expect(count($sorts))->toBeLessThanOrEqual(1);

        $lances = [...$lances, ...$sorts];
    }

    expect(collect($lances)->countBy()->sortKeys()->all())
        ->toBe(['Frayeur' => 1, 'Sommeil' => 1, 'Tourmente' => 1])
        ->and((int) $instance->fresh()->usages_dread)->toBe(0)
        ->and((array) $instance->fresh()->sorts_dread_lances)->toHaveCount(3);
});

// ==================================================================
// 2. LE SEIGNEUR SORCIER DE BARAK TOR — chacun une fois, Frayeur deux fois
// ==================================================================

it('porte la capacité à compteur : chacun une fois, Frayeur deux fois, sur le répertoire du Seigneur', function () {
    $witch = Monstre::where('nom_base', 'Seigneur Sorcier de Barak Tor')->firstOrFail();

    expect((array) $witch->capacites)->toBe(['sorts_uniques' => ['Frayeur' => 2]])
        ->and((array) config('archetypes_lanceurs.seigneur_sorcier_barak_tor.sorts'))
        ->toContain('Frayeur');
});

it('EN JEU — le Seigneur Sorcier de Barak Tor lance six sorts : Frayeur DEUX fois, chacun des quatre autres une fois', function () {
    $ctx = ajustementsQuete2('Seigneur Sorcier de Barak Tor', 3);

    $instance = $ctx['instance']->fresh();

    // Budget = 5 sorts, Frayeur comptée deux fois : six lancers, pas cinq (le budget de boss) ni trois.
    expect((int) $instance->usages_dread)->toBe(6);

    $lances = [];

    for ($tour = 0; $tour < 12; $tour++) {
        $lances = [...$lances, ...ajustementsSortsJoues(ajustementsRonde($ctx))];
    }

    expect(collect($lances)->countBy()->sortKeys()->all())
        ->toBe([
            'Boule de Flammes' => 1,
            'Commandement' => 1,
            'Frayeur' => 2,
            'Invocation de morts-vivants' => 1,
            'Tourmente' => 1,
        ])
        ->and((int) $ctx['instance']->fresh()->usages_dread)->toBe(0);
});

// ==================================================================
// 3. FIRST LIGHT — la Garde partagée, Abraxus sourcé, le jalon sous-boss de sa boîte
// ==================================================================

it('porte la Garde du Dread dans la boîte de Witch Lord ET dans celle de First Light (une ligne, deux boîtes)', function () {
    $garde = Monstre::where('nom_base', 'Garde du Dread')->firstOrFail();

    expect($garde->boites())->toContain('witch_lord', 'first_light')
        ->and($garde->tier)->toBe('sous_boss')
        // Bloc relu sur le rendu PNG de First Light, quête 4 (PDF 11) : identique à Return of the Witch Lord.
        ->and([$garde->deplacement, $garde->attaque, $garde->defense, $garde->pv_body, $garde->pv_mind])->toBe([8, 4, 6, 3, 3]);
});

it('porte ABRAXUS, sous-boss de First Light, sur le bloc de stats PROPRE de sa carte (quête 9, PDF 18)', function () {
    $abraxus = Monstre::where('nom_base', 'Abraxus')->firstOrFail();

    expect([$abraxus->deplacement, $abraxus->attaque, $abraxus->defense, $abraxus->pv_body, $abraxus->pv_mind])
        ->toBe([8, 4, 5, 3, 3], 'Abraxus : Mouvement 8, Attaque 4 dés, Défense 5 dés, Body 3, Mind 3 (rendu PNG relu)')
        ->and($abraxus->tier)->toBe('sous_boss')
        ->and($abraxus->boite)->toBe('first_light')
        ->and($abraxus->archetype_lanceur)->toBeNull()
        ->and((array) $abraxus->capacites)->toBe(['attaque_diagonale'])
        ->and((int) $abraxus->cout)->toBe(9, 'cout mesuré : 3 / (1,5 − 5/6) = 4,5 attaques, comme Kavra');

    // Son jeton est celui du Guerrier du Dread, et sa voix celle de la famille.
    expect(config('symboles_monstres.cles.Abraxus'))->toBe('dread-warrior')
        ->and(config('barks.archetypes.Abraxus'))->toBe('demon');
});

it('le jalon sous-boss d\'une campagne First Light tire un sous-boss DE SA BOÎTE, à chaque position d\'arc et pour chaque groupe', function () {
    foreach ([1, 2, 3, 4, 5] as $arc) {
        foreach ([1, 2, 3, 7] as $graine) {
            $achat = ajustementsSousBossDuTheme('first_light', $arc, $graine);

            expect($achat->tier)->toBe('sous_boss', "arc {$arc}, graine {$graine}")
                ->and(in_array('first_light', $achat->boites(), true))->toBeTrue("arc {$arc}, graine {$graine} : {$achat->nom_base} n'est pas de sa boîte")
                ->and(['Abraxus', 'Garde du Dread'])->toContain($achat->nom_base);
        }
    }
});

// ==================================================================
// 4. L'ARCHIMAGE ELFE — remesuré
// ==================================================================

it('remesure le cout de l\'Archimage elfe à 12 : 4,8 attaques sur la droite des boss SANS le Seigneur (2026-10-31)', function () {
    $archimage = Monstre::where('nom_base', 'Archimage elfe')->firstOrFail();

    expect((int) $archimage->cout)->toBe(12)
        ->and($archimage->tier)->toBe('boss');

    // La droite est `coutDroiteBoss()` (tests/Pest.php), posée sur les repères Ogre commandant, Seigneur ogre et Gruzbella,
    // jamais sur le Seigneur retiré. 4,8 attaques ⇒ 12.
    $attaquesArchimage = $archimage->pv_body / (1.5 - $archimage->defense / 6);

    expect(round($attaquesArchimage, 1))->toBe(4.8)
        ->and(coutDroiteBoss($attaquesArchimage))->toBe((int) $archimage->cout);
});

// ==================================================================
// 5. LA MIGRATION donne exactement ce que le seeder écrit
// ==================================================================

it('la migration des ajustements remet les lignes existantes à EXACTEMENT ce que le seeder écrit (et se défait)', function () {
    $ecrit = [
        'Grak' => (array) Monstre::where('nom_base', 'Grak')->firstOrFail()->capacites,
        'Barak' => (array) Monstre::where('nom_base', 'Seigneur Sorcier de Barak Tor')->firstOrFail()->capacites,
        'Archimage' => (int) Monstre::where('nom_base', 'Archimage elfe')->firstOrFail()->cout,
        'Garde' => (array) Monstre::where('nom_base', 'Garde du Dread')->firstOrFail()->boites_partagees,
        'pool' => (array) GabaritQuete::where('nom', 'Antre du sous-boss')->firstOrFail()->structure['rencontre_finale']['creatures'],
    ];

    // Les lignes telles qu'elles étaient AVANT le chantier.
    Monstre::where('nom_base', 'Grak')->update(['capacites' => []]);
    Monstre::where('nom_base', 'Seigneur Sorcier de Barak Tor')->update(['capacites' => []]);
    Monstre::where('nom_base', 'Archimage elfe')->update(['cout' => 17]);
    Monstre::where('nom_base', 'Garde du Dread')->update(['boites_partagees' => null]);
    $gabarit = GabaritQuete::where('nom', 'Antre du sous-boss')->firstOrFail();
    $structure = $gabarit->structure;
    $structure['rencontre_finale']['creatures'] = array_values(array_filter(
        $structure['rencontre_finale']['creatures'],
        fn (string $nom) => $nom !== 'Abraxus',
    ));
    $gabarit->update(['structure' => $structure]);

    $migration = require database_path('migrations/2026_10_31_120000_ajustements_jeu_de_base.php');
    $migration->up();
    $migration->up(); // rejouer ne double rien (Abraxus n'entre qu'une fois)

    expect((array) Monstre::where('nom_base', 'Grak')->firstOrFail()->capacites)->toBe($ecrit['Grak'])
        ->and((array) Monstre::where('nom_base', 'Seigneur Sorcier de Barak Tor')->firstOrFail()->capacites)->toBe($ecrit['Barak'])
        ->and((int) Monstre::where('nom_base', 'Archimage elfe')->firstOrFail()->cout)->toBe($ecrit['Archimage'])
        ->and((array) Monstre::where('nom_base', 'Garde du Dread')->firstOrFail()->boites_partagees)->toBe($ecrit['Garde'])
        ->and((array) GabaritQuete::where('nom', 'Antre du sous-boss')->firstOrFail()->structure['rencontre_finale']['creatures'])
        ->toBe($ecrit['pool']);

    $migration->down();

    expect((array) Monstre::where('nom_base', 'Grak')->firstOrFail()->capacites)->toBe([])
        ->and((int) Monstre::where('nom_base', 'Archimage elfe')->firstOrFail()->cout)->toBe(17)
        ->and((array) GabaritQuete::where('nom', 'Antre du sous-boss')->firstOrFail()->structure['rencontre_finale']['creatures'])
        ->not->toContain('Abraxus');
});
