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
 *
 * TROIS jeux de cases distincts, à ne JAMAIS refusionner (doc 17, portage —
 * c'est exactement le bug corrigé par cette séparation) :
 *  - `$occupees` (`occuper()`) : les FIGURES (héros, monstres, alliés).
 *    Bloquent le mouvement ET, si `figuresBloquent`, la ligne de vue — on ne
 *    lance pas un sort à travers un allié ou un ennemi.
 *  - `$obstacles` (`obstruer()`) : le mobilier qui bloque le MOUVEMENT
 *    (`bloque_mouvement`, ex. une table). Bloque `estTraversable()` mais ne
 *    participe JAMAIS au test `figuresBloquent` — ce n'est pas une figure
 *    interposée, une flèche passe par-dessus une table.
 *  - `$opaques` (`occulter()`) : le mobilier qui bloque la VUE
 *    (`bloque_vue`, ex. une bibliothèque). Coupe `ligneDeVue()`
 *    INCONDITIONNELLEMENT, comme un mur, que `figuresBloquent` soit vrai ou non.
 */
final class Grille
{
    /** Ordre d'exploration fixe → comportements scriptés déterministes. */
    private const DIRECTIONS = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    /**
     * Figures interposables (héros, monstres, alliés) — voir occuper().
     *
     * @var array<string, true>
     */
    private array $occupees = [];

    /**
     * Cases rendues INFRANCHISSABLES par un meuble (`bloque_mouvement`, doc
     * 17) — distinct de `$occupees` : un meuble n'est PAS une figure, il ne
     * doit jamais participer au test `figuresBloquent` de `ligneDeVue()`
     * (sans quoi une simple table arrêterait les flèches, exactement le bug
     * corrigé ici). Seul `estTraversable()` lit ce jeu de cases.
     *
     * @var array<string, true>
     */
    private array $obstacles = [];

    /**
     * Cases rendues OPAQUES par un meuble haut (`bloque_vue`, doc 17 —
     * bibliothèque, râtelier, armoire…) — distinct de `$occupees` ET de
     * `$obstacles` : le mobilier bloque le mouvement (`obstruer()`) et/ou la
     * vue (`occulter()`) INDÉPENDAMMENT. Coupe `ligneDeVue()`
     * inconditionnellement, comme un mur — jamais conditionné à
     * `figuresBloquent`, qui ne concerne que les FIGURES interposées (héros,
     * monstres, alliés) : un meuble est du décor, pas une figure.
     *
     * @var array<string, true>
     */
    private array $opaques = [];

    /**
     * État des portes (chantier portes, doc 14 §3.1/3.3), indexé par ARÊTE
     * entre deux cases voisines (une porte ne prend PAS de case : elle vit sur
     * la cloison entre deux cases sol, activable des deux côtés). Chaque entrée
     * de carte est {x, y, cote, etat} : `cote` ∈ {'e','s'} — la porte sépare la
     * case (x,y) de sa voisine EST (x+1,y) ou SUD (x,y+1). Clé canonique via
     * cleArete(). Une porte NON ouverte coupe le PASSAGE et la VUE sur cette
     * arête ; une porte ouverte est franchissable et transparente.
     *
     * @var array<string, string> clé arête → 'ouverte' | 'fermee' | 'verrouillee' | 'secrete'
     */
    private array $portes = [];

    /**
     * @param  list<list<string>>  $cases  m = mur, s = sol
     */
    public function __construct(private readonly array $cases) {}

    public static function depuisCarte(Carte $carte): self
    {
        $grille = new self($carte->grille['cases'] ?? []);
        $grille->definirPortes($carte->grille['portes'] ?? []);

        return $grille;
    }

    /**
     * Clé canonique d'une arête entre deux cases ORTHOGONALEMENT voisines
     * (indépendante du sens) : les deux clés de case triées et jointes.
     */
    public static function cleArete(int $x1, int $y1, int $x2, int $y2): string
    {
        $a = "{$x1},{$y1}";
        $b = "{$x2},{$y2}";

        return $a <= $b ? "{$a}|{$b}" : "{$b}|{$a}";
    }

    /**
     * Les deux cases séparées par une porte {x, y, cote}. `cote` 'e' → voisine
     * EST ; 's' → voisine SUD (repli : 'e').
     *
     * @param  array{x: int, y: int, cote?: string}  $porte
     * @return array{0: array{x: int, y: int}, 1: array{x: int, y: int}}
     */
    public static function casesPorte(array $porte): array
    {
        $x = (int) $porte['x'];
        $y = (int) $porte['y'];
        $sud = ($porte['cote'] ?? 'e') === 's';

        return [['x' => $x, 'y' => $y], ['x' => $sud ? $x : $x + 1, 'y' => $sud ? $y + 1 : $y]];
    }

    /**
     * Charge l'état des portes de la carte (cartes.grille.portes) dans la
     * grille tactique. Chaque entrée : {x, y, cote, etat, verrou?, revele?}.
     *
     * @param  list<array{x: int, y: int, cote?: string, etat?: string}>  $portes
     */
    public function definirPortes(array $portes): void
    {
        foreach ($portes as $porte) {
            if (! isset($porte['x'], $porte['y'])) {
                continue;
            }
            [$a, $b] = self::casesPorte($porte);
            $this->portes[self::cleArete($a['x'], $a['y'], $b['x'], $b['y'])] = (string) ($porte['etat'] ?? 'ouverte');
        }
    }

    /**
     * Y a-t-il une porte NON ouverte sur l'arête entre deux cases voisines ?
     * (verrouillée / secrète non révélée incluses — infranchissable et opaque).
     */
    public function porteBloqueEntre(int $x1, int $y1, int $x2, int $y2): bool
    {
        // Traverser la Pierre passe AUSSI les portes closes : le héros ne les
        // franchit pas, il contourne par la roche. Les laisser bloquantes
        // rendrait le sort incapable de faire précisément ce pour quoi on le
        // lance — dégager un passage quand la porte est prise.
        if ($this->traverseRoche) {
            return false;
        }

        $etat = $this->portes[self::cleArete($x1, $y1, $x2, $y2)] ?? null;

        return $etat !== null && $etat !== 'ouverte';
    }

    /**
     * Marque des cases occupées par une FIGURE (héros, monstre, allié) :
     * infranchissables, et interposables au test `figuresBloquent` de
     * `ligneDeVue()` — on ne lance pas un sort à travers un allié ou un
     * ennemi. Le mobilier ne passe JAMAIS par ici : voir `obstruer()` /
     * `occulter()`.
     *
     * @param  list<array{x: int, y: int}>  $positions
     */
    public function occuper(array $positions): void
    {
        foreach ($positions as $position) {
            $this->occupees["{$position['x']},{$position['y']}"] = true;
        }
    }

    /**
     * Marque des cases INFRANCHISSABLES par un meuble (`bloque_mouvement`,
     * doc 17) — même patron qu'`occuper()`, mais un jeu de cases distinct :
     * contrairement à une figure, un meuble ne bloque JAMAIS la ligne de vue
     * via `figuresBloquent` (ce n'est pas une figure interposée) — seule
     * `occulter()` peut lui faire couper la vue, et alors inconditionnellement.
     *
     * @param  list<array{x: int, y: int}>  $positions
     */
    public function obstruer(array $positions): void
    {
        foreach ($positions as $position) {
            $this->obstacles["{$position['x']},{$position['y']}"] = true;
        }
    }

    /**
     * Marque des cases OPAQUES (meuble haut bloquant la vue, `bloque_vue`,
     * doc 17) — même patron qu'`occuper()`, mais un jeu de cases distinct :
     * une case peut bloquer le mouvement (`obstruer()`), la vue (ici), les
     * deux, ou ni l'un ni l'autre — deux propriétés INDÉPENDANTES.
     *
     * @param  list<array{x: int, y: int}>  $positions
     */
    public function occulter(array $positions): void
    {
        foreach ($positions as $position) {
            $this->opaques["{$position['x']},{$position['y']}"] = true;
        }
    }

    /**
     * Traverser la Pierre (doc 02 §7) : pour CE héros et CE tour, la roche ne
     * barre plus le passage — « traverse les murs sur tout le déplacement du
     * jet » (Witch Lord, reference/18_extensions.md §3).
     *
     * Ne lève QUE la roche : une figure ou un meuble bloque toujours (on ne
     * traverse pas un compagnon), et la ligne de vue n'est pas touchée — voir
     * au-delà d'un mur reste impossible.
     */
    /**
     * **Agile** (Jungles of Delthrak, p. 48) : « ignore le terrain gênant, le
     * mobilier et les héros en se déplaçant ».
     *
     * Le mobilier et les figures cessent de barrer le chemin — les MURS, eux,
     * tiennent : la carte parle de terrain et de créatures, pas de pierre. À
     * distinguer donc de `autoriserLaRoche()`, qui est l'inverse (la roche
     * s'ouvre, les figures continuent de bloquer).
     */
    public function autoriserFranchissement(): void
    {
        $this->obstacles = [];
        $this->occupees = [];
    }

    /**
     * **Éthéré** (Rise of the Dread Moon) : « traversent héros / murs / objets
     * solides ». Roche, portes closes, mobilier et figures : tout s'efface.
     *
     * ⚠ La règle ajoute deux interdits que la GRILLE ne peut pas porter, et que
     * l'appelant doit tenir : « jamais dans une zone non découverte » et
     * « jamais pour finir sur une case occupée ». Le second surtout — une
     * traversée n'est pas une superposition.
     */
    public function autoriserEthere(): void
    {
        $this->autoriserLaRoche();
        $this->autoriserFranchissement();
    }

    public function autoriserLaRoche(): void
    {
        $this->traverseRoche = true;
    }

    private bool $traverseRoche = false;

    public function estTraversable(int $x, int $y): bool
    {
        // Une porte ne prend plus de case (arête) : la traversabilité est
        // purement « case libre » (sol, inoccupée). Le blocage par une porte
        // fermée se joue sur l'ARÊTE entre deux cases (porteBloqueEntre),
        // évalué au moment du pas (pathfinding) — pas ici.
        // Occupée par une FIGURE, OU rendue infranchissable par un meuble
        // (`$obstacles`, doc 17) : les deux jeux de cases bloquent le
        // mouvement, mais seul `$occupees` participe au test `figuresBloquent`
        // de `ligneDeVue()` — un meuble n'est pas une figure interposée.
        if (isset($this->occupees["{$x},{$y}"]) || isset($this->obstacles["{$x},{$y}"])) {
            return false;
        }

        // Traverser la Pierre : la roche cesse d'être un mur. On reste DANS la
        // carte — franchir le bord mènerait hors-grille, sans case où finir.
        if ($this->traverseRoche) {
            return $x >= 0 && $y >= 0 && $y < count($this->cases) && $x < count($this->cases[$y] ?? []);
        }

        return ($this->cases[$y][$x] ?? 'm') === 's';
    }

    /** La case est-elle de la ROCHE pleine ? (fin de mouvement mortelle) */
    public function estRoche(int $x, int $y): bool
    {
        return ($this->cases[$y][$x] ?? 'm') !== 's';
    }

    public function sontAdjacentes(int $x1, int $y1, int $x2, int $y2, bool $diagonales = false): bool
    {
        // Par défaut ORTHOGONAL (doc 03 §12). `$diagonales` sert aux figures
        // dont la carte dit « can attack diagonally » — le Fauchard, le Loup,
        // le Croc-sabre — et n'ouvre QUE le test d'attaque : le déplacement,
        // lui, reste orthogonal pour tout le monde.
        if ($diagonales) {
            return max(abs($x1 - $x2), abs($y1 - $y2)) === 1;
        }

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
    public function adjacenteAEmprise(int $x, int $y, int $l, int $h, int $tx, int $ty, bool $diagonales = false): bool
    {
        foreach ($this->cellulesEmprise($x, $y, $l, $h) as $c) {
            if ($this->sontAdjacentes($c['x'], $c['y'], $tx, $ty, $diagonales)) {
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
     * transparents. Un meuble OPAQUE (`occulter()`, doc 17) coupe la vue tout
     * aussi INCONDITIONNELLEMENT qu'un mur, que `figuresBloquent` soit vrai ou
     * non — c'est du décor, pas une figure interposée.
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
        // exclues : elles ne bloquent jamais). Chaque pas ORTHOGONAL franchit une
        // arête : une porte fermée sur cette arête coupe la vue (les extrémités
        // comprises — une porte close au seuil du tireur/de la cible aveugle).
        while ($avanceX < $dx || $avanceY < $dy) {
            $px = $x;
            $py = $y;

            // Décision : (0.5 + avanceX) / dx  vs  (0.5 + avanceY) / dy.
            $decision = (1 + 2 * $avanceX) * $dy - (1 + 2 * $avanceY) * $dx;
            $diagonal = false;

            if ($decision === 0) {
                // Diagonale parfaite : on coupe le coin (un seul pas diagonal —
                // ne franchit aucune arête de porte, seulement un coin de mur).
                $x += $pasX;
                $y += $pasY;
                $avanceX++;
                $avanceY++;
                $diagonal = true;
            } elseif ($decision < 0) {
                $x += $pasX;
                $avanceX++;
            } else {
                $y += $pasY;
                $avanceY++;
            }

            // Porte fermée sur l'arête franchie (pas orthogonal) → vue coupée.
            if (! $diagonal && $this->porteBloqueEntre($px, $py, $x, $y)) {
                return false;
            }

            // Case d'arrivée atteinte : c'est une extrémité, on ne teste pas la
            // case elle-même (l'arête vers elle vient d'être testée ci-dessus).
            if ($x === $x2 && $y === $y2) {
                break;
            }

            // Mur ('m'/hors grille) → vue coupée.
            if ($this->bloqueVue($x, $y)) {
                return false;
            }

            // Meuble opaque (bibliothèque, râtelier d'armes, armoire… — doc 17) :
            // coupe la vue INCONDITIONNELLEMENT, comme un mur. Volontairement
            // AVANT le test figuresBloquent ci-dessous : celui-ci ne concerne
            // que les FIGURES interposées (héros, monstres), pas le décor — une
            // table (bloque_vue=false) n'arrête jamais une flèche, mais une
            // bibliothèque (bloque_vue=true) le fait même quand figuresBloquent
            // est faux (déplacement/vue « murs seuls »).
            if (isset($this->opaques["{$x},{$y}"])) {
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
     * La case (x,y) coupe-t-elle la ligne de vue ? Uniquement un mur ('m'/hors
     * grille) désormais — les portes vivent sur les arêtes (porteBloqueEntre).
     */
    private function bloqueVue(int $x, int $y): bool
    {
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
     * Toutes les cases atteignables en AU PLUS `$pas` pas, avec leur chemin.
     *
     * Même parcours que {@see self::chemin()} — cases occupées exclues, portes
     * fermées bloquantes — mais borné et SANS destination : on veut l'ensemble,
     * pas une route. Existe pour que l'IA puisse CHOISIR sa case plutôt que de
     * subir la plus courte : un monstre à distance repositionne pour garder sa
     * ligne de mire, ce qu'un `chemin()` par case candidate ferait au prix d'un
     * BFS complet par candidate (des centaines par tour).
     *
     * @return array<string, list<array{x: int, y: int}>> "x,y" → étapes sans le départ
     */
    public function casesAtteignables(int $departX, int $departY, int $pas): array
    {
        if ($pas < 1) {
            return [];
        }

        $file = [[$departX, $departY, 0]];
        $vus = ["{$departX},{$departY}" => true];
        $chemins = [];

        while ($file !== []) {
            [$x, $y, $d] = array_shift($file);

            if ($d >= $pas) {
                continue;
            }

            foreach (self::DIRECTIONS as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                $cle = "{$nx},{$ny}";

                if (isset($vus[$cle]) || ! $this->estTraversable($nx, $ny)) {
                    continue;
                }

                if ($this->porteBloqueEntre($x, $y, $nx, $ny)) {
                    continue;
                }

                $vus[$cle] = true;
                $chemins[$cle] = [...($chemins["{$x},{$y}"] ?? []), ['x' => $nx, 'y' => $ny]];
                $file[] = [$nx, $ny, $d + 1];
            }
        }

        return $chemins;
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

                // Une porte fermée sur l'arête (x,y)→(nx,ny) barre le pas.
                if ($this->porteBloqueEntre($x, $y, $nx, $ny)) {
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
