<?php

declare(strict_types=1);

use App\Engine\MotsClesEquipement;
use App\Models\Objet;
use App\Models\SortDread;
use App\Partie\Aleatoire\PrngLineaire;
use App\Partie\Fouille\DeckFouille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortDreadSeeder;

/*
 * Le Talisman du Savoir MAUDIT — le REGISTRE, dans les deux sens (chantier RESTES-TALISMAN, 2026-10-29).
 *
 * Trois choses que la suite par mécanisme ne voit pas : (1) la ligne catalogue est DISTINCTE du Talisman du Savoir,
 * qui reste intact ; (2) le talisman maudit ne sort au coffre que sous le thème Prophecy of Telor (boîte
 * `prophecy_telor`, filtrée par `DeckFouille::choisirArtefact()`) ; (3) chaque sort qu'il porte existe au catalogue
 * de Dread, et chaque paramètre de fardeau est dans le vocabulaire fermé.
 */

beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ObjetSeeder::class, SortDreadSeeder::class]);
});

it('le Talisman du Savoir ORDINAIRE reste intact : gain de Mind seul, aucune malédiction', function () {
    $ordinaire = Objet::where('nom', 'Talisman du Savoir')->firstOrFail();

    expect($ordinaire->effet)->toBe(['bonus_pv_mind_max' => 1])
        ->and($ordinaire->boite)->toBeNull();
});

it('le talisman maudit est une LIGNE DISTINCTE, unique, et d\'une seule boîte : Prophecy of Telor', function () {
    $maudit = Objet::where('nom', 'Talisman du Savoir maudit')->firstOrFail();

    expect($maudit->id)->not->toBe(Objet::where('nom', 'Talisman du Savoir')->firstOrFail()->id)
        ->and($maudit->rarete)->toBe('unique')
        ->and($maudit->emplacement)->toBe('talisman')
        ->and($maudit->boite)->toBe('prophecy_telor')
        ->and($maudit->effet[MotsClesEquipement::INAMOVIBLE])->toBeTrue();
});

it('REGISTRE : chaque sort porté par un objet existe au catalogue de Dread', function () {
    $noms = Objet::query()->get()
        ->flatMap(fn (Objet $o) => (array) (($o->effet ?? [])[MotsClesEquipement::SORTS_DREAD_PORTEUR] ?? []))
        ->unique()
        ->values();

    expect($noms->all())->not->toBeEmpty()
        ->and($noms->diff(SortDread::pluck('nom'))->values()->all())->toBe([]);
});

it('REGISTRE : les paramètres du fardeau sont dans le vocabulaire fermé, et chacun est lu', function () {
    $cles = Objet::query()->get()
        ->flatMap(fn (Objet $o) => array_keys((array) (($o->effet ?? [])[MotsClesEquipement::FARDEAU_DEBUT_DE_TOUR] ?? [])))
        ->unique()
        ->values();

    expect($cles->all())->not->toBeEmpty()
        ->and(array_diff($cles->all(), MotsClesEquipement::FARDEAU_PARAMETRES))->toBe([])
        ->and(array_diff(MotsClesEquipement::FARDEAU_PARAMETRES, $cles->all()))->toBe([]);
});

it('le talisman maudit ne sort au coffre QUE sous le thème Prophecy of Telor (filtre de boîte, `DeckFouille`)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $choisir = new ReflectionMethod(DeckFouille::class, 'choisirArtefact');

    $tirages = function (string $theme) use ($groupe, $choisir): array {
        $groupe->update(['theme_bestiaire' => $theme]);
        $deck = app(DeckFouille::class);
        $noms = [];

        foreach (range(1, 400) as $graine) {
            $id = $choisir->invoke($deck, $groupe->fresh(), new PrngLineaire($graine));

            if ($id !== null) {
                $noms[] = Objet::find($id)?->nom;
            }
        }

        return $noms;
    };

    expect($tirages('jungles_delthrak'))->not->toContain('Talisman du Savoir maudit')
        ->and($tirages('prophecy_telor'))->toContain('Talisman du Savoir maudit');
});
