<?php

declare(strict_types=1);

use App\Models\Competence;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\CapacitesInnees;
use App\Partie\MoteurSorts;
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
 * Capacités INNÉES — les capacités de carte des 8 classes d'extension.
 *
 * Acquises d'emblée et gratuitement (au plateau, la carte vient avec la
 * figurine). Ce fichier teste leur LECTURE et leur comptage « once per quest » ;
 * les effets en jeu sont testés avec la mécanique qu'ils empruntent.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

it('attache les capacités de carte à la création, sans coûter de point', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $rogue = creerHeros($alice, $groupe, 'Silfen', 1, ['classe' => 'rogue']);

    $innees = $rogue->competences()->where('innee', true)->pluck('nom')->sort()->values()->all();

    expect($innees)->toBe(['Ambidextrie', 'Frappe opportuniste', 'Mobilité de combat']);

    // ⚠ Elles ne consomment AUCUN point : le héros de niveau 1 garde toute sa
    // progression. Les compter comme achetées reviendrait à naître endetté.
    expect((int) $rogue->niveau)->toBe(1);
});

it('ne donne aucune capacité innée aux 4 classes historiques', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barbare = creerHeros($alice, $groupe, 'Krogar', 1, ['classe' => 'barbare']);

    // Aucune carte officielle n'en donne aux quatre de base — c'est l'écart
    // d'équilibrage assumé du lot, pas un oubli.
    expect($barbare->competences()->where('innee', true)->count())->toBe(0);
});

it('compte les capacités « une fois par quête », et seulement celles-là', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];

    $capacites = app(CapacitesInnees::class);

    // On lui donne les deux formes : un passif permanent et une « once per quest ».
    $permanent = Competence::where('classe', 'rogue')->where('nom', 'Mobilité de combat')->firstOrFail();
    $unique = Competence::where('classe', 'chevalier')->where('nom', 'Inébranlable')->firstOrFail();
    $heros->competences()->syncWithoutDetaching([$permanent->id, $unique->id]);

    expect($capacites->disponible($heros, $etat, 'plancher_pv'))->toBeTrue();

    $capacites->consommer($heros, $etat, 'plancher_pv');

    expect($capacites->disponible($heros, $etat->fresh(), 'plancher_pv'))->toBeFalse();

    // Le passif, lui, ne se consomme pas : `consommer` doit le laisser intact.
    $capacites->consommer($heros, $etat->fresh(), 'franchit_figures');

    expect($capacites->disponible($heros, $etat->fresh(), 'franchit_figures'))->toBeTrue();
});

it('ferme les capacités du Berserker tant qu\'il n\'est PAS assez blessé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];

    $frenesie = Competence::where('classe', 'berserker')->where('nom', 'Frénésie sanguinaire')->firstOrFail();
    $heros->competences()->syncWithoutDetaching([$frenesie->id]);

    $capacites = app(CapacitesInnees::class);

    // « Cannot be used unless you have 3 or fewer Body Points » : c'est un
    // PLAFOND, la capacité s'ouvre quand on est blessé.
    $heros->update(['pv_body' => 8]);
    expect($capacites->disponible($heros->fresh(), $etat, 'attaque_balayee'))->toBeFalse();

    $heros->update(['pv_body' => 3]);
    expect($capacites->disponible($heros->fresh(), $etat, 'attaque_balayee'))->toBeTrue();
});

it('LÉGER SUR SES PIEDS donne un dé de défense — et le retire sous le métal', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barde = creerHeros($alice, $groupe, 'Lyr', 1, ['classe' => 'barde']);

    $sorts = app(MoteurSorts::class);
    $base = (int) $barde->des_defense;

    // Sans métal ni bouclier : le bonus s'applique.
    expect($sorts->desDefenseHeros($barde))->toBe($base + 1);

    // Un bouclier au bras, et il tombe. ⚠ Ce n'est pas une interdiction : le
    // Barde PEUT le porter, il y perd simplement son dé.
    $bouclier = Objet::where('nom', 'Bouclier')->firstOrFail();
    Inventaire::create([
        'personnage_id' => $barde->id, 'objet_id' => $bouclier->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);

    expect($sorts->desDefenseHeros($barde->fresh()))->toBe($base);
});
