<?php

declare(strict_types=1);

use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Quete;
use App\Models\Sort;
use App\Models\SortDread;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\MoteurMobilier;
use App\Partie\MoteurSorts;
use App\Partie\OubliSorts;
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
 * LES TROIS SORCIERS DU DREAD DE MORCAR (vague 2A) — Storm Master, High Mage,
 * Necromancer — joués EN JEU, une preuve par mécanique neuve.
 *
 * Le catalogue et le vocabulaire sont verrouillés par `SortsDreadSourcesTest` ;
 * ici on joue la phase des monstres par les vraies routes, dés figés.
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

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Quête où un Sorcier ne connaît QUE le sort demandé (l'archétype est écarté).
 *
 * @return array{alice: mixed, groupe: mixed, heros: App\Models\Personnage, quete: Quete, instance: InstanceMonstre, etatHeros: EtatPersonnageQuete}
 */
function sorcierAvecSort(string $sort, string $monstre = 'Maître des orages'): array
{
    $ctx = demarrerQueteAvecMonstre($monstre);
    $ctx['instance']->monstre->update(['sorts_dread' => [$sort], 'archetype_lanceur' => null, 'capacites' => ['sorts_uniques']]);
    $ctx['instance']->update(['pv_body_max' => (int) $ctx['instance']->monstre->pv_body]);
    $ctx['instance']->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($ctx['instance'], $ctx['quete']);
    $ctx['instance']->refresh();

    return $ctx;
}

/**
 * Aligne le sorcier et le héros sur une rangée libre de $longueur cases : le
 * sorcier en tête, le héros $ecart cases plus loin. Les autres monstres sont
 * déjà « vaincus » (`demarrerQueteAvecMonstre()`).
 *
 * @return array{x0: int, y: int}
 */
function aligner(array $ctx, int $longueur = 8, int $ecart = 1): array
{
    // Portes ouvertes : une porte close couperait la ligne de vue du Sorcier.
    ouvrirToutesLesPortes($ctx['quete']);
    // …et le mobilier retiré : un meuble coupe la vue comme le passage.
    $carte = $ctx['quete']->carte;
    $grilleCarte = $carte->grille;
    $grilleCarte['mobilier'] = [];
    $carte->update(['grille' => $grilleCarte]);
    $ctx['quete']->refresh();
    $cases = $ctx['quete']->carte->grille['cases'];

    foreach ($cases as $y => $ligne) {
        for ($x = 0; $x + $longueur <= count($ligne); $x++) {
            $libre = true;

            for ($i = 0; $i < $longueur; $i++) {
                if (($cases[$y][$x + $i] ?? 'm') !== 's') {
                    $libre = false;

                    break;
                }
            }

            if ($libre) {
                $ctx['instance']->update(['position_x' => $x, 'position_y' => $y]);
                $ctx['etatHeros']->update(['position_x' => $x + $ecart, 'position_y' => $y]);
                $ctx['quete']->refresh();

                return ['x0' => $x, 'y' => $y];
            }
        }
    }

    throw new RuntimeException('Aucune rangée libre de '.$longueur.' cases — scénario de test invalide.');
}

/** Phase des monstres déclenchée par « Terminer le tour » ; rend les actions. */
function phaseSorcier(array $ctx, array $des = []): array
{
    desFiges([...$des, ...array_fill(0, 300, 1)]);

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    return collect($reponse->json('resultat.tour_monstres.actions'))->all();
}

function sortLance(array $actions, string $nom): ?array
{
    return collect($actions)->firstWhere('sort', $nom);
}

// ==================================================================
// Catalogue : les trois sorciers
// ==================================================================

it('porte les trois Sorciers aux stats du tableau du livret (G1504 p. 40-41), sous-boss (lieutenants), six sorts, sorts uniques', function () {
    $attendus = [
        'Maître des orages' => [[6, 4, 6, 5, 7], 'orages_morcar'],
        'Haut mage' => [[5, 5, 5, 4, 8], 'haut_mage_morcar'],
        'Nécromancien' => [[6, 4, 6, 4, 7], 'necromancien_morcar'],
    ];

    foreach ($attendus as $nom => [$stats, $archetype]) {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();

        expect([$m->deplacement, $m->attaque, $m->defense, $m->pv_body, $m->pv_mind])->toBe($stats, "{$nom} : stats")
            ->and($m->tier)->toBe('sous_boss') // lieutenants de Morcar (vague 2C) ; la Gardienne est le seul boss
            ->and($m->boite)->toBe('wizards_of_morcar')
            ->and($m->archetype_lanceur)->toBe($archetype)
            ->and(array_keys(array_flip((array) $m->capacites)))->toContain('sorts_uniques')
            ->and(config("archetypes_lanceurs.{$archetype}.sorts"))->toHaveCount(6);
    }
});

it('ne donne un sort à un Sorcier que dans SON répertoire (« only they may use it »)', function () {
    $tous = collect(['orages_morcar', 'haut_mage_morcar', 'necromancien_morcar'])
        ->flatMap(fn ($a) => config("archetypes_lanceurs.{$a}.sorts"));

    // Frayeur et Fuite sont des doublons exacts de cartes déjà portées ; tous les
    // autres noms sont propres à un seul sorcier.
    $propres = $tous->reject(fn ($n) => in_array($n, ['Frayeur', 'Fuite'], true));

    expect($propres->duplicates()->all())->toBe([]);
});

// ==================================================================
// « Each spell may only be used once per quest »
// ==================================================================

it('lance chaque sort une seule fois par quête, puis l\'épuise (sorts_uniques)', function () {
    $ctx = demarrerQueteAvecMonstre('Nécromancien');
    $instance = $ctx['instance'];

    // Budget = le répertoire entier ; la liste des sorts lancés repart à vide.
    expect((int) $instance->usages_dread)->toBe(6)
        ->and((array) $instance->sorts_dread_lances)->toBe([]);

    // Un seul sort exploitable ici : Trait de mort, cible unique, héros au contact.
    $instance->monstre->update(['archetype_lanceur' => null, 'sorts_dread' => ['Trait de mort']]);
    $instance->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $ctx['quete']);

    $actions = phaseSorcier($ctx);
    expect(sortLance($actions, 'Trait de mort'))->not->toBeNull()
        ->and((array) $instance->fresh()->sorts_dread_lances)->toBe(['Trait de mort'])
        ->and((int) $instance->fresh()->usages_dread)->toBe(0);

    // Deuxième tour : le sort est épuisé, le Sorcier frappe comme n'importe quel monstre.
    $actions = phaseSorcier($ctx);
    expect(sortLance($actions, 'Trait de mort'))->toBeNull();

    // « At the beginning of a new quest, each Sorcerer starts with a full set. »
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance->fresh()->load('monstre'), $ctx['quete']);
    expect((array) $instance->fresh()->sorts_dread_lances)->toBe([]);
});

// ==================================================================
// STORM MASTER
// ==================================================================

it('Foudroiement : 3 dés de combat en ligne droite, la défense s\'applique', function () {
    $ctx = sorcierAvecSort('Foudroiement');
    aligner($ctx);
    $avant = (int) $ctx['heros']->pv_body;

    // 3 dés d'attaque : 3 crânes ; 2 dés de défense du héros : 2 crânes (aucun bouclier).
    $actions = phaseSorcier($ctx, [1, 1, 1, 1, 1]);
    $sort = sortLance($actions, 'Foudroiement');

    expect($sort)->not->toBeNull()
        ->and($sort['resultats'][0]['touches'])->toBe(3)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($avant - 3);
});

it('Foudroiement : un mur magique l\'annule, le mur disparaît, les cases d\'avant sont frappées', function () {
    $ctx = sorcierAvecSort('Foudroiement');
    ['x0' => $x0, 'y' => $y] = aligner($ctx);
    $avant = (int) $ctx['heros']->pv_body;

    // Héros en x0+1, mur en x0+3/x0+4 : la ligne s'arrête avant lui.
    app(MoteurMobilier::class)->poserMurMagique($ctx['quete']->carte, [['x' => $x0 + 3, 'y' => $y], ['x' => $x0 + 4, 'y' => $y]], 'Mur de Glace');
    $ctx['quete']->refresh();

    $actions = phaseSorcier($ctx, [1, 1, 1, 1, 1]);
    $sort = sortLance($actions, 'Foudroiement');

    expect($sort['mur_annule']['nom'])->toBe('Mur de Glace')
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($avant - 3, 'le héros AVANT le mur est frappé');

    $carte = $ctx['quete']->carte->fresh();
    expect(app(MoteurMobilier::class)->murMagiqueSur($carte, $x0 + 3, $y))->toBeNull('le mur est retiré du plateau')
        ->and(FabriqueGrille::pour($ctx['quete']->fresh())->estTraversable($x0 + 3, $y))->toBeTrue();
});

it('Foudroiement : la portée est de 6 cases', function () {
    $ctx = sorcierAvecSort('Foudroiement');
    aligner($ctx, 9, 7); // héros à 7 cases : hors du rayon de 6

    $actions = phaseSorcier($ctx, [1, 1, 1, 1, 1]);

    expect(sortLance($actions, 'Foudroiement'))->toBeNull();
});

it('Tremblement de terre : 1 PV sans aucun jet, et le mur magique l\'annule aussi', function () {
    $ctx = sorcierAvecSort('Tremblement de terre');
    ['x0' => $x0, 'y' => $y] = aligner($ctx);
    $avant = (int) $ctx['heros']->pv_body;
    app(MoteurMobilier::class)->poserMurMagique($ctx['quete']->carte, [['x' => $x0 + 3, 'y' => $y], ['x' => $x0 + 4, 'y' => $y]], 'Mur de Feu');
    $ctx['quete']->refresh();

    $actions = phaseSorcier($ctx);
    $sort = sortLance($actions, 'Tremblement de terre');

    expect((int) $ctx['heros']->fresh()->pv_body)->toBe($avant - 1)
        ->and($sort['mur_annule']['nom'])->toBe('Mur de Feu');
});

it('Muraille de glace : dresse un mur de DEUX cases sur le mobilier attaquable, jamais au contact', function () {
    $ctx = sorcierAvecSort('Muraille de glace');
    ['x0' => $x0, 'y' => $y] = aligner($ctx, 8, 5);

    $actions = phaseSorcier($ctx);
    $sort = sortLance($actions, 'Muraille de glace');

    expect($sort['mur_magique'])->toBeTrue()
        ->and($sort['cases'])->toHaveCount(2)
        ->and($sort['mobilier']['nom'])->toBe('Mur de Glace')
        ->and($sort['mobilier']['pv_body'])->toBe(1)
        ->and($sort['mobilier']['defense_dice'])->toBe(6);

    $grille = FabriqueGrille::pour($ctx['quete']->fresh());
    foreach ($sort['cases'] as $case) {
        expect($grille->estTraversable($case['x'], $case['y']))->toBeFalse('le mur bloque le passage');
    }

});

it('Muraille de glace : jamais lancée contre un héros déjà au contact (aucun mur utile)', function () {
    $ctx = sorcierAvecSort('Muraille de glace');

    expect(sortLance(phaseSorcier($ctx), 'Muraille de glace'))->toBeNull();
});

it('Vent voleur : une pièce portée tirée au hasard quitte l\'inventaire', function () {
    $ctx = sorcierAvecSort('Vent voleur');
    $ctx['heros']->inventaire()->create(['objet_id' => App\Models\Objet::where('nom', 'Épée longue')->value('id'), 'emplacement' => 'arme_principale']);
    $avant = $ctx['heros']->inventaire()
        ->whereIn('emplacement', ['arme_principale', 'arme_secondaire', 'casque', 'armure', 'talisman', 'bottes'])
        ->count();
    expect($avant)->toBeGreaterThan(0);

    $actions = phaseSorcier($ctx);
    $sort = sortLance($actions, 'Vent voleur');

    expect($sort['resultats'][0]['arrache'])->toBeTrue()
        ->and($sort['resultats'][0]['objet_detruit'])->not->toBeEmpty()
        ->and($ctx['heros']->inventaire()->whereIn('emplacement', ['arme_principale', 'arme_secondaire', 'casque', 'armure', 'talisman', 'bottes'])->count())
        ->toBe($avant - 1);
});

it('Ouragan : repousse le héros aligné, à l\'opposé, jusqu\'au mur', function () {
    $ctx = sorcierAvecSort('Ouragan');
    ['x0' => $x0, 'y' => $y] = aligner($ctx, 8, 1);

    $actions = phaseSorcier($ctx);
    $sort = sortLance($actions, 'Ouragan');

    expect($sort['repousse']['de'])->toBe(['x' => $x0 + 1, 'y' => $y])
        ->and($sort['repousse']['vers']['y'])->toBe($y)
        ->and($sort['repousse']['vers']['x'])->toBeGreaterThan($x0 + 1)
        ->and($sort['repousse']['cases'])->toBeGreaterThan(0);

    $etat = $ctx['etatHeros']->fresh();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([$sort['repousse']['vers']['x'], $sort['repousse']['vers']['y']]);
});

it('Ouragan : une fosse sur la ligne arrête le héros et le blesse', function () {
    $ctx = sorcierAvecSort('Ouragan');
    ['x0' => $x0, 'y' => $y] = aligner($ctx, 8, 1);
    $carte = $ctx['quete']->carte;
    $grille = $carte->grille;
    $grille['pieges'] = [[
        'x' => $x0 + 4, 'y' => $y, 'piege_id' => App\Models\Piege::where('nom', 'Fosse')->value('id'), 'etat' => 'cache',
    ]];
    $carte->update(['grille' => $grille]);
    $ctx['quete']->load('carte');
    $avant = (int) $ctx['heros']->pv_body;

    $actions = phaseSorcier($ctx);
    $sort = sortLance($actions, 'Ouragan');

    expect($sort['repousse']['vers'])->toBe(['x' => $x0 + 4, 'y' => $y])
        ->and($sort['repousse']['declenchements'])->not->toBeEmpty()
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBeLessThan($avant);
});

it('Grésil aveuglant : ni déplacement, ni sort, ni tir jusqu\'au début du prochain tour du MJ, puis il tombe', function () {
    $ctx = demarrerQueteAvecMonstre('Maître des orages');
    $ctx['instance']->monstre->update(['archetype_lanceur' => null, 'sorts_dread' => ['Grésil aveuglant']]);
    $ctx['instance']->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($ctx['instance'], $ctx['quete']);

    $actions = phaseSorcier($ctx);
    $sort = sortLance($actions, 'Grésil aveuglant');
    $moteur = app(MoteurSorts::class);
    $heros = $ctx['heros']->fresh();

    expect($sort['resultats'][0]['effet_applique'])->toBeTrue()
        ->and($moteur->sortsInterdits($heros))->toBeTrue()
        ->and($moteur->tirInterdit($heros))->toBeTrue()
        ->and($moteur->deplacementInterdit($heros))->toBeTrue('la condition a survécu à la fin du round');

    // Le menu n'offre plus de déplacement (lecteur existant de deplacement_interdit).
    $menu = test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();
    expect(collect($menu->json('menu.options'))->pluck('type')->all())->not->toContain('deplacement');

    // Au début de la phase des monstres suivante : levée ET annoncée.
    $suivante = phaseSorcier($ctx);
    expect(collect($suivante)->firstWhere('type', 'conditions_levees'))->not->toBeNull()
        ->and($moteur->sortsInterdits($ctx['heros']->fresh()))->toBeFalse();
});

it('Grésil aveuglant : le résolveur refuse un sort ET un tir, au même prédicat que le menu', function () {
    expect(App\Partie\MenuMoteur::estLancerDeSort(['type' => 'sort']))->toBeTrue()
        ->and(App\Partie\MenuMoteur::estLancerDeSort(['type' => 'parchemin']))->toBeTrue()
        ->and(App\Partie\MenuMoteur::estLancerDeSort(['type' => 'attaque']))->toBeFalse();

    $ctx = demarrerQueteAvecMonstre('Maître des orages');
    $sleet = Condition::where('nom', 'Grésil aveuglant')->firstOrFail();
    $ctx['heros']->conditions()->attach($sleet->id, ['duree' => 0, 'source' => 'test']);

    $resolveur = app(App\Partie\ResolveurTour::class);
    $frapper = new ReflectionMethod($resolveur, 'frapper');
    $instance = $ctx['instance'];

    expect(fn () => $frapper->invoke(
        $resolveur, $ctx['groupe'], $ctx['quete'], $ctx['etatHeros'], $ctx['heros'], $instance, true,
    ))->toThrow(Illuminate\Validation\ValidationException::class, 'aveuglé par le grésil');
});

// ==================================================================
// HIGH MAGE
// ==================================================================

it('Muraille de flammes : mur de feu de deux cases, posé en vue du lanceur', function () {
    $ctx = sorcierAvecSort('Muraille de flammes', 'Haut mage');
    aligner($ctx, 8, 5);

    $sort = sortLance(phaseSorcier($ctx), 'Muraille de flammes');

    expect($sort['mobilier']['nom'])->toBe('Mur de Feu')
        ->and($sort['cases'])->toHaveCount(2);
});

it('Liens magiques : le héros ne peut ni bouger ni attaquer, un voisin tranche les liens (1 PV, 4 dés)', function () {
    $ctx = sorcierAvecSort('Liens magiques', 'Haut mage');
    $actions = phaseSorcier($ctx);
    $sort = sortLance($actions, 'Liens magiques');
    $moteur = app(MoteurSorts::class);
    $heros = $ctx['heros']->fresh();

    expect($sort['resultats'][0]['effet_applique'])->toBeTrue()
        ->and($moteur->deplacementInterdit($heros))->toBeTrue()
        ->and($moteur->raisonAttaqueInterdite($heros))->toContain('ligoté')
        // « Détruire les entraves » (action gratuite des ronces) NE lève PAS ces liens.
        ->and($moteur->entravesLiberables($heros))->toHaveCount(0)
        ->and($moteur->liensDe($heros))->not->toBeNull();

    // Le menu offre « trancher les liens » (le héros lui-même peut s'en défaire).
    $menu = test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();
    $types = collect($menu->json('menu.options'))->pluck('type')->all();
    expect($types)->toContain('attaquer_liens')->not->toContain('liberer_entraves')->not->toContain('deplacement');

    // 3 dés d'attaque : crânes ; 4 dés de défense des liens : crânes aussi (aucun bouclier) → détruits.
    desFiges([1, 1, 1, 1, 1, 1, 1, ...array_fill(0, 50, 1)]);
    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer_liens', 'parametres' => ['cible_id' => $heros->id]])
        ->assertStatus(202);

    expect($reponse->json('resultat.detruit'))->toBeTrue()
        ->and($moteur->liensDe($ctx['heros']->fresh()))->toBeNull()
        ->and($moteur->deplacementInterdit($ctx['heros']->fresh()))->toBeFalse();
});

it('Corrosion : toute pièce de métal, armure comprise, définitivement ; jamais un artefact', function () {
    $sort = SortDread::where('nom', 'Corrosion')->firstOrFail();
    expect(data_get($sort->effet, 'detruit.emplacements'))->toContain('armure')
        ->and(data_get($sort->effet, 'detruit.epargne_artefacts'))->toBeTrue()
        // La Rouille de la carte de base reste « épée ou casque » : pas d'armure.
        ->and(data_get(SortDread::where('nom', 'Rouille')->firstOrFail()->effet, 'detruit.emplacements'))->not->toContain('armure');

    $ctx = sorcierAvecSort('Corrosion', 'Haut mage');
    $ctx['heros']->inventaire()->create(['objet_id' => App\Models\Objet::where('nom', 'Épée longue')->value('id'), 'emplacement' => 'arme_principale']);
    $avant = $ctx['heros']->inventaire()->whereIn('emplacement', ['arme_principale', 'arme_secondaire', 'casque', 'armure'])->count();

    $res = sortLance(phaseSorcier($ctx), 'Corrosion');

    expect($res['resultats'][0]['arrache'])->toBeFalse()
        ->and($res['resultats'][0]['objet_detruit'])->toBe('Épée longue')
        ->and($ctx['heros']->inventaire()->whereIn('emplacement', ['arme_principale', 'arme_secondaire', 'casque', 'armure'])->count())->toBe($avant - 1);
});

it('Possession : le moteur déplace le héros à sa place, sans attaque, et la condition tombe', function () {
    $ctx = sorcierAvecSort('Possession', 'Haut mage');
    aligner($ctx, 8, 1);
    $actions = phaseSorcier($ctx);

    expect(sortLance($actions, 'Possession')['resultats'][0]['effet_applique'])->toBeTrue()
        ->and($ctx['heros']->fresh()->conditions()->where('nom', 'Possédé')->exists())->toBeTrue();

    $avant = (int) $ctx['heros']->fresh()->pv_body;
    desFiges(array_fill(0, 100, 1));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    expect($reponse->json('resultat.type'))->toBe('possession_deplacement')
        ->and($ctx['heros']->fresh()->conditions()->where('nom', 'Possédé')->exists())->toBeFalse('un tour, pas plus');
});

it('Désapprentissage : retourne l\'oubli de quête contre un héros LANCEUR, au hasard', function () {
    $ctx = sorcierAvecSort('Désapprentissage', 'Haut mage');
    $sort = Sort::query()->orderBy('id')->firstOrFail();
    $ctx['heros']->sorts()->attach($sort->id, ['disponible' => true]);

    $res = sortLance(phaseSorcier($ctx), 'Désapprentissage');

    expect($res['oubli']['sort_oublie'])->toBe($sort->nom)
        ->and(app(OubliSorts::class)->oublies($ctx['quete'], OubliSorts::CIBLE_PERSONNAGE, (int) $ctx['heros']->id, OubliSorts::SOURCE_SORT))->toBe([$sort->nom]);

});

it('Désapprentissage : sans héros lanceur, le sort n\'est pas retenu (il ne brûle pas son unique usage)', function () {
    $ctx = sorcierAvecSort('Désapprentissage', 'Haut mage');

    expect(sortLance(phaseSorcier($ctx), 'Désapprentissage'))->toBeNull()
        ->and((array) $ctx['instance']->fresh()->sorts_dread_lances)->toBe([]);
});

// ==================================================================
// NECROMANCER
// ==================================================================

it('Invocation de momie : UNE momie, sans dé, au contact du lanceur', function () {
    $ctx = sorcierAvecSort('Invocation de momie', 'Nécromancien');
    aligner($ctx, 8, 6); // héros loin : un renfort ne s'appelle pas au contact

    $res = sortLance(phaseSorcier($ctx), 'Invocation de momie');

    expect($res['invoques'])->toHaveCount(1)
        ->and($res['invoques'][0]['monstre'])->toBe('Momie')
        ->and($res)->not->toHaveKey('de');
});

it('Appel des squelettes : jusqu\'à 2 squelettes surgissent en vue du lanceur, près des héros', function () {
    $ctx = sorcierAvecSort('Appel des squelettes', 'Nécromancien');
    ['x0' => $x0, 'y' => $y] = aligner($ctx, 8, 6);

    $res = sortLance(phaseSorcier($ctx), 'Appel des squelettes');

    expect($res['invoques'])->toHaveCount(2);
    foreach ($res['invoques'] as $i) {
        expect($i['monstre'])->toBe('Squelette')
            // pas au contact du lanceur : « anywhere within sight »
            ->and(abs($i['x'] - $x0) + abs($i['y'] - $y))->toBeGreaterThan(1);
    }
});

it('Crânes maudits : 2 dés d\'attaque, le héros pare normalement', function () {
    $ctx = sorcierAvecSort('Crânes maudits', 'Nécromancien');
    $avant = (int) $ctx['heros']->pv_body;

    // 2 dés d'attaque : crânes ; 2 dés de défense : boucliers blancs (4) → tout parré.
    $res = sortLance(phaseSorcier($ctx, [1, 1, 4, 4]), 'Crânes maudits');

    expect($res['resultats'][0]['touches'])->toBe(2)
        ->and($res['resultats'][0]['degats'])->toBe(0)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($avant);
});

it('Trait de mort : 1 PV de Body, ni dé ni parade', function () {
    $ctx = sorcierAvecSort('Trait de mort', 'Nécromancien');
    $avant = (int) $ctx['heros']->pv_body;

    $res = sortLance(phaseSorcier($ctx), 'Trait de mort');

    expect($res['resultats'][0]['degats'])->toBe(1)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($avant - 1);
});

it('Relève des morts : réaction SANS action, un monstre abattu devient squelette, une seule fois', function () {
    $ctx = demarrerQueteAvecMonstre('Nécromancien');
    $quete = $ctx['quete'];
    $necro = $ctx['instance'];
    $gobelin = Monstre::where('nom_base', 'Gobelin')->firstOrFail();
    $cases = [];

    foreach ([1, 2] as $_) {
        $case = caseAdjacenteLibre($quete, (int) $necro->position_x + $_, (int) $necro->position_y);
        $cases[] = $case;
    }

    $victimes = collect($cases)->map(fn ($c) => InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => $gobelin->id, 'pv_body' => 1, 'pv_body_max' => 1,
        'pv_mind' => $gobelin->pv_mind, 'position_x' => $c['x'], 'position_y' => $c['y'],
        'etat' => 'actif', 'revele' => true,
    ]));

    $usagesAvant = (int) $necro->usages_dread;
    $mort = app(MoteurDegats::class)->infligerAMonstre($victimes[0], 5, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($mort['vaincu'])->toBeTrue()
        ->and($mort['reaction_dread']['sort'])->toBe('Relève des morts')
        ->and($mort['reaction_dread']['sans_action'])->toBeTrue();

    $squelette = $quete->instancesMonstres()->whereHas('monstre', fn ($q) => $q->where('nom_base', 'Squelette'))
        ->where('position_x', $victimes[0]->position_x)->where('position_y', $victimes[0]->position_y)->first();

    expect($squelette)->not->toBeNull()
        ->and($squelette->etat)->toBe('actif')
        ->and((array) $necro->fresh()->sorts_dread_lances)->toBe(['Relève des morts'])
        ->and((int) $necro->fresh()->usages_dread)->toBe($usagesAvant - 1);

    // Une seule fois par quête : la deuxième mort ne relève personne.
    $mort2 = app(MoteurDegats::class)->infligerAMonstre($victimes[1], 5, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($mort2['reaction_dread'])->toBeNull();
});

it('Relève des morts : ne relève jamais un Sorcier (la quête se termine à sa mort)', function () {
    $ctx = demarrerQueteAvecMonstre('Nécromancien');
    $autre = Monstre::where('nom_base', 'Haut mage')->firstOrFail();
    $case = caseAdjacenteLibre($ctx['quete'], (int) $ctx['instance']->position_x, (int) $ctx['instance']->position_y);
    $sorcier = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => $autre->id, 'pv_body' => 1, 'pv_body_max' => 1,
        'pv_mind' => $autre->pv_mind, 'position_x' => $case['x'], 'position_y' => $case['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    $mort = app(MoteurDegats::class)->infligerAMonstre($sorcier->load('monstre'), 9, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($mort['reaction_dread'])->toBeNull();
});

// ==================================================================
// Le moteur choisit le sort : jamais le Raise the Dead comme action
// ==================================================================

it('un sort réactif n\'est jamais choisi comme action du tour', function () {
    $ctx = sorcierAvecSort('Relève des morts', 'Nécromancien');

    expect(sortLance(phaseSorcier($ctx), 'Relève des morts'))->toBeNull();
});

it('un Nécromancien au répertoire complet joue un sort valide de SON répertoire', function () {
    $ctx = demarrerQueteAvecMonstre('Nécromancien');
    $actions = phaseSorcier($ctx);
    $repertoire = config('archetypes_lanceurs.necromancien_morcar.sorts');
    $lance = collect($actions)->pluck('sort')->filter()->first();

    expect($lance)->not->toBeNull()
        ->and($repertoire)->toContain($lance)
        ->and($lance)->not->toBe('Relève des morts');
});
