<?php

declare(strict_types=1);

use App\Models\Mobilier;
use App\Models\Objet;
use App\Partie\MoteurMobilier;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;

/*
 * LE THÈME DANS LES TABLES DE MEUBLE (2026-10-29). Une pièce BOÎTÉE ne sort du butin que sous sa boîte : même prédicat que
 * l'artefact du coffre (`DeckFouille::choisirArtefact()`) et que l'étal (`PhaseMarche`). Le vivier des meubles l'avait oublié.
 *
 * Deux portes : un OBJET (`objets.boite`) par son vivier de catégorie ou par son nom, un PIÈGE (`pieges.boite`) dans la table.
 * Conséquence voulue : les potions boîtées de Delthrak et de Morcar ne tombent plus des meubles hors de leur thème.
 */

beforeEach(function () {
    $this->seed([ObjetSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/** Un type de meuble en mémoire, avec la table de fouille voulue (le tirage ne lit que `effet.fouille`). */
function tableDeFouille(array $fouille): Mobilier
{
    $type = new Mobilier();
    $type->effet = ['fouille' => $fouille];

    return $type;
}

it('un OBJET boîté hors thème n\'est jamais tiré d\'une table à catégorie (1200 tirages)', function () {
    $boitee = Objet::where('nom', 'Potion de sang de serpent')->firstOrFail();
    expect($boitee->boite)->toBe('jungles_delthrak');

    $table = tableDeFouille([['issue' => 'objet', 'poids' => 1, 'categories' => ['consommable']]]);
    $moteur = app(MoteurMobilier::class);

    $tires = collect(range(1, 1200))
        ->map(fn () => $moteur->tirerButin($table, 1, [], ['spirit_queen'])['objet_id'] ?? null)
        ->filter()
        ->all();

    expect($tires)->not->toBeEmpty()
        ->and(in_array($boitee->id, $tires, true))->toBeFalse();
});

it('sous son thème, un objet boîté de catégorie peut sortir (1200 tirages)', function () {
    $boitee = Objet::where('nom', 'Potion de sang de serpent')->firstOrFail();

    $table = tableDeFouille([['issue' => 'objet', 'poids' => 1, 'categories' => ['consommable']]]);
    $moteur = app(MoteurMobilier::class);

    $tires = collect(range(1, 1200))
        ->map(fn () => $moteur->tirerButin($table, 1, [], ['jungles_delthrak'])['objet_id'] ?? null)
        ->filter()
        ->all();

    expect(in_array($boitee->id, $tires, true))->toBeTrue();
});

it('une pièce NOMMÉE boîtée hors thème devient « rien » ; sous son thème, elle sort (déterministe)', function () {
    $boitee = Objet::where('nom', 'Potion de sang de serpent')->firstOrFail();
    $table = tableDeFouille([['issue' => 'objet', 'poids' => 1, 'objet' => 'Potion de sang de serpent']]);
    $moteur = app(MoteurMobilier::class);

    // Hors thème : l'entrée sort du tableau, il ne reste rien à tirer.
    expect($moteur->tirerButin($table, 1, [], ['spirit_queen']))->toBe(['issue' => 'rien', 'sans_table' => true]);

    // Sous son thème : la pièce nommée sort, telle quelle.
    expect($moteur->tirerButin($table, 1, [], ['jungles_delthrak']))
        ->toMatchArray(['issue' => 'objet', 'objet_id' => $boitee->id]);
});

it('un PIÈGE boîté hors thème sort de la table avant le tirage : les autres entrées restent seules (déterministe)', function () {
    $table = tableDeFouille([
        ['issue' => 'piege', 'poids' => 1, 'piege' => 'Piège de Boule de Flammes'],
        ['issue' => 'tresor', 'poids' => 3, 'or' => [25, 60]],
    ]);
    $moteur = app(MoteurMobilier::class);

    $issues = collect(range(1, 300))
        ->map(fn () => $moteur->tirerButin($table, 1, [], ['jungles_delthrak'])['issue'])
        ->unique()->values()->all();

    // Le piège est retiré AVANT le tirage : le trésor (poids 3) reste la seule issue, à 100 %.
    expect($issues)->toBe(['tresor']);
});

it('sous son thème, le même piège entre dans le tirage avec son poids', function () {
    $table = tableDeFouille([
        ['issue' => 'piege', 'poids' => 1, 'piege' => 'Piège de Boule de Flammes'],
        ['issue' => 'rien', 'poids' => 1],
    ]);
    $moteur = app(MoteurMobilier::class);

    $issues = collect(range(1, 300))
        ->map(fn () => $moteur->tirerButin($table, 1, [], ['spirit_queen'])['issue'])
        ->unique()->sort()->values()->all();

    expect($issues)->toBe(['piege', 'rien']);
});

it('un piège nommé dont la ligne n\'existe pas reste admis : échec ouvert, comme une pièce sans boîte', function () {
    $table = tableDeFouille([['issue' => 'piege', 'poids' => 1, 'piege' => 'Piège fantôme']]);

    expect(app(MoteurMobilier::class)->tirerButin($table, 1, [], []))
        ->toMatchArray(['issue' => 'piege', 'piege' => 'Piège fantôme']);
});

it('sans thème passé, un piège boîté n\'entre dans aucune table : la valeur par défaut est l\'échec fermé', function () {
    $coffre = Mobilier::where('nom', 'Coffre')->firstOrFail();
    $moteur = app(MoteurMobilier::class);

    $pieges = collect(range(1, 300))
        ->filter(fn () => ($moteur->tirerButin($coffre)['piege'] ?? null) !== null)
        ->count();

    expect($pieges)->toBe(0);
});
