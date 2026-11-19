<?php

declare(strict_types=1);

use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LES SALLES SPÉCIALES (lot K6, R11 — René, 2026-11-19 : « quand on assigne une salle spéciale sur le plateau, elle remplace
 * les salles du plateau en dessous pour la quête »). Plateau de `DocumentExemple`, INVENTÉ : la salle `reserve` (14..16,
 * 2..4) ; une place publique en (13, 2), 3×3, l'entame sans la couvrir.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagneAPlace(): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['salles_speciales'] = [['id' => 'place', 'x' => 13, 'y' => 2, 'l' => 3, 'h' => 3]];

    return $campagne;
}

it('la salle spéciale est une salle ouverte ; la salle du plateau qu\'elle entame est absente — son reste est de la roche', function () {
    $campagne = campagneAPlace();
    expect(app(ValidationCampagne::class)->valider($campagne))->toBe([]);

    $carte = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0])['carte'];
    $ids = array_column($carte['salles'], 'id');

    expect($ids)->toContain('place')->not->toContain('reserve')
        ->and($carte['cases'][3][13])->toBe('s')   // sous la place : du sol de salle
        ->and($carte['cases'][3][16])->toBe('m')   // le reste de la réserve : de la roche
        ->and(\App\Partie\Salles::indexDe($carte['salles'], 15, 3))->toBe(array_search('place', $ids, true));

    // Un mur au bord ouest de la place, face au couloir en (12, 3).
    expect(collect($carte['murs'])->contains(fn ($m) => $m['x'] === 12 && $m['y'] === 3 && $m['cote'] === 'e'))->toBeTrue();
});

it('refuse d\'ouvrir une salle recouverte, et deux salles spéciales qui se chevauchent', function () {
    $ouverte = campagneAPlace();
    $ouverte['quetes'][0]['salles_ouvertes'][] = 'reserve';
    $refus = collect(app(ValidationCampagne::class)->valider($ouverte))->map(fn ($r) => $r['chemin'].' '.$r['code'])->all();
    expect($refus)->toContain('quetes.0.salles_speciales.0 '.ValidationCampagne::SALLES_SE_CHEVAUCHENT);

    $deux = campagneAPlace();
    $deux['quetes'][0]['salles_speciales'][] = ['id' => 'tour', 'x' => 15, 'y' => 4, 'l' => 2, 'h' => 2];
    $refus = collect(app(ValidationCampagne::class)->valider($deux))->map(fn ($r) => $r['chemin'].' '.$r['code'])->all();
    expect($refus)->toContain('quetes.0.salles_speciales.1 '.ValidationCampagne::SALLES_SE_CHEVAUCHENT);
});
