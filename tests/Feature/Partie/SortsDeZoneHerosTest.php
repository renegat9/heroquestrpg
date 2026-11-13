<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * LES SORTS DE ZONE D'UN HÉROS — le socle (plan `docs/plan-parchemins-glace.md`, lot 1, 2026-11-13).
 *
 * Trois défauts relevés au plan, reproduits ici AVANT le correctif :
 *  (a) un sort de héros à `zone` était OFFERT sans cible, puis REFUSÉ « Cible requise » par le résolveur ;
 *  (b) le libellé du Chant de guérison disait « toute la salle, alliés compris » : il soigne les héros EN VUE ;
 *  (c) un sort de zone interdit en couloir (`hors_couloir`) n'avait aucun lecteur.
 *
 * Et ce que le socle doit faire : une zone de dégâts frappe ses figures une à une, dés ou montant fixe, et le tir ami
 * sur les héros de la zone passe par la même frappe que le sort visant un monstre (défense, Brassard, réductions).
 *
 * Les sorts de ces tests sont FABRIQUÉS ici (aucune donnée de catalogue) : leur rôle est de prouver les lecteurs. La
 * donnée réelle (Morsure du Froid) est prouvée dans MorsureDuFroidTest.php.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Un sort de zone de test, fabriqué (les clés sont celles du registre `MotsClesSort`).
 *
 * @param  array<string, mixed>  $effet
 */
function sortDeZoneTest(string $nom, array $effet): Sort
{
    return Sort::create([
        'element' => 'parchemin',
        'nom' => $nom,
        'type' => 'degats',
        'difficulte_parchemin' => 1,
        'effet' => $effet,
        'description' => "Sort de test « {$nom} ».",
    ]);
}

/**
 * Une quête sur un plateau 7×7 : UNE salle (tout le plateau) ou AUCUNE (couloir), le lanceur (magicien) au centre (3,3).
 *
 * `$compagne` : une seconde héroïne (Brunhilde), posée en (4,3) — à droite du lanceur. Elle doit exister AVANT le départ
 * de la quête : sans état de quête, elle n'est pas une figure du plateau.
 *
 * @return array{alice: JoueurAuthentifiable, groupe: \App\Models\Groupe, lanceur: Personnage, quete: Quete, etat: EtatPersonnageQuete, compagne: ?Personnage}
 */
function zoneScene(bool $dansUneSalle = true, bool $compagne = false): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $lanceur = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    $brunhilde = null;

    if ($compagne) {
        $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
        $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2);
    }

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $lanceur->id)->firstOrFail();

    $taille = 7;
    $quete->carte->update([
        'largeur' => $taille,
        'hauteur' => $taille,
        'grille' => [
            'largeur' => $taille, 'hauteur' => $taille,
            'cases' => array_fill(0, $taille, array_fill(0, $taille, 's')),
            'salles' => $dansUneSalle
                ? [['x' => 0, 'y' => 0, 'largeur' => $taille, 'hauteur' => $taille, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]]
                : [],
            'portes' => [], 'pieges' => [], 'mobilier' => [], 'terrain' => [], 'leviers' => [], 'epreuves' => [],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);
    $quete->update(['salles_decouvertes' => $dansUneSalle ? [0] : []]);

    $quete->instancesMonstres()->update(['etat' => 'vaincu', 'revele' => false]);
    $etat->update([
        'position_x' => 3, 'position_y' => 3,
        'a_joue' => false, 'a_deplace' => false, 'a_agi' => false, 'tombe' => false,
        'deplacement_restant' => null, 'capacites_tour' => null,
    ]);

    if ($brunhilde !== null) {
        EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $brunhilde->id)
            ->update(['position_x' => 4, 'position_y' => 3, 'tombe' => false]);
    }

    Cache::forget(GenererMenu::cleMenu($groupe->id, $alice->id));

    return compact('alice', 'groupe', 'lanceur', 'quete', 'etat') + ['compagne' => $brunhilde];
}

/** Pose une figure révélée et active sur (x, y). */
function figureZone(Quete $quete, string $nomBase, int $x, int $y, int $pv): InstanceMonstre
{
    return InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => Monstre::where('nom_base', $nomBase)->firstOrFail()->id,
        'pv_body' => $pv, 'pv_body_max' => $pv, 'pv_mind' => 0,
        'etat' => 'actif', 'revele' => true,
        'position_x' => $x, 'position_y' => $y,
    ]);
}

/** Le menu RÉEL. @return list<array<string, mixed>> */
function menuZone(JoueurAuthentifiable $alice): array
{
    Cache::forget(GenererMenu::cleMenu(\App\Models\Groupe::where('identifiant', 'table-1')->value('id'), $alice->id));

    return test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options');
}

/** L'entrée de sort connu (`lancer_sort`) dont le nom commence par `$nom`, ou null. @return array<string, mixed>|null */
function entreeLancerSort(JoueurAuthentifiable $alice, string $nom): ?array
{
    $sorts = collect(data_get(collect(menuZone($alice))->firstWhere('id', 'lancer_sort'), 'parametres.sorts', []));

    return $sorts->first(fn (array $e) => str_starts_with((string) ($e['nom'] ?? ''), $nom));
}

/** Donne un sort connu au lanceur (une seule fois par quête, comme tout sort). */
function apprendreZone(Personnage $lanceur, Sort $sort): void
{
    $lanceur->sorts()->attach($sort->id, ['disponible' => true]);
}

// ==================================================================
// (a) OFFERT ⇒ JOUABLE : une zone de dégâts porte ses figures et la route l'accepte
// ==================================================================

it('(a) une zone de dégâts est OFFERTE avec ses figures (touches) et la route ACCEPTE l\'entrée — jamais « Cible requise »', function () {
    $ctx = zoneScene();
    $sort = sortDeZoneTest('Brume test', [
        'cible' => 'monstres_zone', 'zone' => 'contact',
        'degats_fixes' => 1, 'defense_applicable' => false, 'type_degat' => 'froid',
    ]);
    apprendreZone($ctx['lanceur'], $sort);
    figureZone($ctx['quete'], 'Gobelin', 4, 3, 3);

    $entree = entreeLancerSort($ctx['alice'], 'Brume test');

    expect($entree)->not->toBeNull('la zone a une figure à son contact : elle doit être offerte')
        ->and($entree)->not->toHaveKey('cibles')
        ->and($entree['touches'] ?? [])->toHaveCount(1, 'la décision de frappe est publiée, figure par figure');

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => $entree['cle']],
    ])->assertStatus(202)->assertJsonPath('resultat.zone', 'contact');
});

it('(a) une zone de dégâts SANS figure n\'est pas offerte — et refusée si on la force (la liste fait foi)', function () {
    $ctx = zoneScene();
    $sort = sortDeZoneTest('Brume test', [
        'cible' => 'monstres_zone', 'zone' => 'contact',
        'degats_fixes' => 1, 'defense_applicable' => false, 'type_degat' => 'froid',
    ]);
    apprendreZone($ctx['lanceur'], $sort);

    // Personne à son contact : aucune entrée — et le sort n'est pas consommé.
    expect(entreeLancerSort($ctx['alice'], 'Brume test'))->toBeNull();

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sort->id}"],
    ])->assertStatus(422);

    expect((bool) $ctx['lanceur']->sorts()->whereKey($sort->id)->first()->pivot->disponible)->toBeTrue('refusé : le sort reste disponible');
});

// ==================================================================
// (b) LE LIBELLÉ DIT CE QUE LE SORT FAIT
// ==================================================================

it('(b) le Chant de guérison dit qu\'il soigne le lanceur et les héros EN VUE — pas « toute la salle »', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barde = creerHeros($alice, $groupe, 'Lirel', 1, ['classe' => 'barde']);
    app(\App\Partie\MoteurSorts::class)->attacherRepertoireClasse($barde, 'barde');

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $entree = entreeLancerSort($alice, 'Chant de guérison');

    expect($entree)->not->toBeNull()
        ->and($entree['nom'])->toContain("héros qu'il voit")
        ->and($entree['nom'])->not->toContain('toute la salle');
});

it('(b) le libellé de la Flamme hypnotique dit toujours la salle, alliés compris : le registre lui rend son texte', function () {
    expect(\App\Engine\MotsClesSort::ZONES['salle_du_lanceur']['libelle'])->toBe('toute la salle, alliés compris');
});

// ==================================================================
// (c) hors_couloir : lu au menu, par le même calcul que la résolution
// ==================================================================

it('(c) un sort de zone « hors_couloir » n\'est PAS offert dans un couloir, et l\'est dans une salle', function () {
    $ctx = zoneScene(dansUneSalle: false);
    $sort = sortDeZoneTest('Tempête couloir test', [
        'cible' => 'monstres_zone', 'zone' => 'contact',
        'degats_fixes' => 1, 'defense_applicable' => false, 'hors_couloir' => true,
    ]);
    apprendreZone($ctx['lanceur'], $sort);
    figureZone($ctx['quete'], 'Gobelin', 4, 3, 3);

    expect(entreeLancerSort($ctx['alice'], 'Tempête couloir test'))
        ->toBeNull('le lanceur est dans un couloir : le sort n\'est pas offert');

    // Le même sort, dans une salle : offert.
    $ctx['quete']->carte->update(['grille' => array_replace($ctx['quete']->carte->grille, [
        'salles' => [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
    ])]);
    $ctx['quete']->update(['salles_decouvertes' => [0]]);

    expect(entreeLancerSort($ctx['alice'], 'Tempête couloir test'))->not->toBeNull('dans une salle, il est offert');
});

// ==================================================================
// LE SOCLE EN JEU : tir ami sur les héros de la zone, dés sans défense
// ==================================================================

it('une zone « figures_zone » frappe AUSSI les héros de la zone, séparément, à dés sans défense (tir ami)', function () {
    $ctx = zoneScene(compagne: true);
    $sort = sortDeZoneTest('Vague test', [
        'cible' => 'figures_zone', 'zone' => 'contact',
        'des_degats' => 2, 'defense_applicable' => false,
    ]);
    apprendreZone($ctx['lanceur'], $sort);

    $compagne = $ctx['compagne'];
    $monstre = figureZone($ctx['quete'], 'Gobelin', 3, 2, 6);

    // Deux dés d'attaque par figure, tous crânes : deux points chacune.
    desFiges([1, 1, 1, 1]);

    $entree = entreeLancerSort($ctx['alice'], 'Vague test');
    expect(collect($entree['touches'] ?? [])->pluck('type')->sort()->values()->all())
        ->toBe(['heros', 'monstre'], 'la décision publiée nomme le héros ET le monstre');

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => $entree['cle']],
    ])->assertStatus(202)->json('resultat');

    $entreeHeros = collect($resultat['resultats'])->firstWhere('cible.type', 'heros');

    expect((int) $compagne->fresh()->pv_body)->toBe(6, 'Brunhilde : 2 dés, 2 crânes, −2 PV')
        ->and((int) $monstre->fresh()->pv_body)->toBe(4, 'le Gobelin : même coup, −2 PV')
        ->and(collect($resultat['resultats'])->pluck('cible.type')->sort()->values()->all())->toBe(['heros', 'monstre'])
        ->and($entreeHeros['degats'])->toBe(2)
        ->and($entreeHeros['pv_body_apres'])->toBe(6)
        ->and($entreeHeros['cible']['id'])->toBe($compagne->id)
        ->and($entreeHeros['cible']['nom'])->toBe('Brunhilde');
});

it('la zone de dégâts ne frappe PAS une figure dont la zone est hors portée : seulement le contact', function () {
    $ctx = zoneScene();
    $sort = sortDeZoneTest('Contact seul test', [
        'cible' => 'figures_zone', 'zone' => 'contact',
        'degats_fixes' => 1, 'defense_applicable' => false,
    ]);
    apprendreZone($ctx['lanceur'], $sort);

    $proche = figureZone($ctx['quete'], 'Gobelin', 4, 3, 3);
    $loin = figureZone($ctx['quete'], 'Gobelin', 5, 5, 3);

    $entree = entreeLancerSort($ctx['alice'], 'Contact seul test');
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort', 'parametres' => ['cle' => $entree['cle']],
    ])->assertStatus(202);

    expect((int) $proche->fresh()->pv_body)->toBe(2)
        ->and((int) $loin->fresh()->pv_body)->toBe(3);
});
