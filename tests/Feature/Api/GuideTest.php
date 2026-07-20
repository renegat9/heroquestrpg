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

it('trie le bestiaire par palier puis coût', function () {
    $monstres = $this->getJson('/api/guide')->assertOk()->json('monstres');

    $rang = ['base' => 0, 'sous_boss' => 1, 'boss' => 2];
    $precedent = -1;
    foreach ($monstres as $m) {
        expect($rang[$m['tier']])->toBeGreaterThanOrEqual($precedent);
        $precedent = $rang[$m['tier']];
    }
});
