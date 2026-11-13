<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\HallArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;

/**
 * MODE ARÈNE, lot T5 — les COFFRES DU TOURNOI (F9528 p. 13, plan p. 15) : deux coffres, fouillés au contact ou en
 * diagonale, la table 2d6, puis le coffre quitte le plateau.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    connecterJoueur();
});

/** Un barbare d'arène posé EN DIAGONALE du coffre nord ; renvoie l'option de fouille de son menu. */
function devantLeCoffre(): array
{
    $heros = Personnage::findOrFail(test()->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $doublure = $arenes->engager($arene, $heros);
    $quete = app(DemarreurArene::class)->demarrer($arene, [(int) Monstre::where('nom_base', 'Gobelin')->value('id')]);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $doublure->id)->firstOrFail();

    [$cx, $cy] = app(HallArene::class)->caseDeLaSalle(...HallArene::COFFRES[0]);
    $etat->update(['position_x' => $cx + 1, 'position_y' => $cy + 1]); // en diagonale
    foreach ($arene->personnages()->pluck('joueur_id')->unique() as $j) {
        Cache::forget(App\Jobs\GenererMenu::cleMenu($arene->id, (int) $j));
    }

    // LA LETTRE (2026-11-12) : le héros seul manœuvre d'abord, à l'ouverture du round — il passe, son activation reste à jouer.
    test()->getJson("/api/groupes/{$arene->identifiant}/menu")->assertOk();
    test()->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => 'attendre'])->assertSuccessful();

    $option = collect(test()->getJson("/api/groupes/{$arene->identifiant}/menu")->json('menu.options'))->firstWhere('type', 'fouille_mobilier');

    return [$arene->refresh(), $quete, $doublure, $option];
}

it('pose deux coffres, fouillables en diagonale', function () {
    [, $quete, , $option] = devantLeCoffre();

    $coffres = collect($quete->fresh()->carte->grille['mobilier'])->where('tresor_tournoi', true);
    expect($coffres)->toHaveCount(2)
        ->and($option)->not->toBeNull()
        ->and($option['parametres']['nom'])->toBe('Coffre');
});

it('2d6 = 7 : une hache de bataille en os dans le sac de la doublure, et le coffre quitte le plateau', function () {
    [$arene, $quete, $doublure, $option] = devantLeCoffre();
    desFiges([3, 4]);

    $r = $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => $option['id']])->assertSuccessful()->json('resultat');

    expect($r['type'])->toBe('tresor_tournoi')
        ->and($r['total'])->toBe(7)
        ->and($doublure->inventaire()->whereHas('objet', fn ($q) => $q->where('nom', 'Hache de bataille en os'))->exists())->toBeTrue()
        ->and(collect($quete->fresh()->carte->grille['mobilier'])->where('tresor_tournoi', true))->toHaveCount(1);
});

it('2d6 = 2 : le piège explosif blesse le fouilleur de 3', function () {
    [$arene, , $doublure, $option] = devantLeCoffre();
    $pv = (int) $doublure->pv_body;
    desFiges([1, 1]);

    $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => $option['id']])->assertSuccessful();

    expect((int) $doublure->fresh()->pv_body)->toBe(max(0, $pv - 3));
});

it('2d6 = 12 : 300 pièces d\'or, à la bourse du combat — jamais à un héros', function () {
    [$arene, , $doublure, $option] = devantLeCoffre();
    desFiges([6, 6]);

    $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => $option['id']])->assertSuccessful();

    expect((int) $arene->fresh()->or)->toBe(300)
        ->and((int) Personnage::findOrFail($doublure->doublure_de)->or)->toBe(0);
});
