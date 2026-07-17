<?php

declare(strict_types=1);

use App\Partie\Grille;

/**
 * Construit une grille à partir de lignes lisibles ('m' = mur, 's' = sol,
 * 'p' = porte) où la première ligne est y = 0.
 *
 * @param  list<string>  $lignes
 */
function grilleDepuis(array $lignes): Grille
{
    return new Grille(array_map(str_split(...), $lignes));
}

describe('Grille — ligne de vue (prérequis Phase 2, doc 14)', function () {
    it('voit une case le long d une ligne horizontale dégagée', function () {
        $grille = grilleDepuis([
            'sssss',
        ]);

        expect($grille->ligneDeVue(0, 0, 4, 0))->toBeTrue();
    });

    it('est coupée par un mur situé entre les deux extrémités', function () {
        $grille = grilleDepuis([
            'ssmss',
        ]);

        // Le mur en (2,0) bloque la vue de (0,0) vers (4,0).
        expect($grille->ligneDeVue(0, 0, 4, 0))->toBeFalse();
    });

    it('voit une diagonale dégagée', function () {
        $grille = grilleDepuis([
            'ssss',
            'ssss',
            'ssss',
            'ssss',
        ]);

        expect($grille->ligneDeVue(0, 0, 3, 3))->toBeTrue();
    });

    it('est coupée par un mur sur la diagonale', function () {
        $grille = grilleDepuis([
            'ssss',
            'smss',
            'ssss',
            'ssss',
        ]);

        // Le mur en (1,1) est traversé par la diagonale (0,0)->(3,3).
        expect($grille->ligneDeVue(0, 0, 3, 3))->toBeFalse();
    });

    it('considère qu une case se voit elle-même', function () {
        $grille = grilleDepuis([
            'sss',
            'sms',
            'sss',
        ]);

        expect($grille->ligneDeVue(1, 1, 1, 1))->toBeTrue()
            ->and($grille->ligneDeVue(2, 0, 2, 0))->toBeTrue();
    });

    it('rend deux cases adjacentes toujours mutuellement visibles', function () {
        $grille = grilleDepuis([
            'mmm',
            'ssm',
            'mmm',
        ]);

        // (0,1) et (1,1) sont adjacentes : aucune case intermédiaire.
        expect($grille->ligneDeVue(0, 1, 1, 1))->toBeTrue()
            ->and($grille->ligneDeVue(1, 1, 0, 1))->toBeTrue();
    });

    it('ne voit pas une case derrière un mur', function () {
        $grille = grilleDepuis([
            'sssss',
            'sssss',
            'mmmmm',
            'sssss',
        ]);

        // Le rang de murs (y=2) coupe toute vue entre le haut et le bas.
        expect($grille->ligneDeVue(2, 0, 2, 3))->toBeFalse();
    });

    it('laisse passer la vue à travers sol et porte (porte transparente à ce stade)', function () {
        $grille = grilleDepuis([
            'spsps',
        ]);

        // Les portes en (1,0) et (3,0) ne bloquent pas encore.
        expect($grille->ligneDeVue(0, 0, 4, 0))->toBeTrue();
    });

    it('est symétrique : ligneDeVue(a,b) == ligneDeVue(b,a)', function () {
        $grille = grilleDepuis([
            'sssss',
            'ssmss',
            'sssss',
            'sssss',
        ]);

        // Paire bloquée.
        expect($grille->ligneDeVue(2, 0, 2, 3))
            ->toBe($grille->ligneDeVue(2, 3, 2, 0))
            ->and($grille->ligneDeVue(2, 0, 2, 3))->toBeFalse();

        // Paire dégagée (en diagonale, contournant le mur, pour éprouver le
        // tracé dans les deux sens).
        expect($grille->ligneDeVue(0, 2, 4, 3))
            ->toBe($grille->ligneDeVue(4, 3, 0, 2))
            ->and($grille->ligneDeVue(0, 2, 4, 3))->toBeTrue();
    });

    it('bloque la vue sur une figure interposée seulement quand figuresBloquent (tir/sort)', function () {
        $grille = grilleDepuis([
            'sssss',
        ]);
        // Une figure occupe (2,0), entre (0,0) et (4,0).
        $grille->occuper([['x' => 2, 'y' => 0]]);

        // Déplacement / vue « murs seuls » : la figure ne bloque pas.
        expect($grille->ligneDeVue(0, 0, 4, 0))->toBeTrue();
        // Tir / sort : la figure interposée coupe la vue.
        expect($grille->ligneDeVue(0, 0, 4, 0, figuresBloquent: true))->toBeFalse();
        // Extrémités jamais bloquantes : viser une case adjacente reste possible
        // même si la cible elle-même occupe sa case.
        expect($grille->ligneDeVue(0, 0, 2, 0, figuresBloquent: true))->toBeTrue()
            ->and($grille->ligneDeVue(1, 0, 3, 0, figuresBloquent: true))->toBeFalse();
    });

    it('gère proprement les coordonnées hors grille (aucune erreur d index)', function () {
        $grille = grilleDepuis([
            'sss',
            'sss',
            'sss',
        ]);

        // Sortir de la grille est traité comme un mur, sans lever d exception.
        expect($grille->ligneDeVue(0, 0, 10, 10))->toBeBool()
            ->and($grille->ligneDeVue(-5, -5, 1, 1))->toBeBool();
    });
});
