<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\SortDread;
use App\Models\Terrain;
use App\Partie\JournalCombat;
use App\Partie\MoteurDread;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

/*
 * BRASSARD DE GLACE (Armband of Ice, The Frozen Horror, Artifact Reference p. 35 — transcrit dans
 * `reference/18_extensions.md` §3) : « This powerful item grants its wearer immunity to Mind Freeze and Chill spells,
 * plus immunity to damage from ice vaults and icy rivers for as long as it is worn. It also reduces the damage from an
 * Ice Storm spell by 1 point for the armband wearer only. »
 *
 * Porté le 2026-11-12 (René, accord du même jour : « intégrer maintenant que leurs manques ont une couture »). Quatre
 * clauses, quatre lecteurs, chacun à un point de passage :
 *  - Morsure de Froid (Chill, `type_degat: froid`) et les deux terrains de glace : `immunite_degat: froid`, la couture
 *    de l'Anneau de Chaleur — `MoteurSorts::absorbeDegat()` ;
 *  - Gel de l'Esprit (Mind Freeze, AUCUNE nature) : `immunite_sorts_nommes`, lu par `Equipement::immuniseSortNomme()` au
 *    choix de la cible, `MoteurDread::cibleMindFreeze()` ;
 *  - Tempête de Glace : `reduit_degats_de_sort`, −1 sur le coup, lu par `MoteurDegats::infligerAHeros()` et publié en
 *    `dernierModificateurs()`.
 *
 * ⚠ La Tempête de Glace n'a plus de `type_degat: froid` (SortDreadSeeder, même date) : la carte la nomme À PART des
 * immunités au froid, et sans cela l'immunité ci-dessus l'annulerait entière au lieu de la réduire. Conséquence, testée
 * plus bas : l'Anneau de Chaleur ne protège PAS de la Tempête de Glace — sa carte ne nomme que Chill.
 *
 * Les scènes sont construites à la main (géométrie exacte), comme `SortsGlaceTest::sceneGlace()` : les helpers d'un
 * fichier Pest ne sont pas fiables d'un fichier à l'autre, d'où le préfixe `bdg`.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

/**
 * Une quête à la main, un ou plusieurs héros (chacun avec son état), un monstre optionnel.
 *
 * @param  list<list<string>>  $cases  m = mur, s = sol
 * @param  list<array{x: int, y: int}>  $herosPos
 * @param  list<array<string, mixed>>  $herosAttrs  surcharges de stats, par héros
 * @param  array{x: int, y: int}|null  $instancePos
 * @param  list<array{x: int, y: int, terrain_id: int, paire_id?: string}>  $terrain
 * @return array{groupe: Groupe, quete: Quete, herosModels: list<Personnage>, etats: list<EtatPersonnageQuete>, instance: ?InstanceMonstre}
 */
function bdgScene(
    array $cases,
    array $herosPos,
    array $herosAttrs = [],
    ?array $instancePos = null,
    string $monstre = 'Archimage elfe',
    array $terrain = [],
): array {
    $groupe = creerGroupe('table-bdg-'.uniqid());
    $gabarit = GabaritQuete::query()->firstOrFail();

    $largeur = count($cases[0] ?? []);
    $hauteur = count($cases);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — Brassard de Glace',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => $hauteur,
            'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [],
            'leviers' => [], 'pieges' => [], 'mobilier' => [], 'epreuves' => [],
            'terrain' => $terrain,
            'glace' => [],
            'spawn_heros' => $herosPos,
            'spawn_monstres' => $instancePos === null ? [] : [$instancePos],
            'aretes' => [],
        ],
    ]);

    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);

    $herosModels = [];
    $etats = [];

    foreach ($herosPos as $i => $pos) {
        $joueur = connecterJoueur('bdg-'.$i.'-'.uniqid());
        $heros = creerHeros($joueur, $groupe, 'Héros '.($i + 1), $i + 1, $herosAttrs[$i] ?? []);

        $herosModels[] = $heros;
        $etats[] = EtatPersonnageQuete::create([
            'quete_id' => $quete->id,
            'personnage_id' => $heros->id,
            'position_x' => $pos['x'],
            'position_y' => $pos['y'],
            'tombe' => false,
            'a_joue' => false,
        ]);
    }

    $instance = null;

    if ($instancePos !== null) {
        $catalogue = Monstre::where('nom_base', $monstre)->firstOrFail();
        $instance = InstanceMonstre::create([
            'quete_id' => $quete->id,
            'monstre_id' => $catalogue->id,
            'pv_body' => $catalogue->pv_body,
            'pv_body_max' => $catalogue->pv_body,
            'pv_mind' => $catalogue->pv_mind,
            'position_x' => $instancePos['x'],
            'position_y' => $instancePos['y'],
            'etat' => 'actif',
            'revele' => true,
        ]);
        $instance->refresh()->load('monstre');
    }

    return ['groupe' => $groupe, 'quete' => $quete->fresh(), 'herosModels' => $herosModels, 'etats' => $etats, 'instance' => $instance];
}

/** Pose une pièce dans un emplacement, sans les garde-fous de l'équipement (même patron que `poserPourGlace()`). */
function bdgPoser(Personnage $p, string $nom, string $emplacement): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

/** Le répertoire du boss ne tient qu'un sort : il est donc choisi s'il est jouable. */
function bdgForcerSort(InstanceMonstre $instance, string $sort): void
{
    $instance->monstre->update(['sorts_dread' => [$sort], 'archetype_lanceur' => null]);
    $instance->refresh()->load('monstre');

    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $instance->quete);
    $instance->refresh()->load('monstre');
}

/** Fige les dés, puis joue le tour du boss face à TOUS les héros de la scène. */
function bdgJouer(array $scene, array $des): array
{
    desFiges([...$des, ...array_fill(0, 200, 4)]);

    $cibles = new Collection(array_map(fn ($e) => $e->fresh(), $scene['etats']));

    return app(MoteurDread::class)->jouerTourDread(
        $scene['groupe'], $scene['quete']->fresh(), $scene['instance']->fresh()->load('monstre'), $cibles,
    ) ?? [];
}

/** L'action du sort nommé, dans un payload simple ou composite. */
function bdgAction(array $payload, string $nom): ?array
{
    if (($payload['type'] ?? null) === 'actions_composites') {
        foreach ((array) $payload['actions'] as $action) {
            if (($action['sort'] ?? null) === $nom) {
                return $action;
            }
        }

        return null;
    }

    return ($payload['sort'] ?? null) === $nom ? $payload : null;
}

/** Un héros seul, dans une pièce d'un tenant : la plupart des coups de Dread se résolvent contre lui. */
function bdgUnHeros(array $attrs = []): array
{
    return bdgScene([array_fill(0, 5, 's')], [['x' => 0, 'y' => 0]], [$attrs]);
}

// ==================================================================
// GEL DE L'ESPRIT (Mind Freeze) — le porteur n'est jamais la cible
// ==================================================================

it("Gel de l'Esprit ne vise JAMAIS le porteur du Brassard : il prend l'autre héros en vue, même plus entamé", function () {
    // Le porteur a MOINS de Mind (1) que son compagnon (3) : sans le Brassard, c'est lui que le sort vise.
    // ⚠ Géométrie : une figure coupe la ligne de vue. Le compagnon ne doit donc PAS se cacher derrière le porteur — le boss
    // (0,0) voit (1,1) et (2,0) par deux droites libres.
    $scene = bdgScene(
        [array_fill(0, 3, 's'), array_fill(0, 3, 's'), array_fill(0, 3, 's')],
        herosPos: [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 0]],
        herosAttrs: [
            ['attribut_mind' => 3, 'pv_mind_max' => 3, 'pv_mind' => 1],
            ['attribut_mind' => 3, 'pv_mind_max' => 3, 'pv_mind' => 3],
        ],
        instancePos: ['x' => 0, 'y' => 0],
    );
    bdgPoser($scene['herosModels'][0], 'Brassard de Glace', 'talisman');
    bdgForcerSort($scene['instance'], "Gel de l'Esprit");

    $sort = bdgAction(bdgJouer($scene, [1, 1, 1]), "Gel de l'Esprit");

    expect($sort)->not->toBeNull()
        ->and($sort['resultats'][0]['cible']['personnage_id'])->toBe($scene['herosModels'][1]->id, 'le porteur est immunisé : le sort prend l\'autre héros')
        ->and((int) $scene['herosModels'][0]->fresh()->pv_mind)->toBe(1, 'le Mind du porteur ne bouge pas');
});

it("témoin : sans le Brassard, Gel de l'Esprit vise bien le héros le plus entamé", function () {
    $scene = bdgScene(
        [array_fill(0, 3, 's'), array_fill(0, 3, 's'), array_fill(0, 3, 's')],
        herosPos: [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 0]],
        herosAttrs: [
            ['attribut_mind' => 3, 'pv_mind_max' => 3, 'pv_mind' => 1],
            ['attribut_mind' => 3, 'pv_mind_max' => 3, 'pv_mind' => 3],
        ],
        instancePos: ['x' => 0, 'y' => 0],
    );
    bdgForcerSort($scene['instance'], "Gel de l'Esprit");

    $sort = bdgAction(bdgJouer($scene, [1, 1, 1]), "Gel de l'Esprit");

    expect($sort['resultats'][0]['cible']['personnage_id'])->toBe($scene['herosModels'][0]->id);
});

it("Gel de l'Esprit n'est pas lancé si le seul héros en vue porte le Brassard", function () {
    $scene = bdgScene(
        [['m', 's', 's', 's', 's', 'm']],
        herosPos: [['x' => 2, 'y' => 0]],
        herosAttrs: [['attribut_mind' => 3, 'pv_mind_max' => 3, 'pv_mind' => 3]],
        instancePos: ['x' => 1, 'y' => 0],
    );
    bdgPoser($scene['herosModels'][0], 'Brassard de Glace', 'talisman');
    bdgForcerSort($scene['instance'], "Gel de l'Esprit");

    // « Une cible qui ne peut pas être touchée n'est pas une cible » : le boss ne brûle pas le sort pour rien.
    expect(bdgAction(bdgJouer($scene, [1, 1, 1]), "Gel de l'Esprit"))->toBeNull();
});

// ==================================================================
// MORSURE DE FROID (Chill) — immunité par la nature `froid`
// ==================================================================

it('Morsure de Froid (Chill) ne blesse PAS le porteur : le sort est absorbé, sans perte de PV', function () {
    $scene = bdgUnHeros();
    bdgPoser($scene['herosModels'][0], 'Brassard de Glace', 'talisman');
    $pvAvant = (int) $scene['herosModels'][0]->pv_body;

    $payload = app(MoteurDread::class)->lancerSortDePiege(
        $scene['quete'], 'Morsure de Froid', $scene['etats'][0]->fresh(), 'Piège de test',
    );

    expect($payload['resultats'][0])->toHaveKey('absorbe')
        ->and($payload['resultats'][0]['absorbe'])->toBeTrue()
        ->and((int) $scene['herosModels'][0]->fresh()->pv_body)->toBe($pvAvant);
});

it('témoin : sans le Brassard, Morsure de Froid blesse le héros d\'1 PV', function () {
    $scene = bdgUnHeros();
    $pvAvant = (int) $scene['herosModels'][0]->pv_body;

    $payload = app(MoteurDread::class)->lancerSortDePiege(
        $scene['quete'], 'Morsure de Froid', $scene['etats'][0]->fresh(), 'Piège de test',
    );

    expect($payload['resultats'][0]['degats'])->toBe(1)
        ->and((int) $scene['herosModels'][0]->fresh()->pv_body)->toBe($pvAvant - 1);
});

// ==================================================================
// TEMPÊTE DE GLACE (Ice Storm) — réduction de 1, annoncée
// ==================================================================

it('Tempête de Glace : 1 dégât de moins au porteur, et le coup le DIT (modificateur publié)', function () {
    $scene = bdgUnHeros();
    bdgPoser($scene['herosModels'][0], 'Brassard de Glace', 'talisman');
    $pvAvant = (int) $scene['herosModels'][0]->pv_body;

    // Trois dés de combat, trois crânes : 3 dégâts sans le Brassard, 2 avec.
    desFiges(array_fill(0, 20, 1));

    $payload = app(MoteurDread::class)->lancerSortDePiege(
        $scene['quete'], 'Tempête de Glace', $scene['etats'][0]->fresh(), 'Piège de test',
    );
    $r = $payload['resultats'][0];

    expect($r['degats'])->toBe(2)
        ->and((int) $scene['herosModels'][0]->fresh()->pv_body)->toBe($pvAvant - 2)
        ->and($r['modificateurs'] ?? null)->toBe([
            ['source' => 'Brassard de Glace', 'valeur' => -1, 'sur' => 'degats'],
        ], 'la réduction doit être publiée, jamais silencieuse');
});

it('témoin : sans le Brassard, Tempête de Glace fait ses 3 dégâts, sans aucun modificateur', function () {
    $scene = bdgUnHeros();
    $pvAvant = (int) $scene['herosModels'][0]->pv_body;
    desFiges(array_fill(0, 20, 1));

    $payload = app(MoteurDread::class)->lancerSortDePiege(
        $scene['quete'], 'Tempête de Glace', $scene['etats'][0]->fresh(), 'Piège de test',
    );

    expect($payload['resultats'][0]['degats'])->toBe(3)
        ->and($payload['resultats'][0])->not->toHaveKey('modificateurs')
        ->and((int) $scene['herosModels'][0]->fresh()->pv_body)->toBe($pvAvant - 3);
});

it('la réduction ne passe jamais sous zéro : un coup d\'UN dégât est ramené à zéro, sans rien à annoncer de plus', function () {
    $scene = bdgUnHeros();
    bdgPoser($scene['herosModels'][0], 'Brassard de Glace', 'talisman');
    $pvAvant = (int) $scene['herosModels'][0]->pv_body;

    // Un crâne, deux boucliers : un seul dégât possible, et le Brassard le retire.
    desFiges([1, 4, 4]);

    $payload = app(MoteurDread::class)->lancerSortDePiege(
        $scene['quete'], 'Tempête de Glace', $scene['etats'][0]->fresh(), 'Piège de test',
    );

    expect($payload['resultats'][0]['degats'])->toBe(0)
        ->and((int) $scene['herosModels'][0]->fresh()->pv_body)->toBe($pvAvant);
});

it('l\'Anneau de Chaleur ne protège PAS de la Tempête de Glace — sa carte ne nomme que Chill', function () {
    // Garde-fou de la lecture : la Tempête de Glace n'a plus de nature `froid`. Si elle en reprenait une, l'anneau
    // l'annulerait entière, ce que sa carte ne dit pas.
    $scene = bdgUnHeros();
    bdgPoser($scene['herosModels'][0], 'Anneau de Chaleur', 'talisman');
    $pvAvant = (int) $scene['herosModels'][0]->pv_body;
    desFiges(array_fill(0, 20, 1));

    $payload = app(MoteurDread::class)->lancerSortDePiege(
        $scene['quete'], 'Tempête de Glace', $scene['etats'][0]->fresh(), 'Piège de test',
    );

    expect($payload['resultats'][0]['degats'])->toBe(3)
        ->and((int) $scene['herosModels'][0]->fresh()->pv_body)->toBe($pvAvant - 3);
});

it('l\'Anneau de Chaleur garde son immunité à Morsure de Froid (Chill), sa clause nommée', function () {
    $scene = bdgUnHeros();
    bdgPoser($scene['herosModels'][0], 'Anneau de Chaleur', 'talisman');

    $payload = app(MoteurDread::class)->lancerSortDePiege(
        $scene['quete'], 'Morsure de Froid', $scene['etats'][0]->fresh(), 'Piège de test',
    );

    expect($payload['resultats'][0]['absorbe'])->toBeTrue();
});

// ==================================================================
// TERRAIN — la moitié « coffres de glace et rivières gelées », comme l'Anneau de Chaleur
// ==================================================================

it('le Brassard de Glace immunise contre la Rivière gelée — même bouclier blanc, aucun dégât', function () {
    $riviere = Terrain::where('nom', 'Rivière gelée')->firstOrFail();

    $scene = bdgScene(
        [array_fill(0, 6, 's')],
        herosPos: [['x' => 0, 'y' => 0]],
        terrain: [
            ['x' => 2, 'y' => 0, 'terrain_id' => $riviere->id],
            ['x' => 3, 'y' => 0, 'terrain_id' => $riviere->id],
        ],
    );
    bdgPoser($scene['herosModels'][0], 'Brassard de Glace', 'talisman');
    $pvAvant = (int) $scene['herosModels'][0]->pv_body;

    // Bouclier blanc partout : sans le Brassard, cette séquence blesserait exactement 2 (témoin ci-dessous).
    desFiges(array_fill(0, 40, 4));

    app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['herosModels'][0], ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'], ['x' => 5, 'y' => 0],
    );

    expect((int) $scene['herosModels'][0]->fresh()->pv_body)->toBe($pvAvant, 'immunisé au froid, la rivière ne blesse plus')
        ->and((int) $scene['etats'][0]->fresh()->position_x)->toBe(5, 'l\'immunité ne coupe pas le mouvement');
});

it('témoin : sans le Brassard, la même Rivière gelée blesse de 2', function () {
    $riviere = Terrain::where('nom', 'Rivière gelée')->firstOrFail();

    $scene = bdgScene(
        [array_fill(0, 6, 's')],
        herosPos: [['x' => 0, 'y' => 0]],
        terrain: [
            ['x' => 2, 'y' => 0, 'terrain_id' => $riviere->id],
            ['x' => 3, 'y' => 0, 'terrain_id' => $riviere->id],
        ],
    );
    $pvAvant = (int) $scene['herosModels'][0]->pv_body;
    desFiges(array_fill(0, 40, 4));

    app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['herosModels'][0], ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'], ['x' => 5, 'y' => 0],
    );

    expect((int) $scene['herosModels'][0]->fresh()->pv_body)->toBe($pvAvant - 2);
});

it('le Brassard de Glace immunise contre la Chambre forte de glace — même crâne, aucun dégât', function () {
    $vault = Terrain::where('nom', 'Chambre forte de glace')->firstOrFail();

    $scene = bdgScene(
        [array_fill(0, 3, 's')],
        herosPos: [['x' => 0, 'y' => 0]],
        terrain: [['x' => 0, 'y' => 0, 'terrain_id' => $vault->id]],
    );
    bdgPoser($scene['herosModels'][0], 'Brassard de Glace', 'talisman');
    $pvAvant = (int) $scene['herosModels'][0]->pv_body;

    // Le saignement de la chambre forte est lu par `saignerParTerrain()` : un crâne le déclenche.
    desFiges(array_fill(0, 20, 1));
    $methode = new ReflectionMethod(ResolveurTour::class, 'saignerParTerrain');
    $methode->setAccessible(true);
    $methode->invoke(app(ResolveurTour::class), $scene['etats'][0]->fresh());

    expect((int) $scene['herosModels'][0]->fresh()->pv_body)->toBe($pvAvant, 'immunisé au froid, la chambre forte ne saigne plus');
});

// ==================================================================
// DONNÉES — le catalogue dit ce que la carte dit
// ==================================================================

it('seed le Brassard de Glace comme artefact de boîte glace, talisman, sans charge — et ses trois clés', function () {
    $objet = Objet::where('nom', 'Brassard de Glace')->firstOrFail();

    expect($objet->categorie)->toBe('armure')
        ->and($objet->emplacement)->toBe('talisman')
        ->and($objet->rarete)->toBe('unique')
        ->and($objet->boite)->toBe('horreur_des_glaces')
        ->and($objet->effet)->toBe([
            'immunite_degat' => 'froid',
            'immunite_sorts_nommes' => ["Gel de l'Esprit"],
            'reduit_degats_de_sort' => ['sort' => 'Tempête de Glace', 'points' => 1],
        ])
        // Aucune charge : immunité permanente tant qu'il est porté, comme l'Anneau de Chaleur.
        ->and($objet->effet)->not->toHaveKey('charges');
});

it('la Tempête de Glace n\'est PLUS une source de froid — sa carte ne la classe pas sous l\'immunité au froid', function () {
    $sort = SortDread::where('nom', 'Tempête de Glace')->firstOrFail();

    expect($sort->effet)->not->toHaveKey('type_degat')
        ->and(data_get(SortDread::where('nom', 'Morsure de Froid')->firstOrFail()->effet, 'type_degat'))->toBe('froid');
});

// ==================================================================
// ANNONCE — la réduction se LIT au fil, pas seulement dans le payload
// ==================================================================

it('la réduction de la Tempête de Glace s\'écrit au fil : « … réduit le coup de 1 » après la frappe', function () {
    $scene = bdgUnHeros();
    bdgPoser($scene['herosModels'][0], 'Brassard de Glace', 'talisman');
    desFiges(array_fill(0, 20, 1));

    $payload = app(MoteurDread::class)->lancerSortDePiege(
        $scene['quete'], 'Tempête de Glace', $scene['etats'][0]->fresh(), 'Piège de test',
    );
    $fil = collect((new JournalCombat)->depuisResultat($payload, 'Le piège'))->pluck('texte')->all();

    $texte = implode(' | ', $fil);

    expect($fil)->toContain('Héros 1 : Brassard de Glace réduit le coup de 1')
        // La ligne de dégâts dit le montant RÉEL, après la réduction : deux PV, pas trois.
        ->and($texte)->toMatch('/Tempête de Glace frappe Héros 1 \(.2 PV\)/u');
});

it('témoin : sans le Brassard, le fil ne parle d\'aucune réduction', function () {
    $scene = bdgUnHeros();
    desFiges(array_fill(0, 20, 1));

    $payload = app(MoteurDread::class)->lancerSortDePiege(
        $scene['quete'], 'Tempête de Glace', $scene['etats'][0]->fresh(), 'Piège de test',
    );
    $fil = implode(' | ', collect((new JournalCombat)->depuisResultat($payload, 'Le piège'))->pluck('texte')->all());

    expect($fil)->not->toContain('réduit le coup')
        ->and($fil)->toMatch('/Tempête de Glace frappe Héros 1 \(.3 PV\)/u');
});
