<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Models\Monstre;
use App\Models\SortDread;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
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
 * RETURN OF THE WITCH LORD (F4193) — le catalogue, le thème, et les rencontres finales (chantier 2026-10-23).
 *
 * Les effets (immunités, fuite, coup sans effet, cercueil) sont éprouvés EN JEU dans leurs fichiers propres
 * (`ImmunitesSelectivesTest`, `FuiteAffaibliTest`, `TombeauMonstreTest`). Ici : que le catalogue porte ce que
 * le livret écrit, que le thème s'allume à la fin de la rotation, et que le Seigneur ferme la campagne.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/**
 * Les créatures de la rencontre finale, tirées par le MÊME point de passage que la vraie quête
 * (`DemarreurQuete::acheterMonstres()`, réflexion comme `ThemeMorcarTest`).
 *
 * @return list<Monstre>
 */
function wlAcheter(string $theme, string $typeJalon, int $budget, int $maxSpawns, int $position, int $groupeId): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);
    $structure = GabaritQuete::where('type_jalon', $typeJalon)->firstOrFail()->structure;

    return $methode->invoke($demarreur, $structure, $budget, $maxSpawns, $position, $groupeId, BestiaireGroupe::auto($theme));
}

const WL_LEGION = ['Skulmar', 'Kessandria', 'Garde du Dread', 'Cavalier-esprit'];

it('CATALOGUE — les cinq créatures portent les stats relues sur le livret, leur palier, leur boîte et leur cout', function () {
    // [déplacement, attaque, défense, Body, Mind, palier, cout]
    $attendu = [
        'Cavalier-esprit' => [8, 4, 4, 3, 3, 'sous_boss', 8],
        'Garde du Dread' => [8, 4, 6, 3, 3, 'sous_boss', 10],
        'Skulmar' => [8, 5, 6, 3, 4, 'sous_boss', 10],
        'Kessandria' => [6, 4, 6, 3, 4, 'sous_boss', 10],
        'Seigneur Sorcier' => [10, 5, 6, 4, 5, 'boss', 14],
    ];

    foreach ($attendu as $nom => [$dep, $att, $def, $body, $mind, $palier, $cout]) {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();

        expect([(int) $m->deplacement, (int) $m->attaque, (int) $m->defense, (int) $m->pv_body, (int) $m->pv_mind, $m->tier, (int) $m->cout, $m->boite])
            ->toBe([$dep, $att, $def, $body, $mind, $palier, $cout, 'witch_lord'], $nom);
    }
});

it('CATALOGUE — chaque sort de répertoire existe au catalogue de Dread (aucun sort inventé)', function () {
    foreach (['Skulmar', 'Kessandria', 'Seigneur Sorcier'] as $nom) {
        foreach (Monstre::where('nom_base', $nom)->firstOrFail()->sorts_dread as $sort) {
            expect(SortDread::where('nom', $sort)->exists())->toBeTrue("{$nom} : « {$sort} » absent du catalogue");
        }
    }
});

it('CATALOGUE — Kessandria garde son répertoire ENTIER (Nuée d\'Effroi, sa potion Patinage) malgré le palier sous-boss', function () {
    $k = Monstre::where('nom_base', 'Kessandria')->firstOrFail();

    expect($k->sorts_dread)->toBe(['Éclair de Chaos', 'Tourmente', 'Frayeur', 'Sommeil', 'Nuée d\'Effroi', 'Patinage', 'Fuite'])
        ->and((array) $k->capacites)->toHaveKey('immunite_sorts_sauf')
        ->and(in_array('repertoire_complet', (array) $k->capacites, true))->toBeTrue();
});

it('CATALOGUE — le Seigneur Sorcier n\'a aucune fuite, et sa liste de sources est celle du livret', function () {
    $sei = Monstre::where('nom_base', 'Seigneur Sorcier')->firstOrFail();
    $capacites = (array) $sei->capacites;

    expect(json_encode($capacites))->not->toContain('fuit_si_affaibli')
        ->and($capacites['vulnerable_uniquement_a'])
        ->toBe(['Lame des Esprits', 'Trait de Feu', 'Boule de Feu', 'Dague de jet magique'])
        ->and($sei->sorts_dread)->toBe(['Invocation de morts-vivants', 'Tempête de feu', 'Tourmente', 'Éclair de Chaos', 'Frayeur', 'Commandement']);
});

it('CATALOGUE — les quatre statues d\'orcs NE SONT PAS semées (la forme est testée à part)', function () {
    expect(Monstre::all()->contains(fn (Monstre $m) => str_contains(json_encode($m->capacites), 'invulnerable_casse_arme')))
        ->toBeFalse();
});

it('THÈME — witch_lord ferme la rotation (dernière entrée), n\'est pas désactivé, et a un libellé', function () {
    $boites = DemarreurQuete::BOITES_THEMATIQUES;

    expect(end($boites))->toBe('witch_lord')
        ->and(array_keys(DemarreurQuete::BOITES_INCOMPLETES))->not->toContain('witch_lord')
        ->and($boites)->toHaveCount(8);
});

it('FINALE — le Seigneur Sorcier ferme la campagne du thème, quel que soit le groupe ou la position', function () {
    foreach (range(1, 6) as $groupe) {
        foreach ([1, 4, 9] as $position) {
            expect(wlAcheter('witch_lord', 'boss_final', 40, 8, $position, $groupe)[0]->nom_base)
                ->toBe('Seigneur Sorcier');
        }
    }
});

it('LÉGION — les sous-boss du thème tournent entre Skulmar, Kessandria, la Garde et le Cavalier, sans jamais un autre', function () {
    $vus = [];

    foreach (range(1, 12) as $position) {
        $vus[] = wlAcheter('witch_lord', 'sous_boss', 40, 8, $position, 3)[0]->nom_base;
    }

    expect(array_values(array_unique($vus)))->each->toBeIn(WL_LEGION)
        ->and(count(array_unique($vus)))->toBeGreaterThan(1);
});

it('FINALE — le Seigneur est posé À COUP SÛR, même au budget le plus petit : son cout ne fait que réduire l\'escorte', function () {
    // Budget MESURÉ (`budgetRencontres`, un héros de niveau 1, premier arc, boss final) : 8. Le Seigneur coûte 14 :
    // `acheterMonstres()` pose la finale QUOI QU'IL EN SOIT, puis débite `max(0, restant − cout)`. Au budget 8,
    // l'escorte est donc VIDE — la rencontre n'est que le Seigneur, comme pour les finales existantes (le Seigneur
    // ogre coûte 20, le Scion 22). Au budget 40, il reste 26 pour l'escorte.
    $petit = wlAcheter('witch_lord', 'boss_final', 8, 8, 1, 1);
    $confortable = wlAcheter('witch_lord', 'boss_final', 40, 8, 1, 1);

    expect($petit)->toHaveCount(1)
        ->and($petit[0]->nom_base)->toBe('Seigneur Sorcier')
        ->and($confortable[0]->nom_base)->toBe('Seigneur Sorcier')
        ->and(count($confortable))->toBeGreaterThan(1);
});
