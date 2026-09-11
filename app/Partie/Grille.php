<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Carte;

/**
 * Vue tactique de la carte assemblée : traversabilité et plus courts chemins
 * ORTHOGONAUX (doc 03 §12 — pas de diagonale).
 *
 * ⚠ DEUX parcours cohabitent, et ne doivent JAMAIS se confondre (doc 18 §4,
 * plan glace §2 — la Rivière Gelée est ce qui les a séparés) :
 *  - `chemin()` / `casesAtteignables()` sont PONDÉRÉS (Dijkstra, file de
 *    priorité, `parcoursPondere()`) : chaque pas coûte `coutDeplacement()` de
 *    la case d'ARRIVÉE, pas systématiquement 1. C'est le déplacement RÉEL —
 *    une case de Rivière Gelée (coût 2) le ralentit deux fois plus qu'une
 *    case de sol ordinaire.
 *  - `distance()` reste un parcours à coût UNIFORME (`parcours()`, un pas =
 *    un pas) : elle sert la PORTÉE et l'ADJACENCE (une arbalète tire à N
 *    cases, deux figures sont adjacentes à distance 1), qu'aucun terrain ne
 *    ralentit — une flèche n'est pas freinée par la glace. La confondre avec
 *    le parcours pondéré raccourcirait la portée de toute arme à distance
 *    dès qu'une case de glace se trouve sur la ligne.
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
 *
 * ⚠ **Une porte NON ouverte bloque sa CASE, en plus de son ARÊTE** (René,
 * 2026-09-11, après avoir joué : « la porte doit être centrale à sa case,
 * bloquant l'entrée dans sa case tant qu'elle n'est pas ouverte »). Une porte
 * garde son arête (`$portes`/`porteBloqueEntre()`, inchangé) ET gagne une
 * case (`$porteParCase`/`caseEmbrasure()`) : sa case d'EMBRASURE — celle des
 * deux `casesPorte()` qui tombe sur l'anneau de mur d'une salle, voir
 * `caseEmbrasure()` — devient inoccupable et opaque tant que l'état n'est pas
 * `ouverte`, exactement comme `estTraversable()`/`ligneDeVue()` le font déjà
 * pour 'm'. Ouverte, elle redevient un sol ordinaire : on la traverse et on
 * voit à travers, aucun coût de déplacement ne change. L'algorithme de
 * `ligneDeVue()` ne change pas : une case de plus lui est simplement soumise.
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
     * Figures du MÊME CAMP que celui qui bouge — un compagnon pour un héros, un
     * autre monstre pour un monstre.
     *
     * ⚠ Trois propriétés, et il faut les trois séparément : elles ne bloquent
     * PAS le passage (« on peut traverser la case d'un autre héros », LR p. 12),
     * elles bloquent la VUE comme n'importe quelle figure interposée, et elles
     * interdisent l'ARRÊT (« on ne peut jamais partager une case », même page).
     * Les fondre dans `$occupees` interdisait le passage ; les fondre dans rien
     * du tout aurait laissé tirer à travers ses propres compagnons — et c'est
     * précisément ce que l'attaque en diagonale existe pour compenser.
     */
    private array $alliees = [];

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
     * État des portes indexé par CASE D'EMBRASURE (René, 2026-09-11, après
     * avoir joué : « la porte doit être centrale à sa case, bloquant l'entrée
     * dans sa case tant qu'elle n'est pas ouverte »). Distinct de `$portes`
     * ci-dessus (indexé par ARÊTE, inchangé, toujours consulté par
     * `porteBloqueEntre()`) : les DEUX coexistent, la porte bloque désormais
     * sa case EN PLUS de son arête, elle ne remplace rien.
     *
     * Pourquoi une case de plus était nécessaire : l'arête ne protège qu'UN
     * des deux pas menant à l'embrasure (celui qui vient du couloir). L'autre
     * pas — venu de L'INTÉRIEUR de la salle, vers cette même case — n'était
     * gardé par AUCUNE arête déclarée (`AssembleurCarte::creuserArete()` ne
     * pousse qu'UNE porte par bout de jonction), si bien qu'un héros pouvait
     * AUJOURD'HUI se tenir dans une embrasure fermée en y entrant par la
     * salle. Bloquer la CASE ferme les deux pas d'un coup, sans toucher à
     * `porteBloqueEntre()` ni à l'algorithme de `ligneDeVue()` — une case de
     * plus à consulter, exactement comme pour 'm'. Alimenté par
     * `definirPortes()` via `caseEmbrasure()`.
     *
     * @var array<string, string> clé case "x,y" → 'ouverte' | 'fermee' | 'verrouillee' | 'secrete'
     */
    private array $porteParCase = [];

    /**
     * COÛT DE DÉPLACEMENT par case (doc 18 §4, terrain), défaut 1 (une case
     * ordinaire). Posé par `definirCoutsDeplacement()`, lu par
     * `coutDeplacement()` et consommé par le parcours PONDÉRÉ
     * (`parcoursPondere()`, `chemin()`, `casesAtteignables()`) — jamais par
     * `distance()`, qui reste géométrique. Voir le docblock de la classe.
     *
     * @var array<string, int>
     */
    private array $couts = [];

    /**
     * @param  list<list<string>>  $cases  m = mur, s = sol
     */
    public function __construct(private readonly array $cases) {}

    public static function depuisCarte(Carte $carte): self
    {
        $grille = new self($carte->grille['cases'] ?? []);
        $grille->definirPortes($carte->grille['portes'] ?? [], $carte->grille['salles'] ?? []);

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
     * `$salles` (cartes.grille.salles, mur compris) sert à `caseEmbrasure()` —
     * repli sur [] pour les grilles de test qui n'en posent pas (voir son
     * docblock pour le comportement de repli).
     *
     * @param  list<array{x: int, y: int, cote?: string, etat?: string}>  $portes
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     */
    public function definirPortes(array $portes, array $salles = []): void
    {
        foreach ($portes as $porte) {
            if (! isset($porte['x'], $porte['y'])) {
                continue;
            }
            [$a, $b] = self::casesPorte($porte);
            $etat = (string) ($porte['etat'] ?? 'ouverte');
            $this->portes[self::cleArete($a['x'], $a['y'], $b['x'], $b['y'])] = $etat;

            $embrasure = self::caseEmbrasure($porte, $salles);
            $this->porteParCase["{$embrasure['x']},{$embrasure['y']}"] = $etat;
        }
    }

    /**
     * La case d'EMBRASURE d'une porte : celle des deux `casesPorte()` qui
     * tombe sur l'ANNEAU DE MUR d'une salle — son rectangle `salles[]` (mur
     * compris), mais seulement le BORD (x ou y sur une des quatre limites),
     * jamais l'intérieur. C'est très exactement la case que
     * `AssembleurCarte::creuserArete()` perce dans le mur d'une salle pour
     * ouvrir le seuil ; l'autre case (le couloir, ou pour une jonction
     * MITOYENNE l'intérieur immédiat de l'autre salle) n'a jamais été un mur
     * et reste un simple sol.
     *
     * Établi sur une carte réellement assemblée (René, 2026-09-11) : porte
     * `cote:'e'` en (29,30) — la colonne x=30 reste un MUR à la ligne du
     * dessus (y=29), donc c'est (30,30), PAS (29,30), qui est l'embrasure.
     * ⚠ La règle « côté ⇒ x+1 » suffit à CE cas mais pas en général :
     * `creuserArete()` perce tantôt la case GAUCHE/HAUT (sortie de la salle
     * PARENT), tantôt la case DROITE/BAS (entrée de la salle qui suit),
     * selon laquelle des deux salles de l'arête est spatialement à gauche —
     * une pure affaire de géométrie de l'arbre, indépendante du sens de la
     * relation parent/enfant. Seul le rectangle de salle permet de trancher
     * dans les deux sens ; c'est le même test que `seuilsDeSalle()` durcit
     * au BORD plutôt qu'à tout le rectangle (l'intérieur d'une salle
     * mitoyenne tombe, lui aussi, dans le rectangle du voisin sans jamais
     * avoir été un mur).
     *
     * Repli : si aucune salle ne réclame ni l'une ni l'autre case (grille de
     * test sans `salles`, jonction dégénérée), on retombe sur la case
     * (x+1,y)/(x,y+1) — le comportement historique, jamais atteint sur une
     * carte réellement assemblée.
     *
     * @param  array{x: int, y: int, cote?: string}  $porte
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     * @return array{x: int, y: int}
     */
    public static function caseEmbrasure(array $porte, array $salles): array
    {
        [$a, $b] = self::casesPorte($porte);

        foreach ([$a, $b] as $case) {
            foreach ($salles as $salle) {
                if (self::surAnneauMur($salle, $case['x'], $case['y'])) {
                    return $case;
                }
            }
        }

        return $b;
    }

    /** La case (x,y) est-elle sur le BORD du rectangle de la salle (mur compris) ? */
    private static function surAnneauMur(array $salle, int $x, int $y): bool
    {
        $xMin = (int) $salle['x'];
        $yMin = (int) $salle['y'];
        $xMax = $xMin + (int) $salle['largeur'] - 1;
        $yMax = $yMin + (int) $salle['hauteur'] - 1;

        if ($x < $xMin || $x > $xMax || $y < $yMin || $y > $yMax) {
            return false;
        }

        return $x === $xMin || $x === $xMax || $y === $yMin || $y === $yMax;
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
     * Marque les cases de figures ALLIÉES à celle qui bouge : traversables,
     * mais opaques et interdites à l'arrêt (LR p. 12, doc 16 §5).
     *
     * @param  list<array{x: int, y: int}>  $positions
     */
    public function occuperAllie(array $positions): void
    {
        foreach ($positions as $position) {
            $this->alliees["{$position['x']},{$position['y']}"] = true;
        }
    }

    /**
     * Une FIGURE se tient-elle ici — amie ou ennemie ?
     *
     * ⚠ La question que doit poser quiconque cherche une case où S'ARRÊTER :
     * `estTraversable()` répond désormais « oui » sur un allié, ce qui est le
     * but, et n'est donc plus le bon test pour une destination.
     */
    public function estOccupeeParFigure(int $x, int $y): bool
    {
        return isset($this->occupees["{$x},{$y}"]) || isset($this->alliees["{$x},{$y}"]);
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
     * Coûts de déplacement du TERRAIN (doc 18 §4), une entrée par case dont le
     * coût diffère de 1 (`{x, y, cout}`) — c'est ce qui rend la Rivière Gelée
     * possible (2 cases de déplacement par case franchie). Même patron que
     * `obstruer()`/`occulter()` : posé par `FabriqueGrille::pour()`, jamais
     * ailleurs.
     *
     * ⚠ FONDATION SEULE : rien ne consulte encore `$this->couts` — voir le
     * commentaire de la propriété et celui de `distance()`.
     *
     * @param  list<array{x: int, y: int, cout: int}>  $couts
     */
    public function definirCoutsDeplacement(array $couts): void
    {
        foreach ($couts as $entree) {
            $this->couts["{$entree['x']},{$entree['y']}"] = max(1, (int) $entree['cout']);
        }
    }

    /** Coût de déplacement d'une case — 1 (sol ordinaire) si non renseigné. */
    public function coutDeplacement(int $x, int $y): int
    {
        return $this->couts["{$x},{$y}"] ?? 1;
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
        $this->alliees = [];
    }

    /**
     * **Patinage** (Skate, The Frozen Horror, doc 18 §4 — sort du boss) :
     * « le lanceur patine [...] et traverse héros et monstres ». Distincte
     * d'`autoriserFranchissement()` (Agile), qui lève AUSSI le mobilier
     * bloquant : la carte de Patinage ne parle QUE des figures, jamais du
     * mobilier ni des murs — une créature qui patine glisse toujours autour
     * d'une table, elle ne la traverse pas. Seul `$occupees`/`$alliees` est
     * levé ; `$obstacles` (mobilier, terrain) reste plein.
     */
    public function autoriserFranchissementFigures(): void
    {
        $this->occupees = [];
        $this->alliees = [];
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
        // Une porte vit sur une ARÊTE (`porteBloqueEntre()`, évalué au moment
        // du pas côté pathfinding) — MAIS bloque aussi désormais sa case
        // d'EMBRASURE (voir plus bas, `porteFermeeSurCase()`), ce que ce
        // commentaire disait autrefois ne jamais arriver.
        // Occupée par une FIGURE, OU rendue infranchissable par un meuble
        // (`$obstacles`, doc 17) : les deux jeux de cases bloquent le
        // mouvement, mais seul `$occupees` participe au test `figuresBloquent`
        // de `ligneDeVue()` — un meuble n'est pas une figure interposée.
        if (isset($this->occupees["{$x},{$y}"]) || isset($this->obstacles["{$x},{$y}"])) {
            return false;
        }

        // Case d'EMBRASURE d'une porte NON ouverte (René, 2026-09-11) :
        // inoccupable, comme un mur — des DEUX côtés, contrairement à l'ancien
        // blocage par arête seule (`porteBloqueEntre()`, toujours actif
        // ci-dessous) qui ne gardait que le pas venu du couloir. Traverser la
        // Pierre lève CETTE case comme elle lève la roche et l'arête de porte
        // (voir `porteBloqueEntre()`) : elle ne fait AUCUNE exception pour une
        // porte, close ou non.
        if (! $this->traverseRoche && $this->porteFermeeSurCase($x, $y)) {
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
            // ⚠ Les alliés bloquent la VUE (mais pas le passage) : un compagnon
            // interposé coupe la ligne de tir, et c'est exactement le cas que
            // l'attaque en diagonale existe pour compenser (LR p. 14).
            if ($figuresBloquent && $this->estOccupeeParFigure($x, $y)) {
                return false;
            }
        }

        return true;
    }

    /**
     * La case (x,y) coupe-t-elle la ligne de vue ? Un mur ('m'/hors grille),
     * OU la case d'EMBRASURE d'une porte NON ouverte (René, 2026-09-11) — elle
     * coupe la vue exactement comme un mur, tant que la porte n'est pas
     * `ouverte`. L'arête (`porteBloqueEntre()`, testée séparément dans
     * `ligneDeVue()`) continue de couvrir l'axe « à travers la porte » ; ceci
     * ajoute l'axe « à travers la case », qu'aucune arête ne protégeait côté
     * intérieur de la salle. `traverseRoche` lève les deux ensemble (Traverser
     * la Pierre passe aussi les portes closes, voir `porteBloqueEntre()`).
     */
    private function bloqueVue(int $x, int $y): bool
    {
        if (($this->cases[$y][$x] ?? 'm') === 'm') {
            return true;
        }

        return ! $this->traverseRoche && $this->porteFermeeSurCase($x, $y);
    }

    /**
     * La case (x,y) est-elle l'embrasure d'une porte NON `ouverte` ?
     *
     * PUBLIC (et pas seulement interne à `estTraversable()`/`bloqueVue()`) :
     * `Rayon::cases()` (Esprit Ardent, Éclair) répète son PROPRE test
     * `estRoche() || porteBloqueEntre()` plutôt que d'appeler
     * `estTraversable()` — un rayon traverse les FIGURES, qu'`estTraversable()`
     * bloque, donc il ne peut pas le réutiliser tel quel. Sans cette case en
     * plus, un rayon lancé depuis L'INTÉRIEUR d'une salle vers une porte close
     * la traverserait tout droit, exactement le bug que ce chantier corrige
     * ailleurs — une seule méthode pour la question, appelée des DEUX endroits
     * qui la posent, plutôt que deux réponses qui pourraient diverger.
     */
    public function porteFermeeSurCase(int $x, int $y): bool
    {
        $etat = $this->porteParCase["{$x},{$y}"] ?? null;

        return $etat !== null && $etat !== 'ouverte';
    }

    /**
     * Plus court chemin orthogonal PONDÉRÉ (cases occupées exclues, départ
     * inclus d'office) ; null si l'arrivée est inaccessible. Chaque pas coûte
     * `coutDeplacement()` de la case d'ARRIVÉE du pas (doc 18 §4) — Dijkstra
     * via `parcoursPondere()`, pas la BFS à coût uniforme de `distance()`.
     *
     * @return list<array{x: int, y: int}>|null étapes SANS la case de départ
     */
    public function chemin(int $departX, int $departY, int $arriveeX, int $arriveeY): ?array
    {
        if ($departX === $arriveeX && $departY === $arriveeY) {
            return [];
        }

        $resultat = $this->parcoursPondere($departX, $departY, $arriveeX, $arriveeY);
        $cle = "{$arriveeX},{$arriveeY}";

        if (! isset($resultat['parents'][$cle])) {
            return null;
        }

        return $this->reconstruireChemin($resultat['parents'], "{$departX},{$departY}", $cle);
    }

    /**
     * Distance de déplacement GÉOMÉTRIQUE (nb de pas orthogonaux, jamais de
     * coût) ; null si inaccessible.
     *
     * ⚠ DOIT RESTER GÉOMÉTRIQUE (doc 18 §4, plan glace §2) : cette méthode
     * sert aussi à la PORTÉE et à l'ADJACENCE (une arbalète tire à N cases,
     * deux figures sont adjacentes à distance 1) — une flèche n'est PAS
     * ralentie par la glace. Elle garde donc son PROPRE parcours à coût
     * uniforme (`parcours()`) plutôt que de déléguer à `chemin()`, qui lui est
     * pondéré depuis le branchement de la Rivière Gelée : les deux parcours
     * partagent la même grille mais jamais le même algorithme. Les mélanger
     * raccourcirait la portée de toute arme à distance dès qu'une case de
     * glace se trouve sur la ligne.
     */
    public function distance(int $departX, int $departY, int $arriveeX, int $arriveeY): ?int
    {
        if ($departX === $arriveeX && $departY === $arriveeY) {
            return 0;
        }

        $parents = $this->parcours($departX, $departY, $arriveeX, $arriveeY);
        $cle = "{$arriveeX},{$arriveeY}";

        if (! isset($parents[$cle])) {
            return null;
        }

        $n = 0;
        while ($cle !== "{$departX},{$departY}") {
            $cle = $parents[$cle];
            $n++;
        }

        return $n;
    }

    /**
     * Coût total (points de déplacement) d'un chemin déjà calculé — somme de
     * `coutDeplacement()` sur chaque case du chemin (départ exclu, comme le
     * rend `chemin()`). Sert aux appelants qui doivent savoir combien de
     * points un trajet a réellement coûté (Rivière Gelée : 3 cases coûtent 6,
     * pas 3) sans recalculer eux-mêmes le parcours.
     *
     * @param  list<array{x: int, y: int}>  $chemin
     */
    public function coutChemin(array $chemin): int
    {
        $total = 0;

        foreach ($chemin as $case) {
            $total += $this->coutDeplacement((int) $case['x'], (int) $case['y']);
        }

        return $total;
    }

    /**
     * Combien de cases EN TÊTE d'un chemin déjà calculé (dans l'ordre rendu
     * par `chemin()`, départ exclu) tiennent dans un budget de points de
     * déplacement — chaque case comptant son `coutDeplacement()` propre,
     * jamais 1 uniformément.
     *
     * Sert aux appelants qui bornent un chemin DÉJÀ tronqué par autre chose
     * (piège, racines…) avec un budget de points connu APRÈS coup — le
     * déplacement des monstres et des alliés, qui indexaient jusqu'ici
     * `$chemin[$pas - 1]` en confondant nombre de cases et points dépensés.
     * `derniereCaseOuSArreter()` reste inchangée : elle attend toujours un
     * INDEX de cases, celui-ci le lui fournit déjà correct.
     *
     * ⚠ Cas limite : si même la PREMIÈRE case dépasse le budget (ex. 1 point
     * restant devant une case à 2), rend 0 — l'appelant ne doit alors PAS
     * bouger, jamais « s'arrêter à moitié » dans la case trop chère.
     *
     * @param  list<array{x: int, y: int}>  $chemin
     */
    public function pasAffordables(array $chemin, int $budget): int
    {
        $depense = 0;
        $n = 0;

        foreach ($chemin as $case) {
            $depense += $this->coutDeplacement((int) $case['x'], (int) $case['y']);

            if ($depense > $budget) {
                break;
            }

            $n++;
        }

        return $n;
    }

    /**
     * Toutes les cases atteignables en un budget d'AU PLUS `$pas` points de
     * déplacement, avec leur chemin — PONDÉRÉ (Dijkstra), comme `chemin()`.
     *
     * Même parcours que {@see self::chemin()} — cases occupées exclues, portes
     * fermées bloquantes — mais borné et SANS destination : on veut l'ensemble,
     * pas une route. Existe pour que l'IA puisse CHOISIR sa case plutôt que de
     * subir la plus courte : un monstre à distance repositionne pour garder sa
     * ligne de mire, ce qu'un `chemin()` par case candidate ferait au prix d'un
     * parcours complet par candidate (des centaines par tour).
     *
     * @return array<string, list<array{x: int, y: int}>> "x,y" → étapes sans le départ
     */
    public function casesAtteignables(int $departX, int $departY, int $pas): array
    {
        if ($pas < 1) {
            return [];
        }

        $depart = "{$departX},{$departY}";
        $resultat = $this->parcoursPondere($departX, $departY, budgetMax: $pas);

        $chemins = [];
        foreach (array_keys($resultat['couts']) as $cle) {
            if ($cle === $depart) {
                continue;
            }

            [$x, $y] = array_map(intval(...), explode(',', $cle));

            // ⚠ On TRAVERSE un allié, on ne s'y ARRÊTE pas : sa case a bien
            // été explorée par `parcoursPondere()` (qui l'ignore comme
            // `estTraversable()` le fait), mais ne figure pas parmi les
            // destinations proposées ici. Un seul point de passage pour la
            // règle — le menu, le déplacement et le tour des monstres lisent
            // tous cette liste.
            if ($this->estOccupeeParFigure($x, $y)) {
                continue;
            }

            $chemins[$cle] = $this->reconstruireChemin($resultat['parents'], $depart, $cle);
        }

        return $chemins;
    }

    /**
     * BFS à coût UNIFORME depuis le départ ; s'arrête dès que l'arrivée est
     * atteinte. Réservée à `distance()` (portée, adjacence) — voir son
     * docblock : `chemin()` et `casesAtteignables()` utilisent désormais
     * `parcoursPondere()`, un algorithme SŒUR mais distinct.
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

    /**
     * Parcours PONDÉRÉ (Dijkstra, file de priorité) depuis le départ — chaque
     * pas coûte `coutDeplacement()` de la case d'ARRIVÉE du pas (doc 18 §4 :
     * la Rivière Gelée coûte 2, pas 1). Cœur commun de `chemin()` et
     * `casesAtteignables()` — jamais de `distance()`, qui garde son propre
     * `parcours()` à coût uniforme (voir le docblock de la classe).
     *
     * S'arrête dès qu'une CIBLE fournie (`$cibleX`/`$cibleY`) est FINALISÉE
     * (Dijkstra classique : sa première extraction de la file porte déjà son
     * coût minimal). Sans cible, explore tout ce qu'autorise `$budgetMax`
     * (nécessaire à `casesAtteignables()` : sans borne, un donjon entier
     * serait parcouru à chaque appel).
     *
     * File de priorité codée sur un ENTIER unique (`-(coût * 1_000_000 +
     * séquence)`) plutôt que sur le coût seul : la séquence d'insertion
     * départage les coûts égaux dans l'ordre où ils ont été DÉCOUVERTS, qui
     * reproduit exactement l'ordre FIFO + `DIRECTIONS` fixe de l'ancienne BFS
     * — sur une grille à coût uniforme (aucune case de terrain posée), ce
     * parcours rend donc EXACTEMENT les mêmes chemins que l'ancienne BFS,
     * aucune suite existante ne doit en être affectée. « Ordre d'exploration
     * fixe → comportements scriptés déterministes », comme `DIRECTIONS`.
     * Suppression paresseuse (« lazy deletion ») : une entrée sortie de la
     * file dont le coût enregistré a depuis été amélioré est simplement
     * ignorée plutôt que retirée activement — PHP n'offre pas de
     * décrémentation de clé sur `SplPriorityQueue`.
     *
     * @return array{couts: array<string, int>, parents: array<string, string>}
     */
    private function parcoursPondere(
        int $departX,
        int $departY,
        ?int $cibleX = null,
        ?int $cibleY = null,
        ?int $budgetMax = null,
    ): array {
        $depart = "{$departX},{$departY}";
        $couts = [$depart => 0];
        $parents = [];
        $finalisees = [];

        $file = new \SplPriorityQueue();
        $sequence = 0;
        $file->insert([$departX, $departY, 0], 0);

        while (! $file->isEmpty()) {
            [$x, $y, $coutCourant] = $file->extract();
            $cle = "{$x},{$y}";

            // Entrée obsolète : soit déjà finalisée, soit dépassée par un
            // coût moindre inséré depuis (suppression paresseuse).
            if (isset($finalisees[$cle]) || $coutCourant > ($couts[$cle] ?? PHP_INT_MAX)) {
                continue;
            }

            $finalisees[$cle] = true;

            if ($cibleX !== null && $x === $cibleX && $y === $cibleY) {
                break;
            }

            foreach (self::DIRECTIONS as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                $ncle = "{$nx},{$ny}";

                if (isset($finalisees[$ncle]) || ! $this->estTraversable($nx, $ny)) {
                    continue;
                }

                if ($this->porteBloqueEntre($x, $y, $nx, $ny)) {
                    continue;
                }

                $nouveauCout = $coutCourant + $this->coutDeplacement($nx, $ny);

                if ($budgetMax !== null && $nouveauCout > $budgetMax) {
                    continue; // hors budget : jamais inséré, jamais une destination possible
                }

                if ($nouveauCout < ($couts[$ncle] ?? PHP_INT_MAX)) {
                    $couts[$ncle] = $nouveauCout;
                    $parents[$ncle] = $cle;
                    $sequence++;
                    $file->insert([$nx, $ny, $nouveauCout], -($nouveauCout * 1_000_000 + $sequence));
                }
            }
        }

        return ['couts' => $couts, 'parents' => $parents];
    }

    /**
     * Reconstruit un chemin (départ EXCLU, comme `chemin()` le rend) à partir
     * d'une table de parents (`parcours()` ou `parcoursPondere()`) — cœur
     * commun aux deux, pour que la façon de remonter la chaîne ne diverge
     * jamais entre les deux parcours.
     *
     * @param  array<string, string>  $parents
     * @return list<array{x: int, y: int}>
     */
    private function reconstruireChemin(array $parents, string $depart, string $cible): array
    {
        $chemin = [];
        $cle = $cible;

        while ($cle !== $depart) {
            [$x, $y] = array_map(intval(...), explode(',', $cle));
            $chemin[] = ['x' => $x, 'y' => $y];
            $cle = $parents[$cle];
        }

        return array_reverse($chemin);
    }
}
