<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Déplacement LIBRE d'un allié (2026-10-17) — défaut relevé en partie réelle : le
 * compagnon ne se voyait offrir qu'« Approcher <monstre> ». Sans monstre en vue, il ne
 * pouvait que terminer son tour, et restait en arrière quand le groupe passait d'une
 * salle à l'autre. Il reçoit désormais les cases où il peut S'ARRÊTER, à SA vitesse, sur
 * la grille de passage du héros (`DeplacementHeros::destinationsAllie()`), brouillard
 * compris. Tests EN JEU : chaque geste passe par les routes de la manette.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, MercenaireSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Une quête à UN héros (Albrecht, qui contrôle l'allié) et un Fauchard recruté, sur une
 * carte taillée à la main : un COULOIR horizontal (ligne y = 1, cases x = 1 à 10) entouré
 * de murs. Le héros est en (2,1), l'allié en (1,1). Aucun monstre n'est en vue : le seul
 * monstre actif est posé SUR UN MUR et caché, il ne bloque aucune case.
 *
 * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles  métadonnées de salles
 *                                                                           (vide = pas de brouillard)
 * @return array{0: \App\Models\Groupe, 1: Quete, 2: \App\Models\Personnage, 3: GroupeMercenaire}
 */
function queteAlliesLibres(array $salles = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe('table-1', 20);
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    test()->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    oublierQuetesFictivesDeGardien($groupe);
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update(['position_x' => 5, 'position_y' => 0, 'revele' => false]);

    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['cases'] = array_fill(0, 3, array_fill(0, 12, 'm'));
    for ($x = 1; $x <= 10; $x++) {
        $grille['cases'][1][$x] = 's';
    }
    foreach (['portes', 'mobilier', 'pieges', 'leviers', 'epreuves', 'terrain', 'glace'] as $couche) {
        $grille[$couche] = [];
    }
    $grille['salles'] = $salles;
    $carte->update(['grille' => $grille]);

    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)
        ->update(['position_x' => 2, 'position_y' => 1]);

    $allie = $groupe->fresh()->mercenaires()->first();
    $allie->update(['position_x' => 1, 'position_y' => 1]);

    return [$groupe, $quete, $heros, $allie->fresh()];
}

/** Le héros termine son tour : c'est alors la manette du joueur qui reçoit le menu de l'allié. */
function passerAuTourDeLAllie(): void
{
    desFiges(array_fill(0, 80, 4));
    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
}

/** @return array<string, mixed> */
function optionAllie(string $id): ?array
{
    $menu = test()->getJson('/api/groupes/table-1/menu')->assertOk()->json();

    return collect($menu['menu']['options'] ?? [])->firstWhere('id', $id);
}

it('un allié SANS monstre en vue reçoit les cases où il peut s\'arrêter, à SA vitesse', function () {
    [, , , $allie] = queteAlliesLibres();
    $vitesse = (int) $allie->mercenaire->deplacement;

    passerAuTourDeLAllie();

    $menu = test()->getJson('/api/groupes/table-1/menu')->assertOk()->json();
    expect($menu['allie_id'])->toBe($allie->id);

    $ids = collect($menu['menu']['options'])->pluck('id')->all();
    expect(in_array('se_deplacer_case_allie', $ids, true))->toBeTrue()
        ->and(in_array('attendre_allie', $ids, true))->toBeTrue()
        ->and(in_array('attaquer_allie', $ids, true))->toBeFalse()
        ->and(in_array('se_deplacer_allie', $ids, true))->toBeFalse();

    $libre = optionAllie('se_deplacer_case_allie');
    expect($libre['type'])->toBe('deplacement_case_allie')
        ->and($libre['parametres']['portee'])->toBe($vitesse);

    $cases = collect($libre['parametres']['destinations'])->keyBy(fn (array $d) => "{$d['x']},{$d['y']}");

    // La case du héros n'est jamais une destination ; le couloir se traverse sans s'y arrêter.
    expect($cases->has('2,1'))->toBeFalse()
        ->and($cases->get('3,1')['cout'])->toBe(2)
        ->and($cases->get((1 + $vitesse).',1')['cout'])->toBe($vitesse)
        ->and($cases->has((2 + $vitesse).',1'))->toBeFalse();
});

it('déplace l\'allié sur une case publiée : sa position change, et le déplacement est consommé', function () {
    [, , , $allie] = queteAlliesLibres();

    passerAuTourDeLAllie();

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer_case_allie',
        'parametres' => ['x' => 4, 'y' => 1],
    ])->assertStatus(202);

    expect($reponse->json('resultat.type'))->toBe('deplacement_allie')
        ->and($reponse->json('resultat.vers'))->toBe(['x' => 4, 'y' => 1]);

    $allie->refresh();
    expect([(int) $allie->position_x, (int) $allie->position_y])->toBe([4, 1])
        ->and((bool) $allie->a_deplace)->toBeTrue();

    // Un second déplacement n'est plus offert : son créneau est pris.
    expect(optionAllie('se_deplacer_case_allie'))->toBeNull()
        ->and(optionAllie('attendre_allie'))->not->toBeNull();
});

it('refuse une case hors de la liste publiée : au-delà de la vitesse, sur le héros, dans un mur', function () {
    [, , , $allie] = queteAlliesLibres();
    $vitesse = (int) $allie->mercenaire->deplacement;

    passerAuTourDeLAllie();

    $horsPortee = (2 + $vitesse);

    foreach ([[$horsPortee, 1], [2, 1], [0, 0]] as [$x, $y]) {
        $this->postJson('/api/groupes/table-1/choix', [
            'option_id' => 'se_deplacer_case_allie',
            'parametres' => ['x' => $x, 'y' => $y],
        ])->assertStatus(422);
    }

    // Un refus ne dépense rien : l'allié n'a pas bougé, et il peut encore choisir.
    $allie->refresh();
    expect([(int) $allie->position_x, (int) $allie->position_y])->toBe([1, 1])
        ->and((bool) $allie->a_deplace)->toBeFalse()
        ->and(optionAllie('se_deplacer_case_allie'))->not->toBeNull();
});

it('ne franchit JAMAIS le brouillard : une salle non révélée n\'est ni destination, ni passage', function () {
    // Salle 0 (départ, révélée) : x 1 à 3. Salle 1 (non révélée) : x 4 à 10.
    $salles = [
        ['x' => 1, 'y' => 0, 'largeur' => 3, 'hauteur' => 3],
        ['x' => 4, 'y' => 0, 'largeur' => 7, 'hauteur' => 3],
    ];
    queteAlliesLibres($salles);

    passerAuTourDeLAllie();

    $cases = collect(optionAllie('se_deplacer_case_allie')['parametres']['destinations'])
        ->map(fn (array $d) => [$d['x'], $d['y']])
        ->values()
        ->all();

    // Seule la case révélée derrière le héros est publiée — rien de la salle 1.
    expect($cases)->toBe([[3, 1]]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer_case_allie',
        'parametres' => ['x' => 4, 'y' => 1],
    ])->assertStatus(422);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer_case_allie',
        'parametres' => ['x' => 3, 'y' => 1],
    ])->assertStatus(202);
});

it('l\'aperçu d\'un allié rend SON trajet, sur la même grille, sans piège à signaler', function () {
    [, , , $allie] = queteAlliesLibres();
    $vitesse = (int) $allie->mercenaire->deplacement;

    passerAuTourDeLAllie();

    $apercu = $this->postJson('/api/groupes/table-1/deplacement/apercu', ['x' => 4, 'y' => 1])
        ->assertOk()
        ->json();

    expect($apercu['atteignable'])->toBeTrue()
        ->and($apercu['cout'])->toBe(3)
        ->and($apercu['restant'])->toBe($vitesse)
        ->and($apercu['restant_apres'])->toBe($vitesse - 3)
        ->and($apercu['pieges'])->toBe([])
        ->and($apercu['traverse_roche'])->toBeFalse()
        ->and(array_map(fn (array $c) => [$c['x'], $c['y']], $apercu['chemin']))->toBe([[2, 1], [3, 1], [4, 1]]);

    $trop = $this->postJson('/api/groupes/table-1/deplacement/apercu', ['x' => 2 + $vitesse, 'y' => 1])
        ->assertOk()
        ->json();

    expect($trop['atteignable'])->toBeFalse()
        ->and($trop['raison'])->not->toBeNull();
});
