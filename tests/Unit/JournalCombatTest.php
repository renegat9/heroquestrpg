<?php

declare(strict_types=1);

use App\Partie\JournalCombat;

function lignes(array $resultat, string $acteur = 'Borin'): array
{
    return (new JournalCombat())->depuisResultat($resultat, $acteur);
}

describe('JournalCombat — restitution mécanique (aucun LLM)', function () {

    it('décrit une attaque de héros qui touche', function () {
        $l = lignes(['type' => 'attaque', 'degats' => 2, 'cible' => ['nom' => 'Gargouille']]);
        expect($l)->toHaveCount(1)
            ->and($l[0]['ton'])->toBe('degats')
            ->and($l[0]['texte'])->toBe('Borin touche Gargouille (−2 PV)');
    });

    it('marque une cible vaincue par le héros', function () {
        $l = lignes(['type' => 'attaque', 'degats' => 3, 'cible_vaincue' => true, 'cible' => ['nom' => 'Gobelin']]);
        expect($l[0]['ton'])->toBe('mort')
            ->and($l[0]['texte'])->toBe('Borin terrasse Gobelin !');
    });

    it('décrit une attaque parée (0 dégât)', function () {
        $l = lignes(['type' => 'attaque', 'degats' => 0, 'cible' => ['nom' => 'Gargouille']]);
        expect($l[0]['ton'])->toBe('pare');
    });

    it('annexe le détail des dés quand le payload les porte (C1)', function () {
        // Attaque : 3 crânes touchés, 1 bouclier paré → 2 dégâts.
        $l = lignes(['type' => 'attaque', 'degats' => 2, 'touches' => 3, 'boucliers' => 1,
            'cible' => ['nom' => 'Gargouille']]);
        expect($l[0]['texte'])->toBe('Borin touche Gargouille (−2 PV) · 3 crânes / 1 bouclier');

        // Parade complète (0 dégât) : le détail des dés reste visible.
        $pare = lignes(['type' => 'attaque', 'degats' => 0, 'touches' => 1, 'boucliers' => 1,
            'cible' => ['nom' => 'Gargouille']]);
        expect($pare[0]['ton'])->toBe('pare')
            ->and($pare[0]['texte'])->toContain('· 1 crâne / 1 bouclier');

        // Sort de dégâts : même détail.
        $sort = lignes(['type' => 'sort', 'degats' => 5, 'touches' => 5, 'boucliers' => 0,
            'sort' => ['nom' => 'Génie'], 'cible' => ['nom' => 'Momie']]);
        expect($sort[0]['texte'])->toContain('· 5 crânes / 0 bouclier');
    });

    it('restitue le tour des monstres avec les dégâts subis et la chute', function () {
        $resultat = [
            'type' => 'attaque',
            'degats' => 1,
            'cible' => ['nom' => 'Gargouille'],
            'tour_monstres' => ['actions' => [
                ['type' => 'attaque_monstre', 'monstre' => 'Gargouille', 'degats' => 2,
                    'cible' => ['nom' => 'Borin'], 'cible_tombee' => true],
                ['type' => 'deplacement_monstre', 'monstre' => 'Gobelin'], // ignoré (bruit)
            ]],
        ];
        $l = lignes($resultat);
        // 1 ligne héros + 2 lignes monstre (touche + chute) ; le déplacement est muet.
        expect($l)->toHaveCount(3)
            ->and($l[1]['ton'])->toBe('subit')
            ->and($l[1]['texte'])->toBe('Gargouille touche Borin (−2 PV)')
            ->and($l[2]['ton'])->toBe('chute')
            ->and($l[2]['texte'])->toBe("Borin s'effondre !");
    });

    it('restitue une fouille de zone réussie (auparavant muette)', function () {
        $l = lignes([
            'type' => 'jet', 'option_id' => 'fouiller', 'succes' => true,
            'pieges_reveles' => [['x' => 1, 'y' => 2]], 'portes_revelees' => [],
        ]);
        expect($l[0]['ton'])->toBe('succes')
            ->and($l[0]['texte'])->toContain('1 piège');
    });

    it('restitue une fouille de zone infructueuse', function () {
        $l = lignes(['type' => 'jet', 'option_id' => 'fouiller', 'succes' => false]);
        expect($l[0]['ton'])->toBe('echec');
    });

    it('décrit un sort offensif qui blesse', function () {
        $l = lignes([
            'type' => 'sort', 'sort' => ['nom' => 'Génie'],
            'degats' => 1, 'cible' => ['nom' => 'Gargouille'],
        ], 'Sylvara');
        expect($l[0]['ton'])->toBe('degats')
            ->and($l[0]['texte'])->toContain('Génie');
    });

    it('reste muet sur un simple déplacement', function () {
        expect(lignes(['type' => 'deplacement']))->toBe([]);
    });

    it('agrège action du héros + tour des alliés + tour des monstres dans l\'ordre', function () {
        $resultat = [
            'type' => 'attaque', 'degats' => 2, 'cible' => ['nom' => 'Gargouille'],
            'tour_allies' => ['actions' => [
                ['type' => 'attaque_allie', 'allie' => 'Archer', 'degats' => 1, 'cible' => ['nom' => 'Gobelin']],
            ]],
            'tour_monstres' => ['actions' => [
                ['type' => 'attaque_monstre', 'monstre' => 'Gobelin', 'degats' => 0, 'cible' => ['nom' => 'Borin']],
            ]],
        ];
        $l = lignes($resultat);
        expect($l)->toHaveCount(3)
            ->and($l[0]['texte'])->toContain('Borin touche Gargouille')
            ->and($l[1]['texte'])->toContain('Archer touche Gobelin')
            ->and($l[2]['ton'])->toBe('pare');
    });
});
