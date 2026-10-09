<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Carte;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Wizards of Morcar — vague 1a : les trois répertoires OPTIONNELS de sorts de
 * héros (Protection/Détection/Ténèbres, livret p. 11) et les murs magiques
 * (Wall of Stone, 2026-10-06).
 *
 * Même parti que `AttaqueMobilierTest`/`DestructionMobilierTest` : une quête
 * MINIMALE (carte 7×7 construite à la main) plutôt qu'un donjon procédural —
 * on éprouve une mécanique, pas un placement. Les résolutions passent par
 * Reflection sur les méthodes PRIVÉES de `ResolveurTour`, exactement comme
 * `AttaqueMobilierTest::attaquerMeuble()` — ça évite la couche HTTP/cache du
 * menu tout en empruntant les VRAIS lecteurs (`MoteurSorts::options()`,
 * `FabriqueGrille::pour()`).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/**
 * Quête minimale (7×7 tout en sol), un héros LANCEUR en (3,3). `$salles`
 * permet de ne couvrir qu'une PARTIE de la grille (le reste devient un
 * « couloir » sans index de salle — `Salles::indexDe()` y répond `null`).
 *
 * @return array{alice: JoueurAuthentifiable, groupe: Groupe, heros: Personnage, quete: Quete, etatHeros: EtatPersonnageQuete}
 */
function queteMinimalePourMorcar(array $personnageAttrs = [], ?array $salles = null): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Lyra', 1, array_merge(['classe' => 'magicien'], $personnageAttrs));

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => 7,
        'hauteur' => 7,
        'grille' => [
            'largeur' => 7, 'hauteur' => 7,
            'cases' => array_fill(0, 7, array_fill(0, 7, 's')),
            'salles' => $salles ?? [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'epreuves' => [],
            'mobilier' => [],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => 3, 'position_y' => 3,
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete->fresh()->load('carte'), 'etatHeros' => $etatHeros];
}

/**
 * Lance le sort `$nomSort` par `$ctx['heros']`, via l'entrée dont la `cle`
 * CONTIENT `$fragmentCle`. `$cible` porte `cible_id`/`cible_type` quand le
 * sort en a besoin (mental/degats) — même forme que le corps réel de
 * `POST /choix` (`{cle, cible_id?, cible_type?}` À PLAT, jamais niché dans
 * l'entrée).
 */
function lancerSortMorcar(array $ctx, string $nomSort, string $fragmentCle = '', array $cible = []): array
{
    $quete = $ctx['quete']->fresh()->load('carte');
    $heros = $ctx['heros']->fresh();

    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $quete, $heros);
    $sortOption = collect($options)->firstWhere('id', 'lancer_sort');
    expect($sortOption)->not->toBeNull("Aucune option « lancer_sort » — {$nomSort} n'a pas d'entrée.");

    $entrees = collect($sortOption['parametres']['sorts'])
        ->filter(fn ($e) => $e['nom'] === $nomSort || str_starts_with((string) $e['nom'], $nomSort))
        ->filter(fn ($e) => $fragmentCle === '' || str_contains((string) $e['cle'], $fragmentCle));

    expect($entrees)->not->toBeEmpty("Aucune entrée jouable pour {$nomSort} (fragment « {$fragmentCle} »).");
    $entree = $entrees->first();

    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();

    return (new ReflectionMethod(ResolveurTour::class, 'resoudreSort'))->invoke(
        app(ResolveurTour::class),
        $ctx['groupe']->fresh(), $quete, $heros, $etat,
        $sortOption, ['cle' => $entree['cle'], ...$cible],
        ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom],
    );
}

// =====================================================================
// RÉPERTOIRES OPTIONNELS — Protection / Détection / Ténèbres
// =====================================================================

it('remplace un élément connu par un répertoire optionnel, pour n\'importe quelle classe de lanceur', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $mage = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    app(MoteurSorts::class)->attacherElement($mage, 'feu');
    app(MoteurSorts::class)->attacherElement($mage, 'eau');
    app(MoteurSorts::class)->attacherElement($mage, 'terre');
    expect($mage->sorts()->count())->toBe(9);

    $reponse = $this->putJson('/api/groupes/table-1/sorts-repertoire', [
        'personnage_id' => $mage->id,
        'element_actuel' => 'feu',
        'nouveau_repertoire' => 'protection',
    ])->assertOk();

    expect($reponse->json('repertoire'))->toBe('protection')
        ->and(collect($reponse->json('sorts'))->pluck('nom')->sort()->values()->all())
        ->toBe(['Désapprentissage', 'Invisibilité', 'Mur de Pierre']);

    // Le magicien garde bien TROIS répertoires (« Wizard still has three sets
    // of spells ») — un pour un, jamais un de plus ni de moins.
    $mage->refresh();
    expect($mage->sorts()->pluck('element')->unique()->sort()->values()->all())
        ->toBe(['eau', 'protection', 'terre']);

    // Rejouable ENTRE deux quêtes, y compris d'un optionnel à un autre
    // (« Spellcasters may change their spells between quests »).
    $this->putJson('/api/groupes/table-1/sorts-repertoire', [
        'personnage_id' => $mage->id,
        'element_actuel' => 'protection',
        'nouveau_repertoire' => 'tenebres',
    ])->assertOk();
    expect($mage->fresh()->sorts()->pluck('element')->unique()->sort()->values()->all())
        ->toBe(['eau', 'tenebres', 'terre']);

    // 422 : un élément que ce héros ne connaît PAS.
    $this->putJson('/api/groupes/table-1/sorts-repertoire', [
        'personnage_id' => $mage->id, 'element_actuel' => 'air', 'nouveau_repertoire' => 'detection',
    ])->assertStatus(422);

    // 422 : hors des trois répertoires optionnels déclarés.
    $this->putJson('/api/groupes/table-1/sorts-repertoire', [
        'personnage_id' => $mage->id, 'element_actuel' => 'eau', 'nouveau_repertoire' => 'feu',
    ])->assertStatus(422);

    // 422 : hors hub.
    $groupe->update(['phase' => 'quete']);
    $this->putJson('/api/groupes/table-1/sorts-repertoire', [
        'personnage_id' => $mage->id, 'element_actuel' => 'eau', 'nouveau_repertoire' => 'detection',
    ])->assertStatus(422);
});

it('fige la liste COMPLÈTE acceptée par sorts.element (CHECK constraint, les deux sens)', function () {
    // `2026_10_06_090000_repertoires_optionnels_de_sorts.php` REMPLACE tout
    // l'enum, pas seulement l'étend : OUBLIER UNE SEULE valeur déjà en
    // production (`parchemin`, notamment) efface l'élément de tous les
    // sorts qui la portaient. Ce test fige la liste exacte pour que la
    // prochaine migration de cette colonne ne puisse plus refaire la même
    // faute sans qu'un test rouge ne le dise.
    $attendues = ['feu', 'eau', 'terre', 'air', 'barde', 'druide', 'warlock', 'elfique', 'parchemin',
        'protection', 'detection', 'tenebres'];

    // Dans un sens : rien au catalogue SEMÉ n'est hors de cette liste.
    $presentes = Sort::query()->distinct()->pluck('element')->all();
    expect(array_diff($presentes, $attendues))->toBe([]);

    // Dans l'autre : la colonne REFUSE toute valeur hors de cette liste —
    // preuve que le CHECK constraint est bien celui posé par CETTE
    // migration, pas un vestige d'avant son passage (ex. un `->change()`
    // qui aurait silencieusement laissé passer n'importe quelle chaîne).
    expect(fn () => Sort::create([
        'nom' => 'Sort hors enum (test)', 'element' => 'ombre_fantome', 'type' => 'utilitaire',
        'difficulte_parchemin' => 1, 'effet' => [],
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('un non-lanceur ne peut rien remplacer', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barbare = creerHeros($alice, $groupe, 'Grondin', 1); // défaut : barbare

    $this->putJson('/api/groupes/table-1/sorts-repertoire', [
        'personnage_id' => $barbare->id, 'element_actuel' => 'feu', 'nouveau_repertoire' => 'protection',
    ])->assertStatus(422);
});

// =====================================================================
// MUR DE PIERRE (Wall of Stone) — mobilier posé EN COURS DE QUÊTE
// =====================================================================

it('propose une entrée par PAIRE de cases libres, et pose un mur de 1 PV / 6 dés sur deux cases, qui bloque mouvement ET vue', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');

    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh());
    $sortOption = collect($options)->firstWhere('id', 'lancer_sort');
    $entrees = collect($sortOption['parametres']['sorts'])->where('sort_type', 'utilitaire')
        ->filter(fn ($e) => str_starts_with($e['nom'], 'Mur de Pierre'));

    // Quatre voisines libres du lanceur en (3,3), et pour CHACUNE trois suites
    // libres (le lanceur exclu) : 4 × 3 = douze paires, toutes distinctes.
    expect($entrees)->toHaveCount(12);
    expect($entrees->pluck('cle')->unique())->toHaveCount(12);
    expect($entrees->pluck('mode')->unique()->all())->toBe(['pose_mur_magique']);

    // Chaque paire est orthogonalement contiguë, et sa PREMIÈRE case touche le
    // lanceur — c'est la règle « la première adjacente au lanceur ».
    foreach ($entrees as $entree) {
        [$a, $b] = $entree['cases'];
        expect(abs($a['x'] - 3) + abs($a['y'] - 3))->toBe(1)
            ->and(abs($a['x'] - $b['x']) + abs($a['y'] - $b['y']))->toBe(1)
            ->and($b)->not->toBe(['x' => 3, 'y' => 3]);
    }

    // À l'EST puis au SUD : (4,3) puis (4,4), un mur VERTICAL de deux cases.
    $payload = lancerSortMorcar($ctx, 'Mur de Pierre', ':mur:4:3:4:4');

    expect($payload['mur_magique'])->toBeTrue()
        ->and($payload['cases'])->toBe([['x' => 4, 'y' => 3], ['x' => 4, 'y' => 4]])
        ->and($payload['mobilier']['nom'])->toBe('Mur de Pierre')
        ->and($payload['mobilier']['pv_body'])->toBe(1)
        ->and($payload['mobilier']['defense_dice'])->toBe(6);

    // Le sort est épuisé (S5) comme n'importe quel autre.
    expect((bool) $ctx['heros']->sorts()->where('nom', 'Mur de Pierre')->first()?->pivot->disponible)->toBeFalse();

    // Une seule entrée de mobilier couvre les deux cases, donc un seul PV à
    // perdre pour la détruire (« un PV perdu détruit tout le mur »).
    $murId = Mobilier::where('nom', 'Mur de Pierre')->value('id');
    $entreesMur = collect($ctx['quete']->fresh()->load('carte')->carte->grille['mobilier'])->where('mobilier_id', $murId);
    expect($entreesMur)->toHaveCount(1)
        ->and($entreesMur->first()['l'] * $entreesMur->first()['h'])->toBe(2);

    // Bloque mouvement ET vue sur LES DEUX cases — la carte dit « a solid,
    // impassable wall ».
    $grille = FabriqueGrille::pour($ctx['quete']->fresh()->load('carte'));
    expect($grille->estTraversable(4, 3))->toBeFalse()
        ->and($grille->estTraversable(4, 4))->toBeFalse()
        ->and($grille->ligneDeVue(3, 3, 5, 3))->toBeFalse()
        ->and($grille->ligneDeVue(3, 4, 5, 4))->toBeFalse();

    // Publié par EtatGroupe (table ET manette lisent la même source), avec sa
    // forme : 1 case de large, 2 de haut.
    $table = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    $meuble = collect($table['carte']['mobilier'])->firstWhere('nom', 'Mur de Pierre');
    expect($meuble)->not->toBeNull()
        ->and($meuble['l'])->toBe(1)
        ->and($meuble['h'])->toBe(2)
        ->and($meuble['pv_body'])->toBe(1)
        ->and($meuble['defense_dice'])->toBe(6)
        ->and($meuble['bloque_mouvement'])->toBeTrue()
        ->and($meuble['bloque_vue'])->toBeTrue();
});

it('un mur posé en COULOIR (sans salle) est publié dès que sa case sort du brouillard', function () {
    // Deux « salles » distinctes ne couvrant PAS toute la grille : (3,4) est
    // un couloir, hors de tout rectangle de salle.
    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 1],
        ['x' => 0, 'y' => 5, 'largeur' => 7, 'hauteur' => 2, 'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 5],
    ];
    $ctx = queteMinimalePourMorcar(['classe' => 'magicien'], $salles);
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');
    $ctx['etatHeros']->update(['position_x' => 3, 'position_y' => 3]); // hors des deux salles

    app(App\Partie\MoteurMobilier::class)->poserMurMagique($ctx['quete']->carte, [['x' => 3, 'y' => 4], ['x' => 3, 'y' => 5]], 'Mur de Pierre');

    $table = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    $meuble = collect($table['carte']['mobilier'])->firstWhere('nom', 'Mur de Pierre');
    // (3,4) est orthogonalement adjacent à (3,3), où se tient le héros — donc
    // visible, malgré l'absence de salle.
    expect($meuble)->not->toBeNull();

    // Et un mur posé HORS de toute zone visible (ni salle découverte, ni case
    // dans le brouillard) ne s'affiche PAS — même garde que les leviers de
    // couloir, pas une exception qui dirait tout, toujours.
    $ctx['quete']->carte->update(['grille' => array_merge($ctx['quete']->carte->grille, [
        'mobilier' => array_values(array_filter($ctx['quete']->carte->grille['mobilier'], fn ($m) => false)),
    ])]);
    app(App\Partie\MoteurMobilier::class)->poserMurMagique($ctx['quete']->carte->fresh(), [['x' => 0, 'y' => 6], ['x' => 1, 'y' => 6]], 'Mur de Pierre');
    $table2 = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    expect(collect($table2['carte']['mobilier'])->firstWhere('nom', 'Mur de Pierre'))->toBeNull();
});

it('ne dresse JAMAIS « Mur de Pierre » comme mobilier ordinaire à la génération procédurale', function () {
    // §Named gap : un mur magique n'a de sens QUE posé par un sort en jeu —
    // `AssembleurCarte::MOBILIER_POSE_EN_QUETE` l'exclut du tirage générique.
    $mur = Mobilier::where('nom', 'Mur de Pierre')->firstOrFail();
    expect($mur->boite)->toBe('wizards_of_morcar');

    $reflexion = new ReflectionClass(App\Partie\AssembleurCarte::class);
    $liste = $reflexion->getConstant('MOBILIER_POSE_EN_QUETE');
    expect($liste)->toContain('Mur de Pierre');
});

// =====================================================================
// INVISIBILITÉ — ne peut plus attaquer, ne peut plus être cible d'un sort
// =====================================================================

it('Invisibilité interdit d\'attaquer et rend immunisé à TOUT sort, ami compris', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'feu'); // pour tenter d'attaquer ensuite

    $payload = lancerSortMorcar($ctx, 'Invisibilité');
    expect($payload['condition'])->toBe('Caché')
        ->and($payload['cible']['personnage_id'])->toBe($ctx['heros']->id);

    $heros = $ctx['heros']->fresh();
    $sorts = app(MoteurSorts::class);
    expect($sorts->attaqueInterdite($heros))->toBeTrue()
        ->and($sorts->immuniteSorts($heros))->toBeTrue()
        ->and($sorts->estInattaquable($heros))->toBeTrue();

    // « may not attack » — ResolveurTour::frapper() est le SEUL choke-point,
    // et il refuse quelle que soit l'arme/la variante.
    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 4, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    expect(fn () => app(ResolveurTour::class)->frapper(
        $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['etatHeros']->fresh(),
        $heros, $instance,
    ))->toThrow(Illuminate\Validation\ValidationException::class);

    // « immune to all spells » — un ALLIÉ ne peut plus la cibler, même pour
    // la soigner : elle disparaît des cibles légales d'un sort utilitaire.
    $barde = creerHeros($ctx['alice'], $ctx['groupe'], 'Sylvaine', 2, ['classe' => 'elfe']);
    app(MoteurSorts::class)->attacherElement($barde, 'eau'); // Eau de Guérison
    EtatPersonnageQuete::create([
        'quete_id' => $ctx['quete']->id, 'personnage_id' => $barde->id,
        'position_x' => 4, 'position_y' => 3,
    ]);

    $soin = Sort::where('nom', 'Eau de Guérison')->firstOrFail();
    $cibles = app(MoteurSorts::class)->ciblesLegales(
        $soin, [], [
            ['type' => 'heros', 'id' => $heros->id, 'nom' => $heros->nom, 'x' => 3, 'y' => 3],
            ['type' => 'heros', 'id' => $barde->id, 'nom' => $barde->nom, 'x' => 4, 'y' => 3],
        ],
        ['x' => 4, 'y' => 3],
        FabriqueGrille::pour($ctx['quete']->fresh()->load('carte')),
    );

    expect(collect($cibles)->pluck('id'))->not->toContain($heros->id)
        ->and(collect($cibles)->pluck('id'))->toContain($barde->id);
});

// =====================================================================
// CHAÎNES DES TÉNÈBRES (Chains of Darkness) — saute mouvement + attaque
// =====================================================================

it('Chaînes des Ténèbres empêche un monstre de bouger et d\'attaquer, mais pas de se défendre', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'tenebres');

    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 4, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    $payload = lancerSortMorcar($ctx, 'Chaînes des Ténèbres', '', ['cible_id' => $instance->id, 'cible_type' => 'monstre']);
    expect($payload['condition'])->toBe('Enchaîné')
        ->and($payload['sans_jet'])->toBeTrue(); // « aucun jet proposé » (resistance: aucune)

    $instance->refresh();
    expect(app(MoteurSorts::class)->monstreA($instance, MoteurSorts::MONSTRE_ENCHAINE))->toBeTrue()
        // « may defend » : apresConditions() ne touche PAS la défense.
        ->and($instance->defenseEffective())->toBe((int) $instance->monstre->defense);

    // Son tour : ni déplacement ni attaque, et la condition retombe —
    // consommée à l'activation, comme saute_tour/enfume.
    $cibles = $ctx['quete']->etatsPersonnages()->with('personnage')->get();
    $resultat = (new ReflectionMethod(ResolveurTour::class, 'jouerMonstre'))->invoke(
        app(ResolveurTour::class), $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $instance, $cibles,
    );

    expect($resultat['type'])->toBe('monstre_enchaine')
        ->and($instance->fresh()->position_x)->toBe(4) // n'a pas bougé
        ->and(app(MoteurSorts::class)->monstreA($instance->fresh(), MoteurSorts::MONSTRE_ENCHAINE))->toBeFalse();
});

it('Enchaîné (catalogue) : immobile et désarmé, jamais inciblable — et Chaînes des Ténèbres ne vise plus de héros', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'tenebres');

    $allie = creerHeros($ctx['alice'], $ctx['groupe'], 'Brok', 2);
    EtatPersonnageQuete::create([
        'quete_id' => $ctx['quete']->id, 'personnage_id' => $allie->id,
        'position_x' => 4, 'position_y' => 3,
    ]);

    $chaines = Sort::where('nom', 'Chaînes des Ténèbres')->firstOrFail();

    // Cible UNIQUE (« one monster you can see », décision de René, 2026-10-09) :
    // la liste ne porte AUCUN héros, allié au contact compris. Le tir ami n'a
    // plus de chemin vers « Enchaîné » pour un héros.
    expect(app(MoteurSorts::class)->ciblesLegales($chaines, [], [[
        'type' => 'heros', 'id' => $allie->id, 'nom' => 'Brok', 'x' => 4, 'y' => 3,
    ]]))->toBe([]);

    // Le LECTEUR du catalogue reste testé : c'est lui qu'un sort de zone emploierait
    // pour poser « Enchaîné » sur un héros. Résolution directe, avec le nom que
    // *Chaînes des Ténèbres* déclare.
    app(MoteurSorts::class)->appliquerConditionCatalogue($allie, 'Enchaîné', $chaines);

    $condition = Condition::where('nom', 'Enchaîné')->firstOrFail();
    expect($condition->effet['deplacement_interdit'])->toBeTrue()
        ->and($condition->effet['attaque_interdite'])->toBeTrue()
        ->and($condition->effet['inattaquable'] ?? false)->toBeFalse() // rien n'empêche de le VISER
        ->and(app(MoteurSorts::class)->attaqueInterdite($allie->fresh()))->toBeTrue()
        ->and(app(MoteurSorts::class)->deplacementInterdit($allie->fresh()))->toBeTrue();
});

// =====================================================================
// FLÈCHES DE LA NUIT (Arrows of the Night) — défense = Mind de la cible
// =====================================================================

it('la cible défend avec autant de dés que de points de Mind actuels', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'tenebres');

    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 10, 'pv_body_max' => 10, 'pv_mind' => 3,
        'position_x' => 4, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    // 2 dés d'attaque (crânes), PUIS 3 dés de défense (Mind = 3) — un monstre
    // défenseur compte les boucliers NOIRS (6), jamais les blancs (`Engine\Combat`
    // §14) : les 3 boucliers annulent largement les 2 crânes.
    desFiges([1, 1, 6, 6, 6]);

    $payload = lancerSortMorcar($ctx, 'Flèches de la Nuit', 'sort:', ['cible_id' => $instance->id, 'cible_type' => 'monstre']);
    expect($payload['touches'])->toBe(2)
        ->and($payload['boucliers'])->toBe(3)
        ->and($payload['degats'])->toBe(0)
        ->and($payload['pv_body_apres'])->toBe(10);
});

it('à 0 point de Mind, la cible ne lance AUCUN dé de défense', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'tenebres');

    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 10, 'pv_body_max' => 10, 'pv_mind' => 0,
        'position_x' => 4, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    // SEULEMENT 2 dés (l'attaque) : aucun dé de défense à consommer.
    desFiges([1, 1]);

    $payload = lancerSortMorcar($ctx, 'Flèches de la Nuit', 'sort:', ['cible_id' => $instance->id, 'cible_type' => 'monstre']);
    expect($payload['touches'])->toBe(2)
        ->and($payload['boucliers'])->toBe(0)
        ->and($payload['degats'])->toBe(2)
        ->and($payload['pv_body_apres'])->toBe(8);
});

// =====================================================================
// TRÉSOR CONVOITÉ (Treasure Horde) — 3 cartes, les dangers repartent
// =====================================================================

it('pioche EXACTEMENT 3 cartes, applique les trésors et remet les dangers sous le paquet', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'detection');

    $ctx['quete']->update(['deck_fouille' => [
        ['issue' => 'tresor', 'or' => 40],
        ['issue' => 'piege', 'piege' => 'Fosse'],
        ['issue' => 'tresor', 'or' => 25],
        ['issue' => 'rien'],
    ]]);

    $orAvant = (int) $ctx['groupe']->fresh()->or;

    $payload = lancerSortMorcar($ctx, 'Trésor convoité');

    expect($payload['tresor_convoite'])->toBeTrue()
        ->and($payload['cartes_gardees'])->toHaveCount(2) // les 2 trésors sur les 3 premières cartes
        ->and($payload['cartes_remises'])->toBe(['piege']);

    expect((int) $ctx['groupe']->fresh()->or)->toBe($orAvant + 65); // 40 + 25

    // Le piège remis n'a RIEN déclenché, et la 4e carte (« rien ») n'a jamais
    // été tirée — exactement 3 piochées (chacune cycle sous le paquet,
    // `Quete::piocherCarte()`), le deck garde ses 4 cartes.
    $deckApres = $ctx['quete']->fresh()->deckFouille();
    expect($deckApres)->toHaveCount(4)
        ->and(collect($deckApres)->pluck('issue')->all())->toContain('piege');
});

// =====================================================================
// FILTRE D'ATTAQUE — « le menu ne propose jamais ce que le résolveur refusera »
// =====================================================================

it('le prédicat d\'attaque couvre TOUTES les options qui frappent avec le héros, et rien d\'autre', function () {
    foreach (App\Partie\MenuMoteur::TYPES_ATTAQUE_HEROS as $type) {
        expect(App\Partie\MenuMoteur::estAttaqueDuHeros(['type' => $type]))->toBeTrue("type « {$type} » doit être une attaque");
    }

    // Ce qui n'est PAS une frappe du héros : un sort, un déplacement, une
    // technique de mouvement du Moine (« garder ton déplacement »), l'attente.
    foreach (['sort', 'parchemin', 'deplacement', 'style', 'attente', 'jet', 'porte'] as $type) {
        expect(App\Partie\MenuMoteur::estAttaqueDuHeros(['type' => $type]))->toBeFalse("type « {$type} » n'est pas une attaque");
    }
});

it('un héros INVISIBLE ne voit plus aucune option d\'attaque, et le résolveur refuse celle qu\'il tenterait encore', function () {
    $ctx = queteMinimalePourMorcar();
    // Un monstre au contact (EST du héros en (3,3)) : le menu propose une attaque.
    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 4, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    $menuAvant = app(App\Partie\MenuMoteur::class)->generer($ctx['groupe']->fresh(), $ctx['heros']->fresh());
    $attaques = collect($menuAvant['options'])->filter(fn ($o) => App\Partie\MenuMoteur::estAttaqueDuHeros($o));
    expect($attaques)->not->toBeEmpty('sans invisibilité, le monstre au contact doit être attaquable');
    $option = $attaques->first();

    // Invisibilité lancée par le héros lui-même.
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');
    lancerSortMorcar($ctx, 'Invisibilité');

    $menuApres = app(App\Partie\MenuMoteur::class)->generer($ctx['groupe']->fresh(), $ctx['heros']->fresh());
    expect(collect($menuApres['options'])->filter(fn ($o) => App\Partie\MenuMoteur::estAttaqueDuHeros($o)))->toBeEmpty();

    // Le résolveur refuse la même option, par son message (pas seulement par
    // une exception quelconque), et ne dépense rien.
    try {
        app(ResolveurTour::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), $option, $option['parametres'] ?? []);
        $refuse = false;
    } catch (Illuminate\Validation\ValidationException $e) {
        $refuse = str_contains((string) ($e->errors()['option_id'][0] ?? ''), 'invisible');
    }
    expect($refuse)->toBeTrue('le résolveur doit refuser une attaque d\'un héros invisible, pour la même raison que le menu');
});

// =====================================================================
// REFUS ET DÉCISION PUBLIÉE — un répertoire perdu en silence, un parchemin
// =====================================================================

it('refuse de prendre un répertoire déjà connu, et de remplacer un parchemin ; /moi publie la décision', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $mage = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);
    app(MoteurSorts::class)->attacherElement($mage, 'feu');
    app(MoteurSorts::class)->attacherElement($mage, 'eau');
    app(MoteurSorts::class)->attacherElement($mage, 'terre');
    app(MoteurSorts::class)->attacherElement($mage, 'protection');

    // La décision que la manette affiche, publiée par /moi.
    $perso = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->firstWhere('id', $mage->id);
    expect($perso['repertoires']['offerts'])->toBe(['detection', 'tenebres'])
        ->and($perso['repertoires']['remplacables'])->toContain('protection')
        ->and($perso['repertoires']['remplacables'])->not->toContain('parchemin');

    // 422 : prendre « protection » alors qu'il la connaît déjà. Rien ne bouge.
    $this->putJson('/api/groupes/table-1/sorts-repertoire', [
        'personnage_id' => $mage->id, 'element_actuel' => 'feu', 'nouveau_repertoire' => 'protection',
    ])->assertStatus(422);
    expect($mage->fresh()->sorts()->pluck('element')->unique()->sort()->values()->all())
        ->toBe(['eau', 'feu', 'protection', 'terre']);

    // 422 : un parchemin n'est pas un répertoire — il reste attaché, intact.
    $parchemin = Sort::where('element', 'parchemin')->firstOrFail();
    $mage->sorts()->syncWithoutDetaching([$parchemin->id => ['disponible' => true]]);
    $this->putJson('/api/groupes/table-1/sorts-repertoire', [
        'personnage_id' => $mage->id, 'element_actuel' => 'parchemin', 'nouveau_repertoire' => 'detection',
    ])->assertStatus(422);
    expect($mage->fresh()->sorts()->where('sorts.id', $parchemin->id)->exists())->toBeTrue();

    // Un non-lanceur : ni remplaçable, ni offert.
    $barbare = creerHeros($alice, $groupe, 'Grondin', 2);
    $persoBarbare = collect($this->getJson('/api/moi')->json('joueur.personnages'))->firstWhere('id', $barbare->id);
    expect($persoBarbare['repertoires'])->toBe(['remplacables' => [], 'offerts' => []]);
});

// =====================================================================
// CLAIRVOYANCE — le contenu d'UNE salle inconnue, sans lever le brouillard
// =====================================================================

/** Deux salles de 3×7 séparées par un couloir en x=3 ; la salle 0 est déjà vue. */
function queteAvecDeuxSallesPourClairvoyance(): array
{
    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 7, 'theme' => 'generique', 'mediane_x' => 1, 'mediane_y' => 3],
        ['x' => 4, 'y' => 0, 'largeur' => 3, 'hauteur' => 7, 'theme' => 'generique', 'mediane_x' => 5, 'mediane_y' => 3],
    ];
    $ctx = queteMinimalePourMorcar(['classe' => 'magicien'], $salles);
    $ctx['quete']->update(['salles_decouvertes' => [0]]);
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'detection');

    return $ctx + ['quete' => $ctx['quete']->fresh()->load('carte')];
}

it('Clairvoyance n\'offre que les salles que le groupe ignore, avec un repère et sans aucun contenu', function () {
    $ctx = queteAvecDeuxSallesPourClairvoyance();

    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh());
    $entrees = collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts'])->where('mode', 'vision_salle');

    // Salle 0 déjà vue : jamais offerte. Salle 1 : à deux cases à l'EST.
    expect($entrees)->toHaveCount(1)
        ->and($entrees->first()['salle'])->toBe(1)
        ->and($entrees->first()['nom'])->toContain('au est, à 2 cases')
        ->and($entrees->first()['nom'])->not->toContain('monstre')
        ->and($entrees->first()['nom'])->not->toContain('vide');
});

it('Clairvoyance montre les monstres de la salle choisie, PAS le reste, et ne lève pas le brouillard', function () {
    $ctx = queteAvecDeuxSallesPourClairvoyance();

    // Un monstre CACHÉ dans la salle inconnue (5,2), un autre dans la salle vue (1,2).
    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 5, 'position_y' => 2, 'etat' => 'actif', 'revele' => false,
    ]);
    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 1, 'position_y' => 2, 'etat' => 'actif', 'revele' => false,
    ]);

    $payload = lancerSortMorcar($ctx, 'Clairvoyance', ':salle:1');

    expect($payload['mode'])->toBe('vision_salle')
        ->and($payload['salle'])->toBe(1)
        ->and($payload['vide'])->toBeFalse()
        ->and($payload['monstres'])->toHaveCount(1)       // celui de la salle 1 seulement
        ->and($payload['texte'])->toContain('1 monstre');

    // Information, pas exploration : le groupe ne voit toujours que la salle 0.
    expect($ctx['quete']->fresh()->sallesDecouvertes())->toBe([0]);

    // Le sort est épuisé comme tout sort de la quête.
    expect((bool) $ctx['heros']->fresh()->sorts()->where('nom', 'Clairvoyance')->first()?->pivot->disponible)->toBeFalse();
});

it('Clairvoyance sur une salle VIDE le dit, et ne propose ensuite aucune seconde salle (« may not try again »)', function () {
    $ctx = queteAvecDeuxSallesPourClairvoyance();

    $payload = lancerSortMorcar($ctx, 'Clairvoyance', ':salle:1');

    expect($payload['vide'])->toBeTrue()
        ->and($payload['monstres'])->toBe([])
        ->and($payload['texte'])->toBe('La salle est vide.');

    // Le sort est consommé : plus aucune entrée Clairvoyance ce tour-ci, même
    // avec une salle encore inconnue sous la main — pas de seconde tentative.
    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh());
    $entrees = collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])
        ->filter(fn ($e) => ($e['mode'] ?? null) === 'vision_salle' && ($e['disponible'] ?? false));
    expect($entrees)->toBeEmpty();
});

// =====================================================================
// UNLEARN — un Sorcier de Dread perd UN sort, pour toute la quête
// =====================================================================

/** Un monstre dont l'archétype porte un répertoire de sorts : un vrai Sorcier. */
function monstreSorcierDread(): Monstre
{
    return Monstre::whereNotNull('archetype_lanceur')->get()
        ->first(fn (Monstre $m) => ! empty(config("archetypes_lanceurs.{$m->archetype_lanceur}.sorts")))
        ?? throw new RuntimeException('Aucun Sorcier de Dread au catalogue de test.');
}

it('Unlearn fait oublier UN sort à un Sorcier de Dread en vue, pour toute la quête', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');

    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => monstreSorcierDread()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 5, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);
    $repertoire = app(App\Partie\MoteurDread::class)->sortsOubliables($instance, $ctx['quete']);
    expect($repertoire)->not->toBeEmpty();

    $payload = lancerSortMorcar($ctx, 'Désapprentissage', '', ['cible_id' => $instance->id, 'cible_type' => 'monstre']);

    expect($payload['mode'])->toBe('oubli_sort')
        ->and($repertoire)->toContain($payload['sort_oublie'])
        ->and($payload['texte'])->toContain($payload['sort_oublie']);

    // Rangé en base pour CETTE quête, et retiré du répertoire du Sorcier.
    $quete = $ctx['quete']->fresh();
    expect(app(App\Partie\OubliSorts::class)->oublies(
        $quete, App\Partie\OubliSorts::CIBLE_INSTANCE, $instance->id, App\Partie\OubliSorts::SOURCE_DREAD,
    ))->toBe([$payload['sort_oublie']])
        ->and(app(App\Partie\MoteurDread::class)->sortsOubliables($instance->fresh(), $quete))
        ->not->toContain($payload['sort_oublie'])
        ->and(count(app(App\Partie\MoteurDread::class)->sortsOubliables($instance->fresh(), $quete)))
        ->toBe(count($repertoire) - 1);

    // Le sort est épuisé comme tout sort de la quête.
    expect((bool) $ctx['heros']->fresh()->sorts()->where('nom', 'Désapprentissage')->first()?->pivot->disponible)->toBeFalse();
});

it('sans Sorcier de Dread en vue, Unlearn n\'est pas offert — un monstre ordinaire n\'est pas une cible', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');

    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::whereNull('archetype_lanceur')->firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 5, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    $options = app(App\Partie\MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh());
    $ouverts = collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])
        ->filter(fn ($e) => ($e['nom'] ?? null) === 'Désapprentissage' && ($e['disponible'] ?? false) === true && ! empty($e['cibles'] ?? []));

    expect($ouverts)->toBeEmpty();
});

it('un sort OUBLIÉ pour la quête est grisé — jamais lançable — et l\'oubli reste scellé à SA quête', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');
    app(App\Partie\OubliSorts::class)->oublier(
        $ctx['quete'], App\Partie\OubliSorts::CIBLE_PERSONNAGE, $ctx['heros']->id, App\Partie\OubliSorts::SOURCE_SORT, 'Invisibilité',
    );

    $options = app(App\Partie\MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh());
    $sorts = collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? []);

    // Les entrées du mur portent la direction dans leur nom (« Mur de Pierre — au nord… »).
    expect($sorts->firstWhere('nom', 'Invisibilité')['disponible'])->toBeFalse()
        ->and($sorts->first(fn ($e) => str_starts_with((string) $e['nom'], 'Mur de Pierre'))['disponible'])->toBeTrue();

    // Une quête SUIVANTE ne le porte pas : l'oubli n'existe que pour la quête qui l'a produit.
    $suivante = Quete::create([
        'groupe_id' => $ctx['groupe']->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête suivante', 'position_arc' => 2, 'type_jalon' => 'normale',
        'etat' => 'en_cours', 'or_initial' => 0,
    ]);
    expect(app(App\Partie\OubliSorts::class)->oublies(
        $suivante, App\Partie\OubliSorts::CIBLE_PERSONNAGE, $ctx['heros']->id, App\Partie\OubliSorts::SOURCE_SORT,
    ))->toBe([]);
});

// =====================================================================
// RENDU — le fil et la manette disent le résultat décidé (2026-10-08)
// =====================================================================

it('Clairvoyance porte son texte au fil : le fil ne dit plus seulement « lance »', function () {
    $ctx = queteAvecDeuxSallesPourClairvoyance();

    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 5, 'position_y' => 2, 'etat' => 'actif', 'revele' => false,
    ]);

    $payload = lancerSortMorcar($ctx, 'Clairvoyance', ':salle:1');

    // Ce que la manette lit sur la réponse du POST choix : le mode, le nom, le texte.
    expect($payload['mode'])->toBe('vision_salle')
        ->and($payload['sort']['nom'])->toBe('Clairvoyance')
        ->and($payload['texte'])->toContain('1 monstre');

    $fil = collect(app(\App\Partie\JournalCombat::class)->depuisResultat($payload, 'Aldric'))->pluck('texte')->all();

    expect($fil)->toBe(["Aldric lance Clairvoyance — {$payload['texte']}"]);
});

it('Unlearn porte son texte au fil : on lit QUEL sort est oublié, et chez qui', function () {
    $ctx = queteMinimalePourMorcar();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');

    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => monstreSorcierDread()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 5, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    $payload = lancerSortMorcar($ctx, 'Désapprentissage', '', ['cible_id' => $instance->id, 'cible_type' => 'monstre']);

    $fil = collect(app(\App\Partie\JournalCombat::class)->depuisResultat($payload, 'Aldric'))->pluck('texte')->all();

    expect($fil)->toBe(["Aldric lance Désapprentissage — {$payload['texte']}"])
        ->and($payload['texte'])->toContain($payload['sort_oublie']);
});
