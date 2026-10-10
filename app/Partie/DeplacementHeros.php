<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;

/**
 * OÙ UN HÉROS PEUT MARCHER — point de passage UNIQUE de la question (2026-10-10).
 *
 * Quatre lecteurs la posaient, chacun avec sa copie : le résolveur du
 * déplacement, l'aperçu de trajet, la décision « puis-je me déplacer ? » du
 * menu, et — le plus coûteux — la mini-carte de la manette, qui refaisait en
 * JS un parcours pondéré (`DeplacementSheet.vue`, six dérives de miroir en un
 * mois : allié traversé, monstre franchi, terrain pondéré, embrasure, bloc
 * tombé…). René : « ça serait plus efficace ainsi » — le serveur publie la
 * LISTE des cases atteignables (`se_deplacer.parametres.destinations`), calculée
 * ici avec le MÊME code que la résolution, et la manette ne fait plus que
 * l'éclairer.
 *
 * Cette classe porte donc :
 *  - la grille que CE héros parcourt ({@see self::grille()}), ex-`ResolveurTour::grilleDeplacement()` ;
 *  - le trajet qu'il emprunte vers une case ({@see self::chemin()}) ;
 *  - les refus d'ARRÊT ({@see self::refusArret()}) : traverser n'est pas s'arrêter ;
 *  - la liste des destinations ({@see self::destinations()}), qui EST la liste
 *    blanche que le résolveur re-valide.
 *
 * ⛔ AUCUNE FUITE D'INFORMATION CACHÉE (exigence de René, 2026-10-10). Les
 * destinations ne disent RIEN que le joueur ne sait pas déjà :
 *  - un piège CACHÉ n'est ni un obstacle ni un surcoût ni un détour : la case
 *    est publiée comme n'importe quelle autre, et le parcours ne le contourne
 *    pas (la forme du trajet le trahirait). Seuls les pièges CONNUS
 *    (`MoteurPieges::ETATS_CONNUS_ARMES`) infléchissent le chemin, comme dans
 *    l'aperçu ;
 *  - un passage secret non découvert est un MUR (porte non ouverte, embrasure
 *    close), et la carte le peint en roche ;
 *  - tout ce que le brouillard masque est un OBSTACLE de cette grille : les
 *    cases `b` de {@see EtatGroupe::casesConnues()} — salles non révélées,
 *    couloirs derrière une porte close — ne sont ni destination, ni passage, ni
 *    raccourci de coût. Un monstre caché dans une salle non révélée ne peut
 *    donc ni retirer une case de la liste, ni en changer le coût.
 *
 * Le test qui le pin : `DestinationsDeplacementTest` (cartes jumelles, avec et
 * sans le secret : listes STRICTEMENT identiques).
 */
final class DeplacementHeros
{
    /**
     * États de piège que la carte MONTRE déjà au groupe (`EtatGroupe::pieges()`),
     * et donc les seuls qu'un trajet peut nommer. Jamais `cache`.
     */
    private const ETATS_AFFICHES = [
        MoteurPieges::ETAT_DETECTE, MoteurPieges::ETAT_FOSSE_OUVERTE,
        MoteurPieges::ETAT_DESARME, MoteurPieges::ETAT_DECLENCHE,
        MoteurPieges::ETAT_RETIENT,
    ];

    public function __construct(
        private readonly MoteurSorts $sorts,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /**
     * LA grille sur laquelle CE héros marche (voir l'historique dans
     * `ResolveurTour::grilleDeplacement()`, qui lui délègue) :
     *
     *  - `franchitAllies` : « on peut traverser la case d'un autre héros, pas
     *    s'y arrêter » (LR p. 12, doc 16 §5) ;
     *  - Traverser la Pierre : la roche et les portes closes s'ouvrent ;
     *  - MOBILITÉ DE COMBAT (Rogue) / Voile de Brume : les FIGURES cessent de
     *    barrer — elles seules (`MoteurSorts::mobiliteCombatDisponible()`) ;
     *  - TERRAIN GÊNANT ignoré (`MoteurSorts::terrainEntravantIgnore()`) ;
     *  - MOBILIER franchi (Bracers of the Wild, Spiderstep Elixir) : on le
     *    traverse, on ne s'y arrête pas (`MoteurSorts::mobilierFranchi()`) ;
     *  - et, EN DERNIER, le BROUILLARD : ce que la carte du groupe ne montre pas
     *    n'est pas un chemin (voir le docblock de la classe). Posé en dernier
     *    pour que `franchirMobilier()` ne puisse pas lever une case inconnue.
     */
    public function grille(Quete $quete, Personnage $personnage): Grille
    {
        $grille = FabriqueGrille::pour(
            $quete,
            exceptPersonnageId: $personnage->id,
            traverseRoche: $this->sorts->traverseRoche($personnage),
            franchitAllies: true,
        );

        if ($this->sorts->mobiliteCombatDisponible($personnage)) {
            $grille->autoriserFranchissementFigures();
        }

        if ($this->sorts->terrainEntravantIgnore($personnage)) {
            $grille->ignorerTerrainEntravant();
        }

        if ($this->sorts->mobilierFranchi($personnage)) {
            $grille->franchirMobilier();
        }

        $grille->obstruer($this->casesInconnues($quete, $personnage));

        return $grille;
    }

    /**
     * La grille RÉELLE, celle qui sait encore qui occupe quoi : on traverse un
     * compagnon, une figure effacée par la mobilité de combat, un meuble franchi
     * — on ne s'y ARRÊTE pas. Sert aux refus d'arrêt uniquement.
     */
    public function grilleReelle(Quete $quete, Personnage $personnage): Grille
    {
        return FabriqueGrille::pour(
            $quete,
            exceptPersonnageId: $personnage->id,
            traverseRoche: $this->sorts->traverseRoche($personnage),
        );
    }

    /**
     * Cases que la carte du groupe ne montre PAS (`b`) — obstacles de la grille
     * de déplacement. Même source que `EtatGroupe::carte().cases` : ni plus, ni
     * moins que ce que le joueur voit.
     *
     * ⚠ SOUPAPE : un héros qui se tient LUI-MÊME sur une case inconnue n'a pas de
     * brouillard à respecter — il y est, il la voit. Le masquer l'enfermerait
     * (tous ses voisins sont inconnus aussi) et gèlerait le groupe, le pire défaut
     * du projet. Cela ne se produit pas en jeu (le héros n'entre dans une salle que
     * par une porte qui la révèle), mais « ne pas se bloquer soi-même » coûte une
     * ligne, et les cartes de test taillées à la main au milieu d'un donjon non
     * découvert en sont l'exemple.
     *
     * @return list<array{x: int, y: int}>
     */
    private function casesInconnues(Quete $quete, Personnage $personnage): array
    {
        $connues = $this->etatGroupe->casesConnues($quete);

        $position = $quete->etatsPersonnages()->where('personnage_id', $personnage->id)->first(['position_x', 'position_y']);

        if ($position?->position_x !== null && ($connues[$position->position_y][$position->position_x] ?? 'b') === 'b') {
            return [];
        }

        $inconnues = [];

        foreach ($connues as $y => $ligne) {
            foreach ($ligne as $x => $valeur) {
                if ($valeur === 'b') {
                    $inconnues[] = ['x' => (int) $x, 'y' => (int) $y];
                }
            }
        }

        return $inconnues;
    }

    /**
     * Le trajet qu'un héros EMPRUNTE vers une destination — point de passage
     * UNIQUE de l'aperçu, de la résolution et des coûts publiés.
     *
     * ⚠ Un piège DÉTECTÉ se déclenche quand on le foule (René, 2026-09-27). Le
     * joueur ne désigne qu'une destination : laisser Dijkstra choisir une route
     * qui marche sur un piège connu l'aurait fait sauter sur un piège qu'il
     * voyait. On cherche donc d'abord une route qui ÉVITE les pièges détectés, et
     * on la retient si elle est payable ; sinon la route directe, qui les
     * traverse — l'aperçu les signale alors ({@see self::piegesConnusSur()}). La
     * destination elle-même n'est jamais évitée : viser la case d'un piège, c'est
     * choisir d'y marcher.
     *
     * ⛔ Seuls les pièges CONNUS infléchissent la route : un piège caché ne
     * change ni le chemin ni son coût, sans quoi leur forme les trahirait.
     *
     * @return list<array{x: int, y: int}>|null
     */
    public function chemin(Quete $quete, Personnage $personnage, Grille $grille, int $departX, int $departY, int $x, int $y, int $restant): ?array
    {
        $chemin = $grille->chemin($departX, $departY, $x, $y);

        return $this->cheminSansPiegeConnu($quete, $personnage, $grille, $chemin, $departX, $departY, $x, $y, $restant, $this->pieges($quete));
    }

    /**
     * Les entrées de pièges de la carte — lues UNE fois par appelant : `grille` est
     * un attribut `array`, décodé à chaque accès, et `destinations()` interroge
     * cette liste pour chaque case atteignable.
     *
     * @return list<array<string, mixed>>
     */
    private function pieges(Quete $quete): array
    {
        return array_values((array) ($quete->carte?->grille['pieges'] ?? []));
    }

    /**
     * Ce trajet traverse-t-il un piège que le groupe CONNAÎT ? (pas de requête :
     * `piegesConnusSur()` ne sert qu'à NOMMER ce qu'il traverse.)
     *
     * @param  list<array{x: int, y: int}>  $chemin
     * @param  list<array<string, mixed>>  $pieges
     */
    private function croiseUnPiegeConnu(array $chemin, array $pieges): bool
    {
        $connus = [];
        foreach ($pieges as $p) {
            if (in_array($p['etat'] ?? null, self::ETATS_AFFICHES, true)) {
                $connus[((int) $p['x']).','.((int) $p['y'])] = true;
            }
        }

        if ($connus === []) {
            return false;
        }

        foreach ($chemin as $case) {
            if (isset($connus["{$case['x']},{$case['y']}"])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{x: int, y: int}>|null  $chemin  le plus court chemin DIRECT déjà calculé
     * @param  list<array<string, mixed>>  $pieges
     * @return list<array{x: int, y: int}>|null
     */
    private function cheminSansPiegeConnu(Quete $quete, Personnage $personnage, Grille $grille, ?array $chemin, int $departX, int $departY, int $x, int $y, int $restant, array $pieges): ?array
    {
        if ($chemin === null || $chemin === [] || ! $this->croiseUnPiegeConnu($chemin, $pieges)) {
            return $chemin;
        }

        $connus = collect($pieges)
            ->filter(fn (array $p) => in_array($p['etat'] ?? null, MoteurPieges::ETATS_CONNUS_ARMES, true)
                && ! ((int) $p['x'] === $x && (int) $p['y'] === $y))
            ->map(fn (array $p) => ['x' => (int) $p['x'], 'y' => (int) $p['y']])
            ->values()
            ->all();

        $evitement = $this->grille($quete, $personnage);
        $evitement->obstruer($connus);
        $detour = $evitement->chemin($departX, $departY, $x, $y);

        return ($detour !== null && $detour !== [] && $evitement->coutChemin($detour) <= $restant)
            ? $detour
            : $chemin;
    }

    /**
     * Les pièges DÉJÀ CONNUS du groupe que ce trajet traverse — mêmes états que
     * ceux publiés par `EtatGroupe::pieges()` (détecté, désarmé, déclenché), et
     * pas un de plus : le joueur revoit sur son chemin ce que la carte lui
     * montre déjà, il n'apprend rien de neuf.
     *
     * @param  list<array{x: int, y: int}>  $chemin
     * @return list<array{x: int, y: int, nom: string, etat: string}>
     */
    public function piegesConnusSur(Quete $quete, array $chemin): array
    {
        $surLeChemin = [];
        foreach ($chemin as $case) {
            $surLeChemin["{$case['x']},{$case['y']}"] = true;
        }

        $connus = collect($this->pieges($quete))
            ->filter(fn (array $p) => isset($surLeChemin[((int) $p['x']).','.((int) $p['y'])])
                && in_array($p['etat'] ?? null, self::ETATS_AFFICHES, true));

        $noms = Piege::query()
            ->whereIn('id', $connus->pluck('piege_id')->filter()->unique())
            ->pluck('nom', 'id');

        return $connus
            ->map(fn (array $p) => [
                'x' => (int) $p['x'],
                'y' => (int) $p['y'],
                'nom' => $noms[$p['piege_id']] ?? 'Piège',
                'etat' => (string) $p['etat'],
            ])
            ->values()
            ->all();
    }

    /**
     * Pourquoi CE héros ne peut pas S'ARRÊTER sur cette case — `null` si rien ne
     * l'en empêche. TRAVERSER N'EST PAS S'ARRÊTER : une figure, un meuble, une
     * mare ou un brasier, un monstre noyé dans la fumée se traversent selon le
     * héros, jamais ne s'occupent. Les destinations et le résolveur lisent CETTE
     * méthode, jamais deux copies.
     *
     * ⛔ Le meuble se teste AVANT la figure : le faux coffre d'un Dreadshifter
     * déguisé porte, sous lui, un monstre encore caché. Annoncer « une figure »
     * pour CETTE case et « un meuble » pour un coffre ordinaire ferait de
     * l'aperçu un détecteur d'embuscade.
     *
     * @param  array<string, true>|null  $enfumes  cases des monstres enfumés (calculées une fois par `destinations()`)
     */
    public function refusArret(Quete $quete, Grille $reelle, int $x, int $y, ?array $enfumes = null): ?string
    {
        if ($reelle->estMobilier($x, $y)) {
            return 'On traverse un meuble, on ne s\'arrête pas dessus : choisis une autre case.';
        }

        if ($reelle->estOccupeeParFigure($x, $y)) {
            return 'On traverse une figure, on ne s\'arrête pas dessus : cette case est occupée.';
        }

        // MARE, BRASIER (Jungles of Delthrak p. 4) : « Creatures may move through
        // […] but may not end their turn occupying the same space. »
        if ($reelle->arretInterditParTerrain($x, $y)) {
            return 'On traverse une mare ou un brasier, on ne s\'y arrête pas : choisis une autre case.';
        }

        // BOMBE FUMIGÈNE : « move unseen THROUGH the monster's space ». Le monstre
        // enfumé n'occupe plus la grille — mais il est toujours là.
        $enfumes ??= $this->casesEnfumees($quete);

        if (isset($enfumes["{$x},{$y}"])) {
            return 'On traverse la fumée, on ne s\'y arrête pas : cette case est occupée.';
        }

        return null;
    }

    /** @return array<string, true> */
    private function casesEnfumees(Quete $quete): array
    {
        $cases = [];

        foreach ($quete->instancesMonstres()->where('etat', 'actif')->get() as $instance) {
            /** @var InstanceMonstre $instance */
            if ($instance->position_x !== null && $this->sorts->monstreA($instance, MoteurSorts::MONSTRE_ENFUME)) {
                $cases["{$instance->position_x},{$instance->position_y}"] = true;
            }
        }

        return $cases;
    }

    /**
     * LES CASES OÙ CE HÉROS PEUT FINIR SON DÉPLACEMENT, avec ce que chacune lui
     * coûte — `se_deplacer.parametres.destinations`, et la LISTE BLANCHE que le
     * résolveur re-valide.
     *
     * Même grille ({@see self::grille()}), même parcours pondéré
     * (`Grille::casesAtteignables()` → `parcoursPondere()`), même route
     * ({@see self::chemin()}, donc détour autour d'un piège CONNU compris), mêmes
     * refus d'arrêt ({@see self::refusArret()}) que la résolution : rien n'est
     * recalculé ailleurs, ni côté manette, ni côté harnais.
     *
     * Trié par ligne puis colonne : l'ordre ne dépend d'aucun détail du parcours
     * (ni, a fortiori, de ce que la carte cache).
     *
     * @param  int  $budget  points de déplacement RESTANTS (la `portee` de l'option)
     * @return list<array{x: int, y: int, cout: int}>
     */
    public function destinations(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat, int $budget): array
    {
        if ($etat->position_x === null || $etat->position_y === null || $budget < 1 || $quete->carte === null) {
            return [];
        }

        $departX = (int) $etat->position_x;
        $departY = (int) $etat->position_y;

        $grille = $this->grille($quete, $personnage);
        $reelle = $this->grilleReelle($quete, $personnage);
        $enfumes = $this->casesEnfumees($quete);
        $pieges = $this->pieges($quete);

        $destinations = [];

        foreach ($grille->casesAtteignables($departX, $departY, $budget) as $cle => $chemin) {
            [$x, $y] = array_map(intval(...), explode(',', $cle));

            if ($this->refusArret($quete, $reelle, $x, $y, $enfumes) !== null) {
                continue;
            }

            // Le chemin que le héros PRENDRA (détour autour d'un piège connu), pas
            // seulement le moins cher : c'est son coût que l'aperçu annoncera.
            $route = $this->cheminSansPiegeConnu($quete, $personnage, $grille, $chemin, $departX, $departY, $x, $y, $budget, $pieges);

            if ($route === null || $route === []) {
                continue;
            }

            $destinations[] = ['x' => $x, 'y' => $y, 'cout' => $grille->coutChemin($route)];
        }

        usort($destinations, fn (array $a, array $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);

        return $destinations;
    }

    /**
     * Cette case figure-t-elle parmi les destinations ? — la garde finale du
     * résolveur (« une liste portée par une option EST la liste blanche »).
     *
     * @param  list<array{x: int, y: int, cout: int}>  $destinations
     */
    public static function figureParmi(array $destinations, int $x, int $y): bool
    {
        foreach ($destinations as $d) {
            if ($d['x'] === $x && $d['y'] === $y) {
                return true;
            }
        }

        return false;
    }
}
