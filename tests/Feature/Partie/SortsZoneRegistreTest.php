<?php

declare(strict_types=1);

use App\Engine\MotsClesSort;
use App\Engine\TypeDegat;
use App\Models\Sort;
use Database\Seeders\SortSeeder;

/*
 * LE REGISTRE DES ZONES DE SORTS DE HÉROS, dans les DEUX SENS (`docs/regles/vocabulaires-effets.md`).
 *
 * Même garde-fou que `SortsDreadSourcesTest` côté MJ : rien d'écrit dans une donnée sans être déclaré, rien de déclaré
 * sans lecteur qui existe ET qui nomme la clé dans son propre fichier. Une zone de sort qui promet un effet que le
 * moteur n'applique pas est exactement la règle « promise au joueur et jamais tenue » que le projet chasse.
 */

beforeEach(function () {
    $this->seed([SortSeeder::class]);
});

it('chaque zone SEMÉE sur un sort de héros est déclarée, et n\'est pas une dette nommée', function () {
    $zones = collect(Sort::all())->filter(fn (Sort $s) => data_get($s->effet, 'zone') !== null)
        ->mapWithKeys(fn (Sort $s) => [$s->nom => (string) $s->effet['zone']]);

    expect($zones)->not->toBeEmpty();

    foreach ($zones as $nom => $zone) {
        expect(array_key_exists($zone, MotsClesSort::ZONES))->toBeTrue("{$nom} : zone « {$zone} » hors vocabulaire MotsClesSort::ZONES.");
        expect(MotsClesSort::estNonImplemente($zone))->toBeFalse("{$nom} : zone « {$zone} » non implémentée, et pourtant semée.");
    }
});

it('chaque zone DÉCLARÉE a un lecteur qui existe ET nomme la zone dans son fichier — ou une dette nommée', function () {
    foreach (MotsClesSort::ZONES as $zone => $meta) {
        expect($meta['libelle'] ?? '')->not->toBeEmpty("{$zone} : libellé manquant (le menu le lit).");

        if ($meta['lecteur'] === null) {
            expect(MotsClesSort::estNonImplemente($zone))->toBeTrue("{$zone} : sans lecteur, elle doit être une dette NOMMÉE.");

            continue;
        }

        [$classe, $methode] = explode('::', $meta['lecteur']);

        expect(method_exists($classe, $methode))->toBeTrue("{$zone} : {$meta['lecteur']} introuvable.");
        expect(str_contains((string) file_get_contents((string) (new ReflectionClass($classe))->getFileName()), "'{$zone}'"))
            ->toBeTrue("{$zone} : {$classe} est déclaré lecteur mais ne nomme jamais la zone « '{$zone}' ».");
    }
});

it('chaque clé de zone déclarée dans MECANIQUES a un lecteur qui existe ET nomme la clé', function () {
    foreach (MotsClesSort::MECANIQUES as $cle => $meta) {
        [$classe, $methode] = explode('::', $meta['lecteur']);

        expect(method_exists($classe, $methode))->toBeTrue("{$cle} : {$meta['lecteur']} introuvable.");
        expect(str_contains((string) file_get_contents((string) (new ReflectionClass($classe))->getFileName()), $cle))
            ->toBeTrue("{$cle} : {$classe} est déclaré lecteur mais ne nomme jamais la clé.");
        expect($meta['libelle'] ?? '')->not->toBeEmpty("{$cle} : libellé manquant.");
    }
});

it('chaque clé d\'effet d\'un sort de héros qui relève de la zone est déclarée dans MECANIQUES', function () {
    $relevant = ['zone', 'degats_fixes', 'des_degats', 'defense_applicable', 'hors_couloir', 'type_degat'];

    $semees = collect(Sort::all())
        ->flatMap(fn (Sort $s) => array_keys((array) $s->effet))
        ->intersect($relevant)->unique()->values()->all();

    expect(array_values(array_diff($semees, array_keys(MotsClesSort::MECANIQUES))))->toBe([]);
});

it('chaque CIBLE d\'un sort de zone est déclarée ; une zone de DÉGÂTS vise des monstres de zone, jamais un monstre unique', function () {
    foreach (Sort::all() as $sort) {
        $cible = (string) data_get($sort->effet, 'cible', '');

        if ($cible !== '') {
            expect(in_array($cible, MotsClesSort::CIBLES, true))->toBeTrue("{$sort->nom} : cible « {$cible} » hors vocabulaire.");
        }

        if ($sort->type === 'degats' && data_get($sort->effet, 'zone') !== null) {
            expect(in_array($cible, [MotsClesSort::CIBLE_MONSTRES_ZONE, MotsClesSort::CIBLE_FIGURES_ZONE], true))
                ->toBeTrue("{$sort->nom} : zone de dégâts sans cible de zone (« {$cible} »).");
            expect(data_get($sort->effet, 'degats_fixes') !== null || data_get($sort->effet, 'des_degats') !== null)
                ->toBeTrue("{$sort->nom} : zone de dégâts sans montant (ni degats_fixes, ni des_degats).");
        }
    }
});

it('chaque nature de dégât d\'un sort de héros est une nature CONNUE', function () {
    foreach (Sort::all() as $sort) {
        if (($type = data_get($sort->effet, 'type_degat')) !== null) {
            expect(TypeDegat::estConnu((string) $type))->toBeTrue("{$sort->nom} : nature « {$type} » inconnue.");
        }
    }
});

it('ne déclare AUCUNE zone de héros sans lecteur : le carré (lot 3, 2026-11-13) n\'est plus une dette', function () {
    // Le carré a son lecteur (`MoteurSorts::figuresDeZone()`, la forme `'carre_2x2'` de sa `match`) : la dette du
    // lot 2 est soldée. Une zone qui revient sans lecteur tombera ici, pas en partie.
    foreach (MotsClesSort::ZONES as $zone => $meta) {
        expect($meta['lecteur'])->not->toBeNull("{$zone} : zone de héros sans lecteur.");
    }

    expect(MotsClesSort::NON_IMPLEMENTES)->not->toHaveKey(MotsClesSort::ZONE_CARRE_2X2)
        ->and(MotsClesSort::estNonImplemente('monstres_zone'))->toBeFalse('Morsure du Froid l\'emploie, avec son lecteur')
        ->and(MotsClesSort::ZONES[MotsClesSort::ZONE_CARRE_2X2]['lecteur'])->toBe('App\Partie\MoteurSorts::figuresDeZone');
});
