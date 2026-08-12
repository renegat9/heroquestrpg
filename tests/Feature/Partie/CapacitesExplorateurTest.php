<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use App\Partie\MoteurPieges;
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
 * Les trois capacités de carte de l'EXPLORATEUR (© 2024 Hasbro), en jeu.
 *
 * Toutes tournées vers le DECK DE TRÉSOR et les pièges — c'est la seule classe
 * dont les capacités ne touchent pas au combat. Deux d'entre elles sont « once
 * per TURN », la fenêtre de comptage la plus courte du jeu : le compteur vit
 * dans `capacites_tour`, remis à zéro avec les créneaux en fin de round.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/**
 * Quête à deux héros (le second empêche la phase des monstres de s'enchaîner),
 * le premier étant de la classe demandée.
 *
 * @return array{0: Groupe, 1: Personnage, 2: Quete, 3: EtatPersonnageQuete}
 */
function demarrerAvecClasse(string $classe): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => $classe]);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $hero->id)->firstOrFail();

    return [$groupe, $hero, $quete, $etat];
}

it('CHASSEUR DE TRÉSOR ajoute 25 pièces à toute carte qui rapporte de l\'or', function () {
    [$groupe, , $quete] = demarrerAvecClasse('explorateur');
    $avant = (int) $groupe->fresh()->or;

    empilerCarteFouille($quete, ['issue' => 'tresor', 'or' => 15]);

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json('resultat');

    // 15 sur la carte + 25 trouvés en fouillant mieux que les autres.
    expect($resultat['or'])->toBe(40)
        ->and($resultat['bonus_or_tresor'])->toBe(25)
        // L'or va au pot COMMUN — chez nous tout l'or y va, l'Explorateur
        // enrichit le groupe, pas sa bourse.
        ->and((int) $groupe->fresh()->or)->toBe($avant + 40);
});

it('ne donne le bonus d\'or à personne d\'autre', function () {
    [$groupe, , $quete] = demarrerAvecClasse('barbare');

    empilerCarteFouille($quete, ['issue' => 'tresor', 'or' => 15]);

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['or'])->toBe(15)
        ->and($resultat)->not->toHaveKey('bonus_or_tresor');
});

it('SIXIÈME SENS écarte la carte de danger et en tire une autre — une fois par tour', function () {
    [, $hero, $quete, $etat] = demarrerAvecClasse('explorateur');

    // Un piège au sommet, un trésor juste dessous : la capacité doit faire
    // passer le premier et rendre le second.
    empilerCarteFouille($quete, ['issue' => 'tresor', 'or' => 15]);
    empilerCarteFouille($quete, ['issue' => 'piege']);

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('tresor')
        ->and($resultat['carte_ecartee'])->toBe('piege');

    // Dépensée pour le TOUR : la seconde fouille du même tour subit le piège.
    expect(app(App\Partie\CapacitesInnees::class)
        ->disponible($hero->fresh(), $etat->fresh(), 'repiocher_carte_piege'))->toBeFalse();

    // …et le compteur se vide au tour suivant, contrairement aux « once per
    // quest » qui meurent avec la quête.
    $etat->fresh()->update(['capacites_tour' => null]);

    expect(app(App\Partie\CapacitesInnees::class)
        ->disponible($hero->fresh(), $etat->fresh(), 'repiocher_carte_piege'))->toBeTrue();
});

it('SIXIÈME SENS écarte aussi le monstre errant, l\'autre carte qui mord', function () {
    [, , $quete] = demarrerAvecClasse('explorateur');

    empilerCarteFouille($quete, ['issue' => 'tresor', 'or' => 15]);
    empilerCarteFouille($quete, ['issue' => 'errant']);

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['carte_ecartee'])->toBe('errant')
        ->and($resultat['issue'])->toBe('tresor');
});

it('laisse passer le piège pour les autres classes', function () {
    [, , $quete] = demarrerAvecClasse('barbare');

    empilerCarteFouille($quete, ['issue' => 'tresor', 'or' => 15]);
    empilerCarteFouille($quete, ['issue' => 'piege']);

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('piege')
        ->and($resultat)->not->toHaveKey('carte_ecartee');
});

it('SENS DU PIÈGE avertit sans RIEN révéler, et arrête la course', function () {
    [$groupe, $hero, $quete, $etat] = demarrerAvecClasse('explorateur');

    // Un piège caché sur une case voisine de la destination : le héros doit
    // être averti EN ARRIVANT à côté, sans que la tuile soit posée.
    $grille = $quete->carte->grille;
    $depart = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];

    // On cherche deux cases libres alignées : la première où marcher, la
    // seconde pour y cacher le piège (adjacente à la première).
    $cible = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $pas = ['x' => $depart['x'] + $dx, 'y' => $depart['y'] + $dy];
        $voisin = ['x' => $pas['x'] + $dy, 'y' => $pas['y'] + $dx]; // perpendiculaire

        if (caseQueteLibre($quete, $pas['x'], $pas['y']) && caseQueteLibre($quete, $voisin['x'], $voisin['y'])) {
            $cible = [$pas, $voisin];
            break;
        }
    }
    expect($cible)->not->toBeNull('Pas de couple de cases libres pour le scénario.');
    [$pas, $voisin] = $cible;

    $grille['pieges'] = [[
        'x' => $voisin['x'], 'y' => $voisin['y'],
        'piege_id' => App\Models\Piege::query()->value('id'),
        'etat' => MoteurPieges::ETAT_CACHE,
    ]];
    $quete->carte->update(['grille' => $grille]);

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => $pas['x'], 'y' => $pas['y']],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['pieges_pressentis'])->toHaveCount(1)
        ->and($resultat['pieges_pressentis'][0]['x'])->toBe($voisin['x']);

    // ⚠ Toujours CACHÉ : « Zargon does not place trap tiles on the board. The
    // traps are still considered concealed. » Rien n'est révélé aux autres.
    expect($quete->carte->fresh()->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_CACHE);

    // Et la capacité est dépensée pour le tour.
    expect(app(App\Partie\CapacitesInnees::class)
        ->disponible($hero->fresh(), $etat->fresh(), 'alerte_pieges_adjacents'))->toBeFalse();
});
