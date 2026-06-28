<?php

declare(strict_types=1);

use App\Partie\Grille;

/**
 * Grandes figurines / monstres multi-cases (Phase 2, 3.9, doc 14). Les helpers
 * d'emprise de Grille étendent l'occupation, l'adjacence, la ligne de vue et la
 * validation de tenue aux figurines couvrant plusieurs cases. Contrainte clé :
 * pour une emprise 1×1 (cas par défaut de tous les monstres), chaque helper
 * équivaut exactement à son homologue mono-case.
 *
 * @param  list<string>  $lignes  'm' = mur, 's' = sol, 'p' = porte (y=0 en haut)
 */
function grilleEmprise(array $lignes): Grille
{
    return new Grille(array_map(str_split(...), $lignes));
}

describe('Grille — emprise des grandes figurines (3.9)', function () {
    it('couvre les l×h cases ancrées au coin haut-gauche', function () {
        $grille = grilleEmprise(['sss', 'sss', 'sss']);

        // Emprise 1×2 (ogre) ancrée en (1,0) : deux cases verticales.
        expect($grille->cellulesEmprise(1, 0, 1, 2))
            ->toBe([['x' => 1, 'y' => 0], ['x' => 1, 'y' => 1]]);

        // Emprise 2×2 : quatre cases.
        expect($grille->cellulesEmprise(0, 0, 2, 2))
            ->toBe([
                ['x' => 0, 'y' => 0], ['x' => 1, 'y' => 0],
                ['x' => 0, 'y' => 1], ['x' => 1, 'y' => 1],
            ]);
    });

    it('réduit l emprise 1×1 à une seule case (cas par défaut)', function () {
        $grille = grilleEmprise(['sss', 'sss', 'sss']);

        expect($grille->cellulesEmprise(2, 1, 1, 1))->toBe([['x' => 2, 'y' => 1]]);
    });

    it('occupe TOUTE l emprise : le pathfinding contourne les deux cases', function () {
        // Couloir vertical étroit (colonne 1) ; un ogre 1×2 en (1,1)-(1,2) le bouche.
        $grille = grilleEmprise([
            'msm',
            'msm',
            'msm',
            'msm',
            'msm',
        ]);

        $grille->occuper($grille->cellulesEmprise(1, 1, 1, 2));

        // De (1,0) à (1,4) : les deux cases occupées (1,1) et (1,2) coupent le couloir.
        expect($grille->chemin(1, 0, 1, 4))->toBeNull();
    });

    it('détecte l adjacence orthogonale à n importe quelle case de l emprise', function () {
        $grille = grilleEmprise(['sss', 'sss', 'sss']);

        // Ogre 1×2 ancré en (1,0) → couvre (1,0) et (1,1).
        // À gauche de la case basse (0,1) : adjacent.
        expect($grille->adjacenteAEmprise(1, 0, 1, 2, 0, 1))->toBeTrue();
        // À droite de la case haute (2,0) : adjacent.
        expect($grille->adjacenteAEmprise(1, 0, 1, 2, 2, 0))->toBeTrue();
        // En diagonale (0,2) : NON adjacent (orthogonal seulement).
        expect($grille->adjacenteAEmprise(1, 0, 1, 2, 0, 2))->toBeFalse();
        // Deux cases plus loin (0,0)… non. (0,0) jouxte (1,0) ? Manhattan = 1 → oui.
        expect($grille->adjacenteAEmprise(1, 0, 1, 2, 0, 0))->toBeTrue();
    });

    it('rend l adjacence 1×1 identique à sontAdjacentes', function () {
        $grille = grilleEmprise(['sss', 'sss', 'sss']);

        foreach ([[0, 0], [1, 1], [2, 2], [0, 2]] as [$tx, $ty]) {
            expect($grille->adjacenteAEmprise(1, 1, 1, 1, $tx, $ty))
                ->toBe($grille->sontAdjacentes(1, 1, $tx, $ty));
        }
    });

    it('voit l emprise si AU MOINS une de ses cases est visible', function () {
        // Mur isolé en (2,0) ; emprise ancrée en (2,0)..(2,1) — la case haute est
        // un mur, mais la case basse (2,1) reste visible depuis (0,1).
        $grille = grilleEmprise([
            'ssm',
            'sss',
        ]);

        // La case haute (2,0) seule n est pas visible (c est un mur, traité comme
        // bloquant son propre tracé via ?? 'm' ; de toute façon la basse suffit).
        expect($grille->ligneDeVueEmprise(0, 1, 2, 0, 1, 2))->toBeTrue();
    });

    it('ne voit pas l emprise si toutes ses cases sont masquées', function () {
        // Rang de murs en y=1 : tout ce qui est sous est invisible depuis le haut.
        $grille = grilleEmprise([
            'sss',
            'mmm',
            'sss',
            'sss',
        ]);

        // Emprise 1×2 ancrée en (1,2) (cases (1,2),(1,3)) vue depuis (1,0) : bloquée.
        expect($grille->ligneDeVueEmprise(1, 0, 1, 2, 1, 2))->toBeFalse();
    });

    it('valide la tenue de l emprise : libre seulement si TOUTES les cases le sont', function () {
        $grille = grilleEmprise([
            'sss',
            'ssm', // mur en (2,1)
            'sss',
        ]);

        // Emprise 1×2 ancrée en (0,0) : (0,0)+(0,1) toutes deux sol → libre.
        expect($grille->empriseLibre(0, 0, 1, 2))->toBeTrue();
        // Emprise 1×2 ancrée en (2,0) : (2,0) sol mais (2,1) mur → NON libre.
        expect($grille->empriseLibre(2, 0, 1, 2))->toBeFalse();

        // Une case occupée invalide aussi la tenue.
        $grille->occuper([['x' => 0, 'y' => 1]]);
        expect($grille->empriseLibre(0, 0, 1, 2))->toBeFalse();
    });

    it('rend empriseLibre 1×1 identique à estTraversable', function () {
        $grille = grilleEmprise(['ssm', 'sss']);
        $grille->occuper([['x' => 0, 'y' => 1]]);

        foreach ([[0, 0], [2, 0], [1, 1], [0, 1]] as [$x, $y]) {
            expect($grille->empriseLibre($x, $y, 1, 1))->toBe($grille->estTraversable($x, $y));
        }
    });
});
