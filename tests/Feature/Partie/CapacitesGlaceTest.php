<?php

declare(strict_types=1);

use App\Engine\ReactionEffet;
use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Snapshot;
use App\Models\Sort;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Partie\Sauvegarde;
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

/**
 * The Frozen Horror — étreinte du Yéti et vol du Gremlin des glaces
 * (doc 18 §2, plan glace phase 3).
 *
 * `BestiaireSourceTest` gèle les deux capacités (`etreinte`, `vol_objet`) et
 * prouve qu'un LECTEUR existe ; ce fichier prouve qu'il fait ce que la carte
 * dit, EN JEU — sur les vraies routes, comme le reste du projet le fait pour
 * chaque capacité de créature.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

// ---------------------------------------------------------------------------
// Étreinte du Yéti
// ---------------------------------------------------------------------------

it("le Yéti n'agrippe QUE s'il inflige au moins 1 Body", function () {
    $ctx = demarrerQueteAvecMonstre('Yéti');
    $heros = $ctx['heros'];

    // Tous les dés à 4 (bouclier blanc) : 0 crâne côté attaque, donc 0 touche
    // et 0 dégât — quel que soit le nombre de dés de défense consommés
    // derrière.
    desFiges(array_fill(0, 30, 4));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 30, 4));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    expect($heros->fresh()->conditions()->where('nom', 'Agrippé')->exists())
        ->toBeFalse('un coup sans dégât ne doit pas établir la prise');
});

it('établit la prise dès que le Yéti inflige au moins 1 Body', function () {
    $ctx = demarrerQueteAvecMonstre('Yéti');
    $heros = $ctx['heros'];
    $pvAvant = (int) $heros->pv_body;

    // Tout à 1 (crâne) : 3 touches côté Yéti, 0 bouclier côté héros — le coup
    // porte à coup sûr.
    desFiges(array_fill(0, 30, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 30, 1));

    $response = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    expect((int) $heros->fresh()->pv_body)->toBeLessThan($pvAvant)
        ->and($heros->fresh()->conditions()->where('nom', 'Agrippé')->exists())->toBeTrue()
        // Un effet automatique que rien n'annonce est injouable : le coup qui
        // établit la prise le dit dans son propre payload.
        ->and($response->json('resultat.tour_monstres.actions.0.etreinte_etablie'))->toBeTrue()
        ->and($response->json('resultat.tour_monstres.actions.0.type'))->toBe('attaque_monstre');
});

it("prive la victime de déplacement ET d'action tant qu'elle est agrippée", function () {
    $ctx = demarrerQueteAvecMonstre('Yéti');
    $heros = $ctx['heros'];

    $condition = Condition::where('nom', 'Agrippé')->firstOrFail();
    $heros->conditions()->syncWithoutDetaching([
        $condition->id => ['duree' => 0, 'source' => "etreinte:{$ctx['instance']->id}"],
    ]);

    $sorts = app(MoteurSorts::class);

    expect($sorts->deplacementInterdit($heros->fresh()))->toBeTrue()
        ->and($sorts->actionInterdite($heros->fresh()))->toBeTrue();
});

it('sature la victime de 2 Body AUTOMATIQUES, sans le moindre jet, au tour suivant du MJ', function () {
    $ctx = demarrerQueteAvecMonstre('Yéti');
    $heros = $ctx['heros'];

    // Round 1 : le Yéti touche et agrippe.
    desFiges(array_fill(0, 30, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 30, 1));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect($heros->fresh()->conditions()->where('nom', 'Agrippé')->exists())->toBeTrue();
    $pvApresRound1 = (int) $heros->fresh()->pv_body;

    // Round 2 : la prise saigne — SANS jet de défense, contrairement à un coup
    // normal (`MoteurDegats::infligerAHeros()` est appelé directement, aucun
    // `Engine\Combat` en chemin). La file reste généreuse : le menu d'un héros
    // bloqué roule quand même un d6 pour le saut de piège (`pointsRestants()`
    // n'est pas gardé par `deplacementInterdit()`), un détail de `MenuMoteur`
    // sans rapport avec l'étreinte.
    desFiges(array_fill(0, 30, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 30, 1));

    $response = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    expect((int) $heros->fresh()->pv_body)->toBe(max(0, $pvApresRound1 - 2));
});

it("le Yéti n'attaque plus tant qu'il tient sa victime — le tour suivant ne joue que l'étreinte", function () {
    $ctx = demarrerQueteAvecMonstre('Yéti');
    $heros = $ctx['heros'];

    // Round 1 : établit la prise via une vraie attaque.
    desFiges(array_fill(0, 30, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 30, 1));
    $r1 = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect($r1->json('resultat.tour_monstres.actions.0.type'))->toBe('attaque_monstre');

    // Round 2 : le Yéti tient toujours — « ne peut faire aucune autre
    // attaque ». Généreux en dés (voir le test précédent) : ils ne servent
    // qu'au menu, jamais à l'étreinte elle-même.
    desFiges(array_fill(0, 30, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 30, 1));
    $r2 = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $actions = $r2->json('resultat.tour_monstres.actions');

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['type'])->toBe('etreinte_maintenue')
        ->and($actions[0]['type'])->not->toBe('attaque_monstre')
        ->and($actions[0]['cible']['personnage_id'])->toBe($heros->id);
});

it('la mort du Yéti libère IMMÉDIATEMENT la victime — plus de saignement, plus de blocage', function () {
    $ctx = demarrerQueteAvecMonstre('Yéti');
    $heros = $ctx['heros'];

    $condition = Condition::where('nom', 'Agrippé')->firstOrFail();
    $heros->conditions()->syncWithoutDetaching([
        $condition->id => ['duree' => 0, 'source' => "etreinte:{$ctx['instance']->id}"],
    ]);

    // Le Yéti meurt — peu importe le chemin (frappe, sort, eau bénite…), on
    // en fixe seulement le résultat : l'instance n'est plus `actif`.
    $ctx['instance']->update(['etat' => 'vaincu']);

    $pvAvant = (int) $heros->fresh()->pv_body;

    // ⚠ Ce Yéti était le SEUL monstre actif de la quête : le tuer nettoie le
    // donjon (`donjonNettoye()`), un chemin qui peut consommer des dés sans
    // rapport avec l'étreinte (fouille, montée de niveau…) — on ne fige donc
    // PAS une file vide ici, contrairement au test de saignement ci-dessus où
    // le Yéti reste `actif` et le combat continue.
    desFiges(array_fill(0, 30, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 30, 1));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $sorts = app(MoteurSorts::class);

    expect((int) $heros->fresh()->pv_body)->toBe($pvAvant, 'plus de saignement une fois le Yéti mort')
        ->and($heros->fresh()->conditions()->where('nom', 'Agrippé')->exists())->toBeFalse('la prise doit être libérée')
        ->and($sorts->deplacementInterdit($heros->fresh()))->toBeFalse()
        ->and($sorts->actionInterdite($heros->fresh()))->toBeFalse();
});

it("le saignement de l'étreinte NE PROPOSE AUCUNE réaction hors tour", function () {
    // Même règle que le poison et les jetons de Rejeton : les cartes
    // réactives (Ailes sombres, Torrent Tournoyant) parlent d'un COUP reçu ;
    // ceci est une hémorragie. `SOURCE_ETREINTE` doit rester hors de
    // `ReactionEffet::SOURCES_REACTIVES`.
    $ctx = demarrerQueteAvecMonstre('Yéti');
    $heros = $ctx['heros'];

    // On arme un sort réactif : si la source était (à tort) réactive, une
    // offre serait forcément postée.
    $sort = Sort::query()->firstOrFail();
    $sort->update(['effet' => [...$sort->effet, 'reaction' => [
        'sur' => ReactionEffet::SUR_DEGATS_SUBIS,
        'action' => ReactionEffet::ANNULE_DEGATS,
    ]]]);
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => true]]);

    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ETREINTE);

    expect((int) $heros->fresh()->pv_body)->toBe((int) $heros->pv_body_max - 2)
        ->and($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();
});

// ---------------------------------------------------------------------------
// Vol du Gremlin des glaces
// ---------------------------------------------------------------------------

it("le Gremlin vole un objet du sac, JAMAIS une pièce équipée (arme/armure/bouclier)", function () {
    $ctx = demarrerQueteAvecMonstre('Gremlin des glaces');
    $heros = $ctx['heros'];
    $epee = Objet::where('nom', 'Épée large')->firstOrFail();

    // La même arme, deux fois : une portée (jamais volable), une au sac
    // (volable) — la preuve la plus stricte que seul l'emplacement compte.
    Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $epee->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
    Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $epee->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    $vol = app(MoteurDread::class)->voler(
        $ctx['groupe'], $ctx['quete'], $ctx['instance'],
        new \Illuminate\Database\Eloquent\Collection([$ctx['etatHeros']->fresh()]),
        ['type' => 'monstre', 'id' => $ctx['instance']->id, 'nom' => 'Gremlin des glaces'],
    );

    expect($vol)->not->toBeNull()
        ->and($vol['type'])->toBe('vol_objet')
        ->and($vol['objet'])->toBe('Épée large');

    expect(Inventaire::where('personnage_id', $heros->id)->where('emplacement', 'arme_principale')->exists())
        ->toBeTrue("l'arme ÉQUIPÉE doit rester en place")
        ->and(Inventaire::where('personnage_id', $heros->id)->where('emplacement', 'sac')->exists())
        ->toBeFalse('la copie au SAC doit avoir disparu');
});

it("ne vole rien et n'attaque pas non plus si personne n'est au contact", function () {
    $ctx = demarrerQueteAvecMonstre('Gremlin des glaces');

    // Le gremlin est isolé : `voler()` doit rendre null (aucune action) sans
    // toucher à quoi que ce soit.
    $loin = $ctx['instance'];
    $loin->update(['position_x' => 0, 'position_y' => 0]);

    $vol = app(MoteurDread::class)->voler(
        $ctx['groupe'], $ctx['quete'], $loin,
        new \Illuminate\Database\Eloquent\Collection([$ctx['etatHeros']->fresh()]),
        ['type' => 'monstre', 'id' => $loin->id, 'nom' => 'Gremlin des glaces'],
    );

    expect($vol)->toBeNull();
});

it("rend l'objet volé INTACT (charges et améliorations de Forge comprises) si on tue le Gremlin avant qu'il sorte de vue", function () {
    $ctx = demarrerQueteAvecMonstre('Gremlin des glaces');
    $heros = $ctx['heros'];
    $epee = Objet::where('nom', 'Épée large')->firstOrFail();

    // Une amélioration de Forge sur l'exemplaire volé : la ligne doit être
    // DÉPLACÉE (jamais recréée), sous peine de la perdre à la récupération —
    // même précaution que `DonObjet`.
    Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $epee->id,
        'emplacement' => 'sac', 'quantite' => 1, 'charges' => 3,
        'ameliorations' => [['nom' => 'Fil aiguisé', 'effet' => ['bonus_des_attaque' => 1]]],
    ]);

    app(MoteurDread::class)->voler(
        $ctx['groupe'], $ctx['quete'], $ctx['instance'],
        new \Illuminate\Database\Eloquent\Collection([$ctx['etatHeros']->fresh()]),
        ['type' => 'monstre', 'id' => $ctx['instance']->id, 'nom' => 'Gremlin des glaces'],
    );

    expect(Inventaire::where('personnage_id', $heros->id)->where('emplacement', 'sac')->exists())->toBeFalse();
    expect((array) data_get($ctx['instance']->fresh()->habillage, 'vol_objet'))->not->toBe([]);

    // Le Gremlin est tué avant de sortir de vue — peu importe le chemin.
    $ctx['instance']->update(['etat' => 'vaincu']);

    desFiges(array_fill(0, 30, 1)); // le héros n'est pas bloqué ici : le menu peut lancer le d6 de déplacement
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $recuperee = Inventaire::where('personnage_id', $heros->id)
        ->where('emplacement', 'sac')->where('objet_id', $epee->id)->first();

    expect($recuperee)->not->toBeNull("l'objet doit revenir")
        ->and((int) $recuperee->charges)->toBe(3, 'les charges doivent revenir INTACTES')
        ->and($recuperee->ameliorations)->toBe(
            [['nom' => 'Fil aiguisé', 'effet' => ['bonus_des_attaque' => 1]]],
            "l'amélioration de Forge doit revenir INTACTE",
        )
        ->and((array) data_get($ctx['instance']->fresh()->habillage, 'vol_objet'))->toBe([]);
});

it("PERD l'objet pour de bon si aucun héros ne le voit au début du tour suivant du MJ", function () {
    $ctx = demarrerQueteAvecMonstre('Gremlin des glaces');
    $heros = $ctx['heros'];
    $etatHeros = $ctx['etatHeros']->fresh();
    $epee = Objet::where('nom', 'Épée large')->firstOrFail();

    Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $epee->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    app(MoteurDread::class)->voler(
        $ctx['groupe'], $ctx['quete'], $ctx['instance'], new \Illuminate\Database\Eloquent\Collection([$etatHeros]),
        ['type' => 'monstre', 'id' => $ctx['instance']->id, 'nom' => 'Gremlin des glaces'],
    );

    // Une case du donjon hors de la ligne de vue du héros — exactement le
    // test que fait `MoteurDread::ciblesEnVue()`.
    $grille = FabriqueGrille::pour($ctx['quete'], exceptInstanceId: $ctx['instance']->id);
    $cases = $ctx['quete']->carte->grille['cases'];
    $cachee = null;

    foreach ($cases as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true)) {
                continue;
            }
            if (! $grille->ligneDeVue($x, $y, (int) $etatHeros->position_x, (int) $etatHeros->position_y, figuresBloquent: true)) {
                $cachee = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }

    if ($cachee === null) {
        $this->markTestSkipped('Aucune case hors de vue — géométrie de carte défavorable.');
    }

    $ctx['instance']->update(['position_x' => $cachee['x'], 'position_y' => $cachee['y']]);

    $perdu = app(MoteurDread::class)->voler(
        $ctx['groupe'], $ctx['quete'], $ctx['instance']->fresh(), new \Illuminate\Database\Eloquent\Collection([$etatHeros->fresh()]),
        ['type' => 'monstre', 'id' => $ctx['instance']->id, 'nom' => 'Gremlin des glaces'],
    );

    expect($perdu)->toBeNull('le contrôle de vue seul ne consomme pas le tour')
        ->and((array) data_get($ctx['instance']->fresh()->habillage, 'vol_objet'))->toBe([]);

    // Même en tuant le Gremlin après coup, l'objet ne revient PLUS : il est
    // perdu, pas simplement égaré.
    $ctx['instance']->update(['etat' => 'vaincu']);

    desFiges(array_fill(0, 30, 1)); // le héros n'est pas bloqué ici : le menu peut lancer le d6 de déplacement
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect(Inventaire::where('personnage_id', $heros->id)->where('objet_id', $epee->id)->exists())->toBeFalse();
});

it('le butin volé du Gremlin survit à une REPRISE (snapshot) — `habillage` ride déjà le snapshot', function () {
    $ctx = demarrerQueteAvecMonstre('Gremlin des glaces');
    $heros = $ctx['heros'];
    $epee = Objet::where('nom', 'Épée large')->firstOrFail();

    Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $epee->id,
        'emplacement' => 'sac', 'quantite' => 1,
        'ameliorations' => [['nom' => 'Fil aiguisé', 'effet' => ['bonus_des_attaque' => 1]]],
    ]);

    app(MoteurDread::class)->voler(
        $ctx['groupe'], $ctx['quete'], $ctx['instance'], new \Illuminate\Database\Eloquent\Collection([$ctx['etatHeros']->fresh()]),
        ['type' => 'monstre', 'id' => $ctx['instance']->id, 'nom' => 'Gremlin des glaces'],
    );

    $vol = data_get($ctx['instance']->fresh()->habillage, 'vol_objet');
    expect($vol)->not->toBeNull();

    app(Sauvegarde::class)->snapshotter($ctx['groupe']->fresh(), 'nouveau_tour');

    // On efface l'ardoise, comme si le groupe reprenait la partie après une
    // coupure, puis on restaure.
    $ctx['instance']->update(['habillage' => null]);

    $snapshot = Snapshot::where('groupe_id', $ctx['groupe']->id)->orderByDesc('id')->firstOrFail();
    app(Sauvegarde::class)->restaurer($ctx['groupe']->fresh(), $snapshot);

    $restaure = InstanceMonstre::findOrFail($ctx['instance']->id);

    expect(data_get($restaure->habillage, 'vol_objet'))->toBe($vol);
});
