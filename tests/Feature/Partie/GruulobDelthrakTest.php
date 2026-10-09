<?php

declare(strict_types=1);

use App\Events\JournalCombatDiffuse;
use App\Models\Evenement;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Models\SortDread;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
use App\Partie\EffetsGlobauxQuete;
use App\Partie\Grille;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\Sauvegarde;
use Illuminate\Support\Facades\Event;
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
 * GRUULOB, SORCIER GOBELIN CORROMPU — second boss à phases de *Jungles of Delthrak*
 * (livret F9907 p. 27, quête 8). Trois choses à tenir ensemble :
 *
 *  - le MOT-CLÉ `phases` (`monstres.phase_suivante`), lu au seul point de passage de
 *    la mort, `MoteurDegats::infligerAMonstre()` — Gruulob n'a aucune défense réactive
 *    ni « increvable », il ne passe donc que par la bascule de phase ;
 *  - la ROTATION du boss du thème : une forme suivante n'est jamais une entrée ;
 *  - la VARIANTE gobeline de *Summon Orcs* (« the same number of Goblins instead »).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, SortDreadSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

it('fait adopter à Gruulob sa forme démoniaque à 0 Body, sans le tuer, et garde son répertoire', function () {
    $ctx = demarrerQueteAvecMonstre('Gruulob, Sorcier Gobelin Corrompu');
    $instance = $ctx['instance'];
    $degats = app(MoteurDegats::class);

    expect($instance->monstre->nom_base)->toBe('Gruulob, Sorcier Gobelin Corrompu')
        ->and((int) $instance->pv_body)->toBe(4);

    // Premier coup fatal : pas de défense réactive, pas d'« increvable » — la phase suivante.
    $r1 = $degats->infligerAMonstre($instance, 4, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($r1['changement_phase'])->toBe([
        'avant' => 'Gruulob, Sorcier Gobelin Corrompu',
        'apres' => 'Gruulob, Forme Démoniaque',
    ])
        ->and($r1['vaincu'])->toBeFalse()
        // Body PLEIN de la nouvelle forme (3), Mind de la nouvelle forme (4).
        ->and((int) $instance->fresh()->pv_body)->toBe(3)
        ->and((int) $instance->fresh()->pv_mind)->toBe(4);

    // « still considered the same monster for game effects such as spells » : même
    // instance, même répertoire, puisque l'archétype est posé sur la forme aussi.
    $forme = $instance->fresh()->load('monstre')->monstre;
    expect($forme->nom_base)->toBe('Gruulob, Forme Démoniaque')
        ->and($forme->archetype_lanceur)->toBe('gruulob_sorcier_gobelin');
});

it('ne tue Gruulob qu\'à la dernière phase', function () {
    $ctx = demarrerQueteAvecMonstre('Gruulob, Sorcier Gobelin Corrompu');
    $instance = $ctx['instance'];
    $degats = app(MoteurDegats::class);

    $degats->infligerAMonstre($instance, 4, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    $forme = $instance->fresh()->load('monstre');
    $mort = $degats->infligerAMonstre($forme, 3, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($mort['vaincu'])->toBeTrue()
        ->and($mort['changement_phase'])->toBeNull()
        ->and($instance->fresh()->etat)->toBe('vaincu');
});

it('tire Gretzl ou Gruulob comme boss du thème Jungles, et JAMAIS une forme suivante', function () {
    // La rotation d'`acheterMonstres()` est privée : on l'appelle par réflexion, comme
    // le fait déjà le test de rotation de Gretzl, pour parcourir plusieurs graines et
    // plusieurs rangs d'arc sans attendre des campagnes réelles.
    $structure = (array) GabaritQuete::where('nom', 'Confrontation finale')->firstOrFail()->structure;
    $acheter = new ReflectionMethod(DemarreurQuete::class, 'acheterMonstres');
    $acheter->setAccessible(true);
    $bestiaire = BestiaireGroupe::auto('jungles_delthrak');

    $bosses = [];
    for ($graine = 0; $graine < 12; $graine++) {
        for ($arc = 1; $arc <= 3; $arc++) {
            $achats = $acheter->invoke(app(DemarreurQuete::class), $structure, 60, 6, $arc, $graine, $bestiaire);

            foreach ($achats as $monstre) {
                if ($monstre->tier === 'boss') {
                    $bosses[] = $monstre->nom_base;
                }
            }
        }
    }

    // Les deux premières phases, et elles seules : Demonspider, Demonape et Gruulob
    // Forme Démoniaque portent le même archétype que leur entrée — c'est l'exclusion de
    // `Monstre::nomsDeFormeSuivante()` qui les retient.
    expect(array_values(array_unique($bosses)))
        ->toEqualCanonicalizing(['Gretzl la Porte-Fléau', 'Gruulob, Sorcier Gobelin Corrompu']);
});

/**
 * Fait invoquer `$sort` par le lanceur du contexte — le vrai chemin de
 * `MoteurDread::sortDreadInvocation()`, appelé par réflexion pour fixer le jet du d6.
 *
 * @param  array<string, mixed>  $ctx
 * @return array<string, mixed>
 */
function gruulobInvoque(array $ctx, SortDread $sort, int $de): array
{
    desFiges([$de]);

    $methode = new ReflectionMethod(MoteurDread::class, 'sortDreadInvocation');
    $methode->setAccessible(true);

    return $methode->invoke(app(MoteurDread::class), $ctx['groupe'], $ctx['quete'], $ctx['instance'], $sort, []);
}

it('Invocation de gobelins a LA TABLE de l\'orque, des gobelins : 1-3 = 4, 4-5 = 5, 6 = 6', function () {
    // « Summon Orcs* (*Summons the same number of Goblins instead) » : même nombre au
    // même jet, espèce différente. On compare les deux tables côte à côte.
    $composition = new ReflectionMethod(MoteurDread::class, 'compositionInvoquee');
    $composition->setAccessible(true);
    $gobelins = SortDread::where('nom', 'Invocation de gobelins')->firstOrFail();
    $orques = SortDread::where('nom', "Invocation d'orques")->firstOrFail();

    foreach ([1 => 4, 3 => 4, 4 => 5, 5 => 5, 6 => 6] as $de => $nombre) {
        expect($composition->invoke(app(MoteurDread::class), $gobelins, $de))
            ->toBe(['Gobelin' => $nombre], "Invocation de gobelins, d6 = {$de}");
        expect($composition->invoke(app(MoteurDread::class), $orques, $de))
            ->toBe(['Orque' => $nombre], "Invocation d'orques, d6 = {$de}");
    }
});

it('Invocation de gobelins pose ses gobelins sur les cases LIBRES du lanceur, comme l\'orque', function () {
    $ctx = demarrerQueteAvecMonstre('Gruulob, Sorcier Gobelin Corrompu');
    $sort = SortDread::where('nom', 'Invocation de gobelins')->firstOrFail();

    // Le terrain borne le renfort : seules les cases orthogonalement libres du lanceur
    // reçoivent un sbire. On mesure ces cases AVANT l'invocation, qui les occupe.
    $libres = new ReflectionMethod(MoteurDread::class, 'casesLibresAdjacentes');
    $libres->setAccessible(true);
    $disponibles = count($libres->invoke(app(MoteurDread::class), $ctx['quete'], $ctx['instance']));

    $payload = gruulobInvoque($ctx, $sort, 6);

    expect($payload['de'])->toBe(6)
        ->and(count($payload['invoques']))->toBe(min(6, $disponibles))
        ->and(collect($payload['invoques'])->pluck('monstre')->unique()->values()->all())
        ->toBe($disponibles > 0 ? ['Gobelin'] : []);
});

/*
 * TIR AU CHOIX et EFFET GLOBAL DE QUÊTE (2026-10-09, livret F9907 p. 27, notes A et C).
 * Chaque test passe par le vrai chemin : le choix du héros (`POST /choix`) fait jouer
 * les monstres, et l'effet se lit dans les dés que `attaqueEffective()` compose.
 */

/**
 * Démarre une quête dont Gruulob est le SEUL monstre. La roster du démarrage ne se choisit
 * pas : on retire les autres exemplaires, pour que la liste des effets soit exacte.
 *
 * @return array<string, mixed>
 */
function demarrerGruulob(): array
{
    $ctx = demarrerQueteAvecMonstre('Gruulob, Sorcier Gobelin Corrompu');
    $ctx['quete']->instancesMonstres()->whereKeyNot($ctx['instance']->id)->delete();

    return $ctx;
}

/** Pose une figure de `$nomBase` dans la quête, active et révélée. */
function gruulobPoser(Quete $quete, string $nomBase): InstanceMonstre
{
    return InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => Monstre::where('nom_base', $nomBase)->value('id'),
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 0,
        'etat' => 'actif', 'revele' => true, 'elite' => false,
    ]);
}

/**
 * Pose `$instance` sur une case que la ligne de vue relie au héros, SANS adjacence (même
 * repérage que `MonstresADistanceTest`). Rend la case, pour vérifier qu'il n'a pas bougé.
 *
 * @param  array<string, mixed>  $ctx
 * @return array{x: int, y: int}
 */
function gruulobALDistanceEnVue(array $ctx, InstanceMonstre $instance): array
{
    $hx = (int) $ctx['etatHeros']->position_x;
    $hy = (int) $ctx['etatHeros']->position_y;
    $grille = Grille::depuisCarte($ctx['quete']->carte);

    foreach ($ctx['quete']->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true) || abs($x - $hx) + abs($y - $hy) < 2) {
                continue;
            }

            if ($grille->ligneDeVue($hx, $hy, $x, $y)) {
                $instance->update(['position_x' => $x, 'position_y' => $y]);

                return ['x' => $x, 'y' => $y];
            }
        }
    }

    throw new RuntimeException('Aucune case à distance en ligne de vue sur cette carte.');
}

/**
 * Un tour du héros (« attendre »), les monstres jouent : rend l'attaque de Gruulob, ou null.
 *
 * @param  array<string, mixed>  $ctx
 * @return array<string, mixed>|null
 */
function gruulobTourAttendre(array $ctx): ?array
{
    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    return collect($reponse->json('resultat.tour_monstres.actions'))->firstWhere('type', 'attaque_monstre');
}

it('tire à distance dans sa première forme, en ligne de vue, sans s\'approcher, avec ses 3 dés', function () {
    $ctx = demarrerGruulob();
    $gruulob = $ctx['instance'];
    $gruulob->update(['usages_dread' => 0]); // pas de sort : le test isole le tir

    $depart = gruulobALDistanceEnVue($ctx, $gruulob);
    desFiges(array_fill(0, 200, 4));

    $attaque = gruulobTourAttendre($ctx);

    expect($attaque['portee'] ?? null)->toBe('distance')
        ->and(count($attaque['faces_attaque'] ?? []))->toBe(3);

    // « may choose to fire at range » : il tire d'où il est, il ne referme pas la distance.
    $gruulob->refresh();
    expect([(int) $gruulob->position_x, (int) $gruulob->position_y])->toBe([$depart['x'], $depart['y']]);
});

it('tire à distance dans sa forme démoniaque aussi, avec ses 4 dés', function () {
    $ctx = demarrerGruulob();

    // La première forme ne meurt pas à 0 Body : elle adopte la forme démoniaque, même instance.
    app(MoteurDegats::class)->infligerAMonstre($ctx['instance'], 4, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    $gruulob = $ctx['instance']->fresh()->load('monstre');
    expect($gruulob->monstre->nom_base)->toBe('Gruulob, Forme Démoniaque');

    // Le répertoire reste lancé à la bascule : on coupe le sort APRÈS elle, pour isoler le tir.
    $gruulob->update(['usages_dread' => 0]);
    $depart = gruulobALDistanceEnVue($ctx, $gruulob);
    desFiges(array_fill(0, 200, 4));

    $attaque = gruulobTourAttendre($ctx);

    expect($attaque['portee'] ?? null)->toBe('distance')
        ->and(count($attaque['faces_attaque'] ?? []))->toBe(4);

    $gruulob->refresh();
    expect([(int) $gruulob->position_x, (int) $gruulob->position_y])->toBe([$depart['x'], $depart['y']]);
});

it('frappe au contact plutôt que de tirer : une figure collée ne lui laisse pas le tir', function () {
    $ctx = demarrerGruulob(); // posé AU CONTACT du héros par le démarrage
    $ctx['instance']->update(['usages_dread' => 0]);
    desFiges(array_fill(0, 200, 4));

    $attaque = gruulobTourAttendre($ctx);

    expect($attaque['portee'] ?? null)->toBe('corps_a_corps')
        ->and(count($attaque['faces_attaque'] ?? []))->toBe(3);
});

it('donne 1 dé d\'attaque de plus à tout gobelin de la quête, archer compris, et ni à Gruulob ni à l\'orque', function () {
    $ctx = demarrerGruulob();
    $quete = $ctx['quete'];
    EffetsGlobauxQuete::etablir($quete); // le démarrage : Gruulob est dans la roster

    $gobelin = gruulobPoser($quete, 'Gobelin');
    $archer = gruulobPoser($quete, 'Gobelin archer');
    $orque = gruulobPoser($quete, 'Orque');

    expect($ctx['instance']->fresh()->attaqueEffective())->toBe(3)  // Gruulob : ses 3 dés, sans bonus
        ->and($gobelin->fresh()->attaqueEffective())->toBe(2 + 1)   // Gobelin : attaque 2, +1
        ->and($archer->fresh()->attaqueEffective())->toBe(1 + 1)    // archer : attaque de contact 1, +1
        ->and($orque->fresh()->attaqueEffective())->toBe(3);        // orque : aucun effet ne la vise
});

it('ne donne aucun dé de plus aux gobelins d\'une quête sans Gruulob', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $quete = $ctx['quete'];
    $quete->instancesMonstres()->whereKeyNot($ctx['instance']->id)->delete();
    EffetsGlobauxQuete::etablir($quete);

    expect(EffetsGlobauxQuete::de($quete->refresh()))->toBe([])
        ->and($ctx['instance']->fresh()->attaqueEffective())->toBe(2); // Gobelin : sa fiche, rien de plus
});

it('garde l\'effet jusqu\'à la fin de la quête, même une fois Gruulob tombé (« in this quest »)', function () {
    $ctx = demarrerGruulob();
    $quete = $ctx['quete'];
    EffetsGlobauxQuete::etablir($quete);
    $gobelin = gruulobPoser($quete, 'Gobelin');

    $degats = app(MoteurDegats::class);
    $degats->infligerAMonstre($ctx['instance'], 4, MoteurDegats::SOURCE_ATTAQUE_HEROS); // forme démoniaque
    $degats->infligerAMonstre($ctx['instance']->fresh()->load('monstre'), 3, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($ctx['instance']->fresh()->etat)->toBe('vaincu');

    expect(collect(EffetsGlobauxQuete::de($quete->refresh()))->pluck('source')->all())
        ->toBe(['Gruulob, Sorcier Gobelin Corrompu'])
        ->and($gobelin->fresh()->attaqueEffective())->toBe(3);
});

it('annonce l\'effet au démarrage : au journal, au fil de la table et des manettes, et dans l\'état publié', function () {
    $texte = "Effet en jeu — Les gobelins de Gruulob : tous les gobelins de cette quête lancent 1 dé d'attaque de plus.";
    $ctx = demarrerGruulob();
    // Rafraîchi : la quête a été ouverte par la route, et `Journal::ajouter()` lit
    // `quete_courante_id` sur CET objet (le démarrage réel l'a mis à jour en mémoire).
    $groupe = $ctx['groupe']->refresh();
    $quete = $ctx['quete'];

    // Le démarrage réel fige puis annonce (`DemarreurQuete::demarrer()`) ; la roster de ce test
    // porte Gruulob posé après coup, on rejoue donc ces deux étapes sur cette roster.
    Event::fake([JournalCombatDiffuse::class]);
    EffetsGlobauxQuete::etablir($quete);
    EffetsGlobauxQuete::annoncer($groupe, $quete);

    // 1. Le journal : une entrée `combat` qui porte la phrase.
    $entree = Evenement::where('groupe_id', $groupe->id)->where('type', 'combat')->get()
        ->first(fn (Evenement $e) => is_array($e->payload) && isset($e->payload['effets_globaux_annonces']));
    expect($entree)->not->toBeNull()
        ->and($entree->payload['effets_globaux_annonces'][0]['texte'] ?? null)->toBe($texte);

    // 2. Le fil direct, sur le canal du groupe : la table et les manettes le reçoivent.
    Event::assertDispatched(JournalCombatDiffuse::class,
        fn (JournalCombatDiffuse $e) => collect($e->lignes)->pluck('texte')->contains($texte));

    // 3. L'état publié : le bandeau, et la reconnexion (`journal_combat`).
    $etat = test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/etat')->assertOk()->json();

    expect(collect($etat['quete']['effets_globaux'] ?? [])->pluck('texte')->all())->toBe([$texte])
        ->and(collect($etat['quete']['effets_globaux'] ?? [])->first()['source'] ?? null)->toBe('Gruulob, Sorcier Gobelin Corrompu')
        ->and(collect($etat['journal_combat'] ?? [])->pluck('texte')->contains($texte))->toBeTrue();
});

it('porte les effets dans l\'instantané de début de quête, pour la reprise', function () {
    $ctx = demarrerGruulob();
    EffetsGlobauxQuete::etablir($ctx['quete']);

    $snapshot = app(Sauvegarde::class)->snapshotter($ctx['groupe']->refresh(), Sauvegarde::ETIQUETTE_DEBUT_QUETE);

    expect(collect($snapshot->fresh()->etat['quete']['effets_globaux'] ?? [])->pluck('source')->all())
        ->toBe(['Gruulob, Sorcier Gobelin Corrompu']);
});

it('lit la liste sur la roster d\'une quête ouverte avant la colonne, sans l\'écrire', function () {
    $ctx = demarrerGruulob();
    $quete = $ctx['quete'];
    $quete->update(['effets_globaux' => null]); // une quête ouverte avant la migration

    expect(collect(EffetsGlobauxQuete::de($quete->refresh()))->pluck('source')->all())
        ->toBe(['Gruulob, Sorcier Gobelin Corrompu'])
        ->and($quete->refresh()->effets_globaux)->toBeNull();
});
