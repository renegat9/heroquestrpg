<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Carte;

/**
 * Vue tactique de la carte assemblée : traversabilité et plus courts chemins
 * ORTHOGONAUX (doc 03 §12 — pas de diagonale) par parcours en largeur (BFS).
 *
 * Les cases occupées (héros — y compris tombés, C4 : ils occupent leur case —
 * et monstres actifs) sont infranchissables ; le désengagement reste libre
 * (C3 : aucune attaque d'opportunité).
 */
final class Grille
{
    /** Ordre d'exploration fixe → comportements scriptés déterministes. */
    private const DIRECTIONS = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    /** @var array<string, true> */
    private array $occupees = [];

    /**
     * État des portes (chantier portes, doc 14 §3.1/3.3) indexé par case :
     * "x,y" => 'ouverte' | 'verrouillee' | 'secrete'. Une porte NON ouverte
     * est infranchissable ET bloque la ligne de vue (comme un mur) ; une porte
     * ouverte est traversable et transparente. L'overlay fait AUTORITÉ sur la
     * valeur de `cases` pour ces cellules : une porte secrète est posée sur une
     * case « m » (invisible) et redevient franchissable dès qu'elle s'ouvre,
     * sans toucher à `cases`.
     *
     * @var array<string, string>
     */
    private array $portes = [];

    /**
     * @param  list<list<string>>  $cases  m = mur, s = sol, p = porte
     */
    public function __construct(private readonly array $cases) {}

    public static function depuisCarte(Carte $carte): self
    {
        $grille = new self($carte->grille['cases'] ?? []);
        $grille->definirPortes($carte->grille['portes'] ?? []);

        return $grille;
    }

    /**
     * Charge l'état des portes de la carte (cartes.grille.portes) dans la
     * grille tactique. Chaque entrée : {x, y, etat, verrou?, revele?}.
     *
     * @param  list<array{x: int, y: int, etat?: string}>  $portes
     */
    public function definirPortes(array $portes): void
    {
        foreach ($portes as $porte) {
            if (isset($porte['x'], $porte['y'])) {
                $this->portes["{$porte['x']},{$porte['y']}"] = (string) ($porte['etat'] ?? 'ouverte');
            }
        }
    }

    /**
     * @param  list<array{x: int, y: int}>  $positions
     */
    public function occuper(array $positions): void
    {
        foreach ($positions as $position) {
            $this->occupees["{$position['x']},{$position['y']}"] = true;
        }
    }

    public function estTraversable(int $x, int $y): bool
    {
        $cle = "{$x},{$y}";

        if (isset($this->occupees[$cle])) {
            return false;
        }

        // L'overlay des portes prime : une porte non ouverte est infranchissable.
        if (isset($this->portes[$cle])) {
            return $this->portes[$cle] === 'ouverte';
        }

        $case = $this->cases[$y][$x] ?? 'm';

        return $case === 's' || $case === 'p';
    }

    public function sontAdjacentes(int $x1, int $y1, int $x2, int $y2): bool
    {
        return abs($x1 - $x2) + abs($y1 - $y2) === 1;
    }

    /**
     * Cases couvertes par l'emprise d'une grande figurine (3.9, doc 14) ancrée
     * en (x,y) — l'ancre = coin haut-gauche. Pour dx∈[0,l-1] et dy∈[0,h-1] :
     * (x+dx, y+dy). Pour un monstre 1×1 (cas par défaut), renvoie [{x,y}].
     *
     * @return list<array{x: int, y: int}>
     */
    public function cellulesEmprise(int $x, int $y, int $l, int $h): array
    {
        $cellules = [];

        for ($dy = 0; $dy < max(1, $h); $dy++) {
            for ($dx = 0; $dx < max(1, $l); $dx++) {
                $cellules[] = ['x' => $x + $dx, 'y' => $y + $dy];
            }
        }

        return $cellules;
    }

    /**
     * La case (tx,ty) touche-t-elle ORTHOGONALEMENT au moins une case de
     * l'emprise ancrée en (x,y) ? Sert au contact (un héros est adjacent à un
     * grand monstre dès qu'il jouxte l'une de ses cases). Pour une emprise 1×1,
     * équivaut exactement à sontAdjacentes(x,y,tx,ty).
     */
    public function adjacenteAEmprise(int $x, int $y, int $l, int $h, int $tx, int $ty): bool
    {
        foreach ($this->cellulesEmprise($x, $y, $l, $h) as $c) {
            if ($this->sontAdjacentes($c['x'], $c['y'], $tx, $ty)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ligne de vue vers une emprise : vraie si AU MOINS une case de l'emprise
     * ancrée en (x2,y2) est visible depuis (x1,y1). Pour une emprise 1×1,
     * équivaut exactement à ligneDeVue(x1,y1,x2,y2).
     */
    public function ligneDeVueEmprise(int $x1, int $y1, int $x2, int $y2, int $l, int $h, bool $figuresBloquent = false): bool
    {
        foreach ($this->cellulesEmprise($x2, $y2, $l, $h) as $c) {
            if ($this->ligneDeVue($x1, $y1, $c['x'], $c['y'], $figuresBloquent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Toute l'emprise ancrée en (x,y) est-elle traversable (sol/porte, inoccupée) ?
     * Sert à valider qu'une grande figurine TIENT à une case d'arrivée. Pour une
     * emprise 1×1, équivaut exactement à estTraversable(x,y).
     */
    public function empriseLibre(int $x, int $y, int $l, int $h): bool
    {
        foreach ($this->cellulesEmprise($x, $y, $l, $h) as $c) {
            if (! $this->estTraversable($c['x'], $c['y'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ligne de vue (prérequis Phase 2, doc 14) : (x2,y2) est-elle visible
     * depuis (x1,y1) ?
     *
     * La vue est coupée par tout mur ('m') situé STRICTEMENT entre les deux
     * extrémités ET par toute porte NON ouverte (verrouillée / secrète non
     * révélée — overlay des portes, doc 14 §3.1/3.3) ; les cases extrémités ne
     * bloquent jamais leur propre visibilité. Sol ('s') et porte OUVERTE sont
     * transparents.
     *
     * Algorithme : DDA supercover entier (Amanatides–Woo simplifié) parcourant
     * chaque case que traverse le segment de centre à centre. Préféré au
     * Bresenham « classique » car celui-ci, sur les pentes faibles, peut se
     * faufiler entre deux murs qui se touchent en coin ; le supercover visite
     * toute case réellement traversée (et coupe net les coins sur les
     * diagonales parfaites, pour qu'une diagonale dégagée reste visible).
     * La symétrie ligneDeVue(a,b) == ligneDeVue(b,a) est garantie en
     * ordonnant les extrémités de façon canonique avant le tracé : le segment
     * tracé est strictement le même quel que soit le sens d'appel.
     *
     * `$figuresBloquent` (tir / sort, doc 03 §36) : une figure INTERPOSÉE (case
     * occupée strictement entre les deux extrémités) coupe aussi la vue — on ne
     * lance pas un sort à travers un allié. Les extrémités (lanceur et cible) ne
     * bloquent jamais leur propre visibilité.
     */
    public function ligneDeVue(int $x1, int $y1, int $x2, int $y2, bool $figuresBloquent = false): bool
    {
        // Une case se voit toujours elle-même.
        if ($x1 === $x2 && $y1 === $y2) {
            return true;
        }

        // Ordre canonique des extrémités → tracé identique dans les deux sens.
        if ([$x1, $y1] > [$x2, $y2]) {
            [$x1, $y1, $x2, $y2] = [$x2, $y2, $x1, $y1];
        }

        $dx = abs($x2 - $x1);
        $dy = abs($y2 - $y1);
        $pasX = $x2 > $x1 ? 1 : -1;
        $pasY = $y2 > $y1 ? 1 : -1;

        $x = $x1;
        $y = $y1;
        $avanceX = 0;
        $avanceY = 0;

        // On parcourt les cases intermédiaires uniquement (les extrémités sont
        // exclues : elles ne bloquent jamais).
        while ($avanceX < $dx || $avanceY < $dy) {
            // Décision : (0.5 + avanceX) / dx  vs  (0.5 + avanceY) / dy.
            $decision = (1 + 2 * $avanceX) * $dy - (1 + 2 * $avanceY) * $dx;

            if ($decision === 0) {
                // Diagonale parfaite : on coupe le coin (un seul pas diagonal).
                $x += $pasX;
                $y += $pasY;
                $avanceX++;
                $avanceY++;
            } elseif ($decision < 0) {
                $x += $pasX;
                $avanceX++;
            } else {
                $y += $pasY;
                $avanceY++;
            }

            // Case d'arrivée atteinte : c'est une extrémité, on ne teste pas.
            if ($x === $x2 && $y === $y2) {
                break;
            }

            // Mur, hors grille (?? 'm') ou porte fermée → vue coupée.
            if ($this->bloqueVue($x, $y)) {
                return false;
            }

            // Figure interposée (tir / sort) : une case occupée sur le trajet
            // coupe la vue — pas de sort à travers un allié ou un ennemi.
            if ($figuresBloquent && isset($this->occupees["{$x},{$y}"])) {
                return false;
            }
        }

        return true;
    }

    /**
     * La case (x,y) coupe-t-elle la ligne de vue ? Mur ('m'/hors grille) ou
     * porte NON ouverte (overlay des portes). Une porte ouverte est transparente.
     */
    private function bloqueVue(int $x, int $y): bool
    {
        $cle = "{$x},{$y}";

        if (isset($this->portes[$cle])) {
            return $this->portes[$cle] !== 'ouverte';
        }

        return ($this->cases[$y][$x] ?? 'm') === 'm';
    }

    /**
     * Plus court chemin orthogonal (cases occupées exclues, départ inclus
     * d'office) ; null si l'arrivée est inaccessible.
     *
     * @return list<array{x: int, y: int}>|null étapes SANS la case de départ
     */
    public function chemin(int $departX, int $departY, int $arriveeX, int $arriveeY): ?array
    {
        if ($departX === $arriveeX && $departY === $arriveeY) {
            return [];
        }

        $parents = $this->parcours($departX, $departY, $arriveeX, $arriveeY);
        $cle = "{$arriveeX},{$arriveeY}";

        if (! isset($parents[$cle])) {
            return null;
        }

        $chemin = [];
        while ($cle !== "{$departX},{$departY}") {
            [$x, $y] = array_map(intval(...), explode(',', $cle));
            $chemin[] = ['x' => $x, 'y' => $y];
            $cle = $parents[$cle];
        }

        return array_reverse($chemin);
    }

    /**
     * Distance de déplacement (nb de pas orthogonaux) ; null si inaccessible.
     */
    public function distance(int $departX, int $departY, int $arriveeX, int $arriveeY): ?int
    {
        $chemin = $this->chemin($departX, $departY, $arriveeX, $arriveeY);

        return $chemin === null ? null : count($chemin);
    }

    /**
     * BFS depuis le départ ; s'arrête dès que l'arrivée est atteinte.
     *
     * @return array<string, string> clé case → clé case parente
     */
    private function parcours(int $departX, int $departY, int $arriveeX, int $arriveeY): array
    {
        $depart = "{$departX},{$departY}";
        $file = [[$departX, $departY]];
        $parents = [];
        $vus = [$depart => true];

        while ($file !== []) {
            [$x, $y] = array_shift($file);

            foreach (self::DIRECTIONS as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                $cle = "{$nx},{$ny}";

                if (isset($vus[$cle]) || ! $this->estTraversable($nx, $ny)) {
                    continue;
                }

                $vus[$cle] = true;
                $parents[$cle] = "{$x},{$y}";

                if ($nx === $arriveeX && $ny === $arriveeY) {
                    return $parents;
                }

                $file[] = [$nx, $ny];
            }
        }

        return $parents;
    }
}
