<?php

declare(strict_types=1);

use App\Partie\JournalCombat;
use App\Partie\SceneDeTable;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Ce que la partie réelle du 2026-11-06 (campagne Trio, quête 186) n'a pas vu à l'écran. Les payloads sont ceux que le
 * moteur a journalisés ce soir-là, rejoués tels quels : le défaut était dans la mise en forme, pas dans la résolution.
 */

beforeEach(function () {
    Http::fake();
    $this->seed([ClasseHerosSeeder::class, SortSeeder::class, ObjetSeeder::class, SortDreadSeeder::class]);

    $groupe = creerGroupe();
    $joueur = connecterJoueur();
    $this->sylvan = creerHeros($joueur, $groupe, 'Sylvan', 1, ['classe' => 'elfe']);
    $this->nain = creerHeros($joueur, $groupe, 'petite barbe', 2, ['classe' => 'nain']);
});

/** @return array<string, mixed> */
function canaliserLEffroi(int $de, int $adjacents, int $degats): array
{
    return [
        'type' => 'sort_dread',
        'sort' => "Canaliser l'Effroi",
        'lanceur' => ['instance_id' => 1060, 'nom' => "Zélote de l'Ombre"],
        'resultats' => [[
            'cible' => ['personnage_id' => test()->sylvan->id, 'nom' => 'Sylvan'],
            'degats' => $degats, 'pv_body_apres' => 4, 'cible_tombee' => false,
            'de' => $de, 'lanceurs_adjacents' => $adjacents, 'total' => $de + $adjacents,
        ]],
    ];
}

/** @return array<string, mixed> */
function scenesTrio(array $action, $acteur): array
{
    return app(SceneDeTable::class)->depuisResultat($action, $acteur);
}

it('montre le dé de Canaliser l\'Effroi au fil, lancé par le sorcier, avec le palier atteint', function () {
    $lignes = app(JournalCombat::class)->depuisResultat(canaliserLEffroi(6, 0, 2), 'Panoramix');
    $avecDe = collect($lignes)->first(fn ($l) => isset($l['des']));

    expect(collect($lignes)->pluck('texte')->all())->toContain("Canaliser l'Effroi frappe Sylvan (−2 PV)")
        ->and($avecDe['des']['atk'])->toBe([6])
        ->and($avecDe['des']['attaquant'])->toBe("Zélote de l'Ombre")
        ->and($avecDe['des']['somme_atk'])->toBe('total 6 → 2 PV')
        ->and($avecDe['des'])->not->toHaveKey('modificateurs');
});

it('compte les lanceurs adjacents comme un modificateur du dé, jamais caché dans le total', function () {
    $des = app(JournalCombat::class)->desJetUnilateral(canaliserLEffroi(3, 1, 1)['resultats'][0], 'Sylvan');

    expect($des['atk'])->toBe([3])
        ->and($des['somme_atk'])->toBe('total 4 → 1 PV')
        ->and($des['modificateurs'])->toBe([['source' => 'lanceur adjacent', 'valeur' => 1, 'sur' => 'attaque']]);
});

it('ouvre une fenêtre à la table pour Canaliser l\'Effroi — y compris quand le dé ne fait rien', function () {
    $touche = scenesTrio(canaliserLEffroi(6, 0, 2), $this->sylvan);
    $rate = scenesTrio(canaliserLEffroi(1, 0, 0), $this->sylvan);

    expect($touche)->toHaveCount(1)
        ->and($touche[0]['jet']['atk'])->toBe([6])
        ->and($touche[0]['jet']['attaquant'])->toBe("Zélote de l'Ombre")
        ->and($touche[0]['issue'])->toBe(['ton' => 'subit', 'libelle' => '−2 PV'])
        ->and($rate)->toHaveCount(1)
        ->and($rate[0]['jet']['somme_atk'])->toBe('total 1 → aucun dégât')
        ->and($rate[0]['issue'])->toBe(['ton' => 'pare', 'libelle' => "Sylvan n'y perd rien"]);
});

/** @return array<string, mixed> */
function parcheminDuNain(bool $reussi): array
{
    return [
        'type' => 'parchemin', 'option_id' => 'lire_parchemin', 'libelle' => 'Lire un parchemin',
        'sort' => ['id' => App\Models\Sort::where('nom', 'Luciole')->value('id'), 'nom' => 'Luciole', 'element' => 'druide', 'type' => 'utilitaire'],
        'lanceur_de_sorts' => false,
        'jet' => [
            'attribut' => 'mind', 'difficulte' => 2, 'des_lances' => 2,
            'succes' => $reussi ? 2 : 0, 'issue' => $reussi ? 'reussite' : 'echec',
            'faces' => $reussi ? ['crane', 'crane'] : ['bouclier_blanc', 'bouclier_blanc'],
        ],
        'consomme' => true,
        'gaspille' => ! $reussi,
    ];
}

it('dit qu\'un parchemin raté est PERDU, dés compris, au fil comme à la table — jamais « Luciole opère »', function () {
    $lignes = app(JournalCombat::class)->depuisResultat(parcheminDuNain(false), 'petite barbe');
    $scenes = scenesTrio(parcheminDuNain(false), $this->nain);

    expect($lignes)->toHaveCount(1)
        ->and($lignes[0]['texte'])->toBe('petite barbe tente de lire Luciole — jet de Mind raté : le parchemin est perdu')
        ->and($lignes[0]['ton'])->toBe('echec')
        ->and($lignes[0]['des']['def'])->toBe(['bouclier_blanc', 'bouclier_blanc'])
        ->and($scenes)->toHaveCount(1)
        ->and($scenes[0]['titre'])->toBe('petite barbe tente de lire Luciole')
        ->and($scenes[0]['jet']['def'])->toBe(['bouclier_blanc', 'bouclier_blanc'])
        ->and($scenes[0]['issue'])->toBe(['ton' => 'echec', 'libelle' => 'Jet raté — le parchemin est perdu']);
});

it('montre le jet de Mind réussi d\'un non-lanceur avant le sort qu\'il déchiffre', function () {
    $lignes = app(JournalCombat::class)->depuisResultat(parcheminDuNain(true), 'petite barbe');
    $scenes = scenesTrio(parcheminDuNain(true), $this->nain);

    expect($lignes[0]['texte'])->toBe('petite barbe déchiffre le parchemin (jet de Mind réussi)')
        ->and($lignes[0]['des']['def'])->toBe(['crane', 'crane'])
        ->and(collect($lignes)->pluck('texte')->implode(' | '))->toContain('Luciole')
        ->and($scenes[0]['jet']['def'])->toBe(['crane', 'crane']);
});
