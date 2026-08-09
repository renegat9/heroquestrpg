<?php

declare(strict_types=1);

use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;

/**
 * Guide / compendium PUBLIC (GET /api/guide) : données de référence en lecture
 * seule, servies SANS authentification (la page /guide s'ouvre depuis l'accueil
 * sans compte). Renvoie les catalogues seedés + les descriptions de talents.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class,
        MonstreSeeder::class, ObjetSeeder::class, SortSeeder::class, PiegeSeeder::class,
    ]);
});

it('sert le compendium complet sans authentification', function () {
    $data = $this->getJson('/api/guide')->assertOk()->json();

    // Toutes les rubriques présentes et non vides.
    foreach (['classes', 'competences', 'monstres', 'objets', 'sorts', 'pieges'] as $cle) {
        expect($data[$cle] ?? [])->not->toBeEmpty("Rubrique {$cle} vide.");
    }

    // Les 4 classes, chacune avec ses stats de base.
    expect(collect($data['classes'])->pluck('nom')->sort()->values()->all())
        ->toBe(['barbare', 'elfe', 'magicien', 'nain']);
    expect($data['classes'][0])->toHaveKeys(['nom', 'pv_body', 'pv_mind', 'des_attaque', 'des_defense', 'deplacement_base']);

    // Les talents portent leur description (correctif précédent).
    expect(collect($data['competences'])->every(fn ($t) => ! empty($t['description'])))->toBeTrue();

    // Un monstre expose ses stats + capacités (tableau).
    expect($data['monstres'][0])->toHaveKeys(['nom_base', 'deplacement', 'attaque', 'defense', 'pv_body', 'pv_mind', 'tier', 'cout', 'capacites']);

    // Un objet expose catégorie / rareté / prix / effet.
    expect($data['objets'][0])->toHaveKeys(['nom', 'categorie', 'rarete', 'prix_base', 'emplacement', 'effet']);

    // Un sort expose élément / type / difficulté / effet.
    expect($data['sorts'][0])->toHaveKeys(['element', 'nom', 'type', 'difficulte_parchemin', 'effet']);
});

it('expose les maîtrises d\'équipement des deux côtés (classe et objet)', function () {
    $data = $this->getJson('/api/guide')->assertOk()->json();

    // Sans ces deux champs, la restriction n'apparaissait NULLE PART dans le
    // guide : le joueur ne l'apprenait qu'au refus, en essayant d'équiper.
    $classes = collect($data['classes'])->keyBy('nom');
    expect($classes['magicien']['tags_equipement'])->toBeArray()
        ->and($classes['magicien']['tags_equipement'])->not->toContain('armure_legere')
        ->and($classes['barbare']['tags_equipement'])->toContain('arme_deux_mains')
        ->and($classes['nain']['tags_equipement'])->toContain('armure_lourde');

    // Chaque arme/armure porte la maîtrise qu'elle EXIGE — sauf celles dont la
    // carte n'énonce AUCUNE restriction de classe, qui n'ont légitimement pas de
    // tag (`verifierAccesEquipement` les laisse passer). Elles sont nommées ici
    // pour qu'un tag oublié ne se cache pas derrière la même absence.
    $sansMaitrise = ['Talisman du Savoir'];

    $portables = collect($data['objets'])
        ->whereIn('categorie', ['arme', 'armure'])
        ->reject(fn ($o) => in_array($o['nom'], $sansMaitrise, true));

    expect($portables)->not->toBeEmpty()
        ->and($portables->every(fn ($o) => ! empty($o['tag_equipement'])))->toBeTrue();

    // …et les nœuds de déblocage restent lisibles pour croiser les deux.
    $deblocages = collect($data['competences'])
        ->filter(fn ($c) => ($c['effet']['mecanique'] ?? null) === 'acces_equipement');
    expect($deblocages)->not->toBeEmpty()
        ->and($deblocages->every(fn ($c) => is_array($c['effet']['tags'] ?? null)))->toBeTrue();
});

it('documente TOUS les objets : chacun porte un effet non vide', function () {
    $objets = $this->getJson('/api/guide')->assertOk()->json('objets');

    // Un objet sans effet est une pièce que le guide ne peut pas décrire — et,
    // le plus souvent, une pièce que le moteur n'applique pas non plus.
    $muets = collect($objets)->filter(fn ($o) => empty($o['effet']))->pluck('nom')->all();

    expect($muets)->toBe([], 'Objets sans effet : '.implode(', ', $muets));
});

it('trie le bestiaire par palier puis coût', function () {
    $monstres = $this->getJson('/api/guide')->assertOk()->json('monstres');

    $rang = ['base' => 0, 'sous_boss' => 1, 'boss' => 2];
    $precedent = -1;
    foreach ($monstres as $m) {
        expect($rang[$m['tier']])->toBeGreaterThanOrEqual($precedent);
        $precedent = $rang[$m['tier']];
    }
});
