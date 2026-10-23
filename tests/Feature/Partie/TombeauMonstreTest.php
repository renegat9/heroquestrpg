<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Mobilier;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\JournalCombat;
use App\Partie\MoteurMobilier;
use App\Partie\ResolveurTour;
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

/*
 * LE CERCUEIL (*Return of the Witch Lord*, p. 4-5 : « may contain an undead creature and/or a treasure. They may
 * also have traps on them »). Une issue `monstre` dans la fouille du Tombeau : un mort-vivant de base en sort,
 * posé AU CONTACT ORTHOGONAL du fouilleur, annoncé. Le test de route prouve la pose ; le tirage et la table
 * sont lus à leur point de passage.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/**
 * Une salle 16×9 tout en sol, un héros en (`$hx`, `$hy`), des murs optionnels.
 *
 * @param  list<array{x: int, y: int}>  $murs
 * @return array{alice: mixed, groupe: Groupe, quete: Quete, albrecht: Personnage, etat: EtatPersonnageQuete}
 */
function tombeauScene(array $murs = [], int $hx = 3, int $hy = 4): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — le cercueil',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $cases = array_fill(0, 9, array_fill(0, 16, 's'));
    foreach ($murs as $m) {
        $cases[$m['y']][$m['x']] = 'm';
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 16, 'hauteur' => 9,
                'theme' => 'generique', 'mediane_x' => 8, 'mediane_y' => 4]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => [],
            'spawn_heros' => [['x' => $hx, 'y' => $hy]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);
    $etat = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $albrecht->id, 'position_x' => $hx, 'position_y' => $hy,
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'albrecht' => $albrecht, 'etat' => $etat];
}

/**
 * La fouille d'un cercueil, par son point de passage réel (`ResolveurTour::appliquerButin()`, réflexion comme
 * `ThemeMorcarTest`) : la carte tirée est donnée, le tirage du mort-vivant ayant été fait en amont.
 *
 * @param  array<string, mixed>  $carte
 * @return array<string, mixed>
 */
function tombeauFouille(array $ctx, array $carte): array
{
    $methode = new ReflectionMethod(ResolveurTour::class, 'appliquerButin');
    $methode->setAccessible(true);

    return $methode->invoke(
        app(ResolveurTour::class), $carte, ['type' => 'fouille_mobilier'],
        $ctx['groupe'], $ctx['quete'], $ctx['albrecht'], $ctx['etat']->fresh(), false,
    );
}

it('la fouille du TOMBEAU porte l\'issue « monstre », avec les trois morts-vivants de base à égalité', function () {
    $fouille = (array) data_get(Mobilier::where('nom', 'Tombeau')->firstOrFail()->effet, 'fouille', []);
    $issue = collect($fouille)->firstWhere('issue', 'monstre');

    expect($issue)->not->toBeNull()
        ->and($issue['monstres'])->toBe(['Squelette', 'Zombie', 'Momie'])
        ->and(collect($fouille)->firstWhere('issue', 'piege')['piege'])->toBe('Aiguille empoisonnée');
});

it('le tirage du cercueil est À ÉGALITÉ : sur soixante tirages, les trois morts-vivants sortent', function () {
    $methode = new ReflectionMethod(MoteurMobilier::class, 'carteDepuisEntree');
    $methode->setAccessible(true);
    $entree = ['issue' => 'monstre', 'poids' => 2, 'monstres' => ['Squelette', 'Zombie', 'Momie']];

    $tires = collect(range(1, 60))
        ->map(fn () => $methode->invoke(app(MoteurMobilier::class), $entree, 1)['monstre'] ?? null)
        ->unique()->sort()->values()->all();

    expect($tires)->toBe(['Momie', 'Squelette', 'Zombie']);
});

it('EN JEU — le mort-vivant sort au CONTACT ORTHOGONAL du fouilleur, actif et révélé', function () {
    $ctx = tombeauScene();

    $payload = tombeauFouille($ctx, ['issue' => 'monstre', 'monstre' => 'Momie']);
    $cree = $ctx['quete']->instancesMonstres()->whereKey($payload['monstre']['instance_id'])->firstOrFail();

    expect($payload['issue'])->toBe('monstre')
        ->and($payload['monstre']['nom'])->toBe('Momie')
        ->and(abs((int) $cree->position_x - 3) + abs((int) $cree->position_y - 4))->toBe(1)
        ->and($cree->etat)->toBe('actif')
        ->and((bool) $cree->revele)->toBeTrue();
});

it('EN JEU — les quatre voisines orthogonales murées : repli sur la case libre la plus proche, jamais en diagonale seule', function () {
    // Le héros en (3,4) : (2,4), (4,4), (3,3), (3,5) sont des murs. Seules les DIAGONALES restent libres — la
    // règle du projet ne les compte pas pour un cercueil : la créature se pose à distance 2, sans contact.
    $murs = [['x' => 2, 'y' => 4], ['x' => 4, 'y' => 4], ['x' => 3, 'y' => 3], ['x' => 3, 'y' => 5]];
    $ctx = tombeauScene($murs);

    $payload = tombeauFouille($ctx, ['issue' => 'monstre', 'monstre' => 'Zombie']);
    $cree = $ctx['quete']->instancesMonstres()->whereKey($payload['monstre']['instance_id'])->firstOrFail();

    // Une DIAGONALE est aussi à distance de Manhattan 2 : on exige qu'elle ne soit pas une diagonale.
    $dx = abs((int) $cree->position_x - 3);
    $dy = abs((int) $cree->position_y - 4);

    expect($payload['issue'])->toBe('monstre')
        ->and($dx + $dy)->toBe(2)
        ->and($dx === $dy)->toBeFalse();
});

it('EN JEU — un monstre qui n\'est pas un mort-vivant de BASE ne sort pas : la fouille le dit (monstre_indisponible)', function () {
    $ctx = tombeauScene();

    $payload = tombeauFouille($ctx, ['issue' => 'monstre', 'monstre' => 'Seigneur Sorcier']);

    expect($payload['issue'])->toBe('rien')
        ->and($payload['monstre_indisponible'] ?? false)->toBeTrue()
        ->and(isset($payload['monstre']))->toBeFalse();
});

it('le cercueil s\'annonce : « sort du tombeau », et nomme le fouilleur', function () {
    $lignes = (new JournalCombat)->depuisEvenements([[[
        'type' => 'fouille_mobilier', 'issue' => 'monstre', 'monstre' => ['nom' => 'Momie'],
    ], 'Albrecht']]);
    $textes = implode(' ¦ ', array_column($lignes, 'texte'));

    expect($textes)->toContain('Momie sort du tombeau que fouille Albrecht');
});
