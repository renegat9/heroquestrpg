<?php

declare(strict_types=1);

use App\Models\Competence;
use App\Partie\ScorePuissance;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LE POUVOIR FINAL (René, 2026-10-22) — le 4e rang d'une colonne coûte 2 points.
 *
 * Trois verrous, un bloc pour chacun :
 *  1. LE SOLDE   — points = (niveau − 1) − Σ cout des nœuds non innés acquis ;
 *  2. LA DÉCISION — `achats[]` de /api/moi et le 422 de l'achat disent la MÊME phrase ;
 *  3. LE SCORE   — +1 par point dépensé, jamais pour une capacité de carte.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class]);
});

/** Identifiant d'un nœud de grille, par son arbre et son nom. */
function idNoeudDeGrille(string $classe, string $nom): int
{
    return (int) Competence::where('classe', $classe)->where('nom', $nom)->value('id');
}

/** Achète un nœud par la vraie route, et exige le 201. */
function acheterUnNoeud($test, App\Models\Personnage $hero, string $classe, string $nom): void
{
    $test->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id, 'competence_id' => idNoeudDeGrille($classe, $nom),
    ])->assertCreated();
}

it('compte le pouvoir final pour 2 points, et refuse l\'achat faute de solde', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Borin', 1, ['classe' => 'barbare', 'niveau' => 5]);

    // Niveau 5 : 4 points. La colonne Furie se monte à 3 (1 point par rang) : il reste 1.
    foreach (['Coup puissant', 'Frénésie', 'Sang qui bout'] as $rang) {
        acheterUnNoeud($this, $hero, 'barbare', $rang);
    }

    expect($hero->fresh()->pointsCompetence())->toBe(1);

    // Le pouvoir final coûte 2 : refusé, et la phrase dit le solde.
    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id, 'competence_id' => idNoeudDeGrille('barbare', 'Représailles sauvages'),
    ])->assertStatus(422)
        ->assertJsonPath('errors.competence_id.0', "Coûte 2 points de compétence : il t'en reste 1.");

    expect($hero->fresh()->competences()->where('nom', 'Représailles sauvages')->exists())->toBeFalse();

    // Niveau 6 : 5 points. Le pouvoir final passe et vide le solde.
    $hero->update(['niveau' => 6]);
    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id, 'competence_id' => idNoeudDeGrille('barbare', 'Représailles sauvages'),
    ])->assertCreated()
        ->assertJsonPath('personnage.points_competence', 0);

    expect($hero->fresh()->pointsDepenses())->toBe(5);
});

it('refuse le pouvoir final sans le rang 3 de SA colonne, même avec les points', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Borin', 1, ['classe' => 'barbare', 'niveau' => 6]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id, 'competence_id' => idNoeudDeGrille('barbare', 'Représailles sauvages'),
    ])->assertStatus(422)
        ->assertJsonPath('errors.competence_id.0', 'Prérequis : Sang qui bout');
});

it('publie la décision d\'achat : la case grisée et le 422 disent la même phrase', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Borin', 1, ['classe' => 'barbare', 'niveau' => 5]);

    foreach (['Coup puissant', 'Frénésie', 'Sang qui bout'] as $rang) {
        acheterUnNoeud($this, $hero, 'barbare', $rang);
    }

    $achats = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.achats'))->keyBy('id');

    // Le pouvoir final : prérequis tenu, mais 1 point seulement → refusé pour le solde.
    expect($achats[idNoeudDeGrille('barbare', 'Représailles sauvages')])->toMatchArray([
        'cout' => 2,
        'achetable' => false,
        'raison' => "Coûte 2 points de compétence : il t'en reste 1.",
    ]);

    // Un rang 1 libre, sur une autre colonne : achetable, sans raison.
    expect($achats[idNoeudDeGrille('barbare', 'Carrure')])
        ->toMatchArray(['cout' => 1, 'achetable' => true, 'raison' => null]);

    // Le pouvoir final d'une colonne SANS son rang 3 : la raison nomme le prérequis.
    expect($achats[idNoeudDeGrille('barbare', 'Increvable')])
        ->toMatchArray(['achetable' => false, 'raison' => 'Prérequis : Colosse']);

    // Un nœud déjà acquis n'est ni achetable ni motivé : son état se lit dans `competences[]`.
    expect($achats[idNoeudDeGrille('barbare', 'Coup puissant')])
        ->toMatchArray(['achetable' => false, 'raison' => null]);
});

it('publie le coût et le rang sur le roster, le guide et le catalogue', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Borin', 1, ['classe' => 'barbare', 'niveau' => 6]);

    acheterUnNoeud($this, $hero, 'barbare', 'Coup puissant');

    $roster = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.competences'));
    expect($roster->firstWhere('nom', 'Coup puissant'))->toMatchArray(['cout' => 1, 'rang' => 1]);

    $guide = collect($this->getJson('/api/guide')->assertOk()->json('competences'));
    expect($guide->firstWhere('nom', 'Représailles sauvages'))->toMatchArray(['cout' => 2, 'rang' => 4])
        ->and($guide->firstWhere('nom', 'Furie'))->toMatchArray(['cout' => 0, 'innee' => true]);
});

it('compte chaque point dépensé dans le score de puissance, et jamais une capacité de carte', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Borin', 1, ['classe' => 'barbare', 'niveau' => 6]);

    // Un berserker a trois capacités de carte : elles ne coûtent rien et ne pèsent pas dans le score.
    $berserker = creerHeros($alice, $groupe, 'Krogar', 2, ['classe' => 'berserker', 'niveau' => 6]);
    expect($berserker->pointsDepenses())->toBe(0)
        ->and($berserker->pointsCompetence())->toBe(5);

    $avant = app(ScorePuissance::class)->calculer($groupe->fresh());

    // Cinq points dépensés : la colonne Furie (3) puis son pouvoir final (2).
    foreach (['Coup puissant', 'Frénésie', 'Sang qui bout', 'Représailles sauvages'] as $rang) {
        acheterUnNoeud($this, $hero, 'barbare', $rang);
    }

    expect($hero->fresh()->pointsDepenses())->toBe(5);

    // Aucun de ces nœuds ne touche aux colonnes de dés ou de PV : le delta du score est exactement le coût.
    $apres = app(ScorePuissance::class)->calculer($groupe->fresh());

    expect($apres - $avant)->toBe(5);
});
