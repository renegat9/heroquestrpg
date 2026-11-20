<?php

declare(strict_types=1);

use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\Salles;
use App\Partie\ZoneFouille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LA SALLE EN L (René, 2026-11-19 : « respecte le plan du board »). Le plateau officiel a une salle qui n'est pas un
 * rectangle (au sud-est : x 17-20 sur y 10-12, plus x 18-20 sur y 13). Une salle d'un plateau construit porte donc des
 * `parties` : des rectangles de plus, sans mur entre eux, lus par `Salles` — le seul test « case dans une salle ».
 * Plan inventé (`DocumentExemple`) : la `galerie` (x 7-10, y 2-5) reçoit une partie x 11-12, y 4-5.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagneEnL(array $parties = [['x' => 11, 'y' => 4, 'l' => 2, 'h' => 2]]): array
{
    $doc = DocumentExemple::campagne();
    $doc['plateau']['salles'][1]['parties'] = $parties;

    return $doc;
}

function codesDeRefus(array $doc): array
{
    return array_column(app(ValidationCampagne::class)->valider($doc), 'code');
}

it('admet une salle en L ; refuse une partie détachée, ou qui chevauche une autre salle', function () {
    expect(codesDeRefus(campagneEnL()))->toBe([])
        ->and(codesDeRefus(campagneEnL([['x' => 16, 'y' => 10, 'l' => 1, 'h' => 1]])))->toContain(ValidationCampagne::PARTIE_DETACHEE)
        ->and(codesDeRefus(campagneEnL([['x' => 11, 'y' => 2, 'l' => 4, 'h' => 1]])))->toContain(ValidationCampagne::SALLES_SE_CHEVAUCHENT);
});

it('la partie en L est la salle : un monstre peut s\'y tenir, un bloc non (ce n\'est pas un couloir)', function () {
    $doc = campagneEnL();
    $doc['quetes'][0]['monstres'][] = ['slug' => 'gobelin', 'x' => 12, 'y' => 5];
    expect(codesDeRefus($doc))->toBe([]);

    $doc['quetes'][0]['blocs'][] = ['x' => 11, 'y' => 4];
    expect(codesDeRefus($doc))->toContain(ValidationCampagne::BLOC_HORS_COULOIR);
});

it('la carte produite : aucun mur entre les rectangles d\'une salle, la partie appartient à la salle partout', function () {
    $doc = campagneEnL();
    $carte = app(CarteConstruite::class)->produire($doc, $doc['quetes'][0])['carte'];
    $salles = $carte['salles'];
    $galerie = collect($salles)->search(fn (array $s) => $s['id'] === 'galerie');

    expect($salles[$galerie]['parties'])->toBe([['x' => 11, 'y' => 4, 'largeur' => 2, 'hauteur' => 2]])
        ->and(Salles::indexDe($salles, 12, 5))->toBe($galerie)
        ->and(Salles::cases($salles[$galerie]))->toHaveCount(16 + 4)
        ->and(ZoneFouille::de($carte, 9, 3)->contient(12, 5))->toBeTrue();

    $murs = collect($carte['murs'])->map(fn (array $m) => "{$m['x']},{$m['y']},{$m['cote']}")->all();
    // Entre (10,4) et (11,4) : la même salle, pas de mur. Entre (12,4) et (12,3), couloir au nord : un mur.
    expect($murs)->not->toContain('10,4,e')->not->toContain('10,5,e')
        ->toContain('12,3,s')->toContain('12,4,e');

    // Une salle sans partie n'en publie pas : rien ne change pour un plateau rectangulaire.
    expect(collect($salles)->firstWhere('id', 'entree'))->not->toHaveKey('parties');
});
