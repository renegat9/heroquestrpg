<?php

declare(strict_types=1);

use App\Models\Sort;
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
 * Durée des conditions de MONSTRE (2026-08-24).
 *
 * `habillage.conditions` ne portait qu'un booléen : `retirerConditionMonstre()`
 * n'était câblée que sur `endormi` (une attaque réveille) et `saute_tour` /
 * `enfume` (auto-consommées au tour même du monstre, dans
 * `ResolveurTour::jouerMonstre()`). `terrifie`, `ralenti` et `paralyse`
 * n'avaient donc AUCUNE sortie : un monstre paralysé par Flamme hypnotique ne
 * rejouait plus jamais de la quête, un monstre ralenti restait ralenti pour
 * toujours — et le commentaire de `jouerMonstre()` affirmait à tort que
 * `decrementerDurees()` s'en chargeait (elle ne touche que
 * `personnage_conditions`, jamais `instances_monstres`).
 *
 * `poserConditionMonstre()` porte désormais une durée optionnelle (un entier
 * de tours, `null` = sans compteur = `true`, le comportement historique) et
 * `decrementerDureesMonstres()` la décompte en fin de round, au même endroit
 * que son pendant héros — voir MoteurSorts pour la source retenue et son
 * repli documenté (`dureeConditionMonstre()`).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

it('un monstre PARALYSÉ ne joue pas son tour, puis REJOUE une fois la durée écoulée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $instance = $ctx['instance'];
    $sorts = app(MoteurSorts::class);

    // Durée 1 : un seul round de test suffit à prouver le décompte — la vraie
    // valeur posée par Flamme hypnotique (catalogue « Paralysé », 3 tours)
    // est éprouvée séparément plus bas, sur `dureeConditionMonstre()`.
    $sorts->poserConditionMonstre($instance, MoteurSorts::MONSTRE_PARALYSE, 1);

    // Réserve de dés « boucliers blancs » : le round 2 fait bien attaquer le
    // monstre (redevenu valide), et aucun crâne ne doit sortir pour rester
    // déterministe — même parti pris que ResolutionTourTest.
    desFiges(array_fill(0, 60, 4));

    $positionAvant = [$instance->position_x, $instance->position_y];

    // Round 1 : le héros termine son tour → phase des monstres → paralysé,
    // le monstre ne joue pas.
    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    expect($reponse->json('resultat.tour_monstres.actions.0.type'))->toBe('monstre_paralyse');

    $instance->refresh();
    expect([$instance->position_x, $instance->position_y])->toBe($positionAvant);

    // ⚠ C'est le cœur du bug corrigé : sans `decrementerDureesMonstres()`
    // appelée en fin de round, cette condition restait vraie pour le reste de
    // la quête.
    expect($sorts->monstreA($instance, MoteurSorts::MONSTRE_PARALYSE))
        ->toBeFalse('la durée écoulée doit avoir retiré la condition en fin de round');

    // Round 2 : le héros termine encore son tour → le monstre, plus paralysé,
    // rejoue enfin — resté adjacent depuis le départ, il attaque.
    $reponse2 = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    expect($reponse2->json('resultat.tour_monstres.actions.0.type'))->toBe('attaque_monstre');
});

it('RALENTI expire et le monstre retrouve ses dés d\'attaque', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $quete = $ctx['quete'];
    $instance = $ctx['instance'];
    $sorts = app(MoteurSorts::class);

    $desBase = $instance->attaqueEffective(); // Gobelin : 2 dés, sans condition.

    $sorts->poserConditionMonstre($instance, MoteurSorts::MONSTRE_RALENTI, 1);
    expect($instance->fresh()->attaqueEffective())->toBe($desBase - 1);

    // Décompte direct (pas besoin de dérouler tout un round HTTP ici : c'est
    // exactement ce que `ouvrirNouveauTour()` appelle en fin de round).
    $sorts->decrementerDureesMonstres($quete);

    expect($sorts->monstreA($instance->fresh(), MoteurSorts::MONSTRE_RALENTI))->toBeFalse()
        ->and($instance->fresh()->attaqueEffective())->toBe($desBase);
});

it('une condition posée SANS durée (true) n\'est jamais retirée par le décompte', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $quete = $ctx['quete'];
    $instance = $ctx['instance'];
    $sorts = app(MoteurSorts::class);

    // Endormi : posée sans 3e argument, exactement comme `ResolveurTour` le
    // fait réellement (Sommeil n'a pas de compteur — sa fin est une attaque
    // subie, cf. `retirerConditionMonstre()` appelée depuis `frapper()`).
    $sorts->poserConditionMonstre($instance, MoteurSorts::MONSTRE_ENDORMI);

    // Cinq décomptes de fin de round, comme si la quête continuait sans que
    // personne n'attaque le monstre : la condition doit survivre à tous.
    for ($i = 0; $i < 5; $i++) {
        $sorts->decrementerDureesMonstres($quete);
    }

    expect($sorts->monstreA($instance->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeTrue();
});

it('SAUTE_TOUR reste auto-consommé à son propre tour, sans attendre le décompte de fin de round', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $instance = $ctx['instance'];
    $sorts = app(MoteurSorts::class);

    // Posée SANS durée, comme Tempête le fait réellement : sa fin est le
    // DÉCLENCHEUR « le monstre vient d'y jouer », retiré dans `jouerMonstre()`
    // lui-même — jamais par `decrementerDureesMonstres()`.
    $sorts->poserConditionMonstre($instance, MoteurSorts::MONSTRE_SAUTE_TOUR);

    desFiges(array_fill(0, 30, 4));

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    expect($reponse->json('resultat.tour_monstres.actions.0.type'))->toBe('monstre_saute_tour')
        ->and($sorts->monstreA($instance->fresh(), MoteurSorts::MONSTRE_SAUTE_TOUR))
        ->toBeFalse('doit tomber dans jouerMonstre() lui-même, avant même ouvrirNouveauTour()');
});

it('ENFUME reste auto-consommé à son propre tour, sans attendre le décompte de fin de round', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $instance = $ctx['instance'];
    $sorts = app(MoteurSorts::class);

    $sorts->poserConditionMonstre($instance, MoteurSorts::MONSTRE_ENFUME);

    desFiges(array_fill(0, 30, 4));

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    expect($reponse->json('resultat.tour_monstres.actions.0.type'))->toBe('monstre_enfume')
        ->and($sorts->monstreA($instance->fresh(), MoteurSorts::MONSTRE_ENFUME))
        ->toBeFalse();
});

it('lit la durée d\'une condition de monstre sur le catalogue via le sort, et se replie sur null faute de source', function () {
    $sorts = app(MoteurSorts::class);

    // Ralentissement → condition « Ralenti » (ConditionSeeder, duree_defaut 3)
    // : exploitable, c'est la source d'autorité.
    $ralentissement = Sort::where('nom', 'Ralentissement')->firstOrFail();
    expect($sorts->dureeConditionMonstre($ralentissement))->toBe(3);

    // Flamme hypnotique → « Paralysé » (duree_defaut 3 également) : c'est ce
    // qui corrige le bug d'origine — un monstre paralysé rejoue après 3 tours.
    $flammeHypnotique = Sort::where('nom', 'Flamme hypnotique')->firstOrFail();
    expect($sorts->dureeConditionMonstre($flammeHypnotique))->toBe(3);

    // Terreur → « Apeuré » (duree_defaut 0, fin = jet_mind_reussi, jamais
    // câblée) : aucune source exploitable, ni au catalogue ni sur le sort
    // lui-même (pas de `effet.duree` entier) → repli documenté sur `null`,
    // la même dette que porte déjà « Apeuré » côté héros.
    $terreur = Sort::where('nom', 'Terreur')->firstOrFail();
    expect($sorts->dureeConditionMonstre($terreur))->toBeNull();
});
