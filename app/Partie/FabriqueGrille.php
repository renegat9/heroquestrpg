<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\GroupeMercenaire;
use App\Models\Mobilier;
use App\Models\Quete;
use Illuminate\Validation\ValidationException;

/**
 * Fabrique la grille tactique OCCUPÉE d'une quête (carte + figures présentes) —
 * source de vérité UNIQUE de l'occupation ET de l'opacité, partagée par le
 * déplacement, le ciblage ET la ligne de vue (ResolveurTour, MoteurSorts,
 * MenuMoteur). Règles d'occupation (doc 03) : héros DEBOUT (un tombé
 * s'enjambe, C4), monstres `actif` avec leur emprise (3.9), alliés `actif`
 * (3.5), et depuis doc 17 le mobilier de la carte (table, coffre, armoire…)
 * avec SA propre emprise l×h — même mécanisme `cellulesEmprise()` que les
 * grandes figurines, réutilisé plutôt que dupliqué. Les `except*` retirent
 * une figure du plateau (la sienne, pour se déplacer / voir depuis sa propre
 * case) ; le mobilier, lui, n'a pas d'`except` : il ne bouge jamais en cours
 * de quête.
 *
 * Le mobilier porte DEUX drapeaux INDÉPENDANTS (doc 17, portage) : une table
 * bloque le mouvement (`bloque_mouvement` → `Grille::obstruer()`) mais pas la
 * vue, une bibliothèque bloque les deux (`bloque_vue` → `Grille::occulter()`,
 * EN PLUS de `obstruer()`). Le mobilier ne passe JAMAIS par `Grille::occuper()`
 * (réservé aux FIGURES — héros, monstres, alliés) : un meuble bloquant le
 * mouvement mais pas la vue ne doit jamais participer au test
 * `figuresBloquent` de `ligneDeVue()`, sans quoi une simple table arrêterait
 * les flèches — exactement le bug corrigé par cette séparation.
 */
final class FabriqueGrille
{
    public static function pour(
        Quete $quete,
        ?int $exceptPersonnageId = null,
        ?int $exceptInstanceId = null,
        ?int $exceptMercenaireId = null,
        bool $traverseRoche = false,
    ): Grille {
        $carte = $quete->carte;

        if ($carte === null) {
            throw ValidationException::withMessages(['groupe' => 'La quête en cours n\'a pas de carte assemblée.']);
        }

        $grille = Grille::depuisCarte($carte);

        // Traverser la Pierre : le héros qui bouge ignore la roche et les
        // portes closes. Posé AVANT les figures et le mobilier, qui eux
        // continuent de bloquer — on ne traverse pas un compagnon.
        if ($traverseRoche) {
            $grille->autoriserLaRoche();
        }

        $occupees = [];
        $obstacles = [];
        $opaques = [];

        foreach ($quete->etatsPersonnages()->get() as $etat) {
            // Un héros TOMBÉ (à terre) ne bloque ni le passage ni la ligne de vue :
            // il gît au sol, on l'enjambe. Il reste secourable (resoudreRelever) tant
            // qu'aucune AUTRE figure ne se tient sur sa case.
            if ($etat->personnage_id !== $exceptPersonnageId && $etat->position_x !== null && ! $etat->tombe) {
                $occupees[] = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
            }
        }

        foreach ($quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get() as $instance) {
            // BOMBE FUMIGÈNE — « all heroes move unseen through the monster's
            // space » : la créature noyée dans la fumée cesse d'occuper sa case.
            // Le retrait vaut du même coup pour le MOUVEMENT et pour la LIGNE
            // DE VUE, parce que `$occupees` est la seule et unique liste que
            // `figuresBloquent` consulte — c'est exactement ce que dit la carte,
            // et il n'y a rien d'autre à câbler.
            $enfume = (bool) ($instance->habillage['conditions'][MoteurSorts::MONSTRE_ENFUME] ?? false);

            if ($instance->id !== $exceptInstanceId && $instance->position_x !== null && ! $enfume) {
                // 3.9 : une grande figurine occupe TOUTE son emprise (1×1 → une
                // seule case, identique au comportement antérieur).
                $e = $instance->monstre->emprise();
                $occupees = array_merge($occupees, $grille->cellulesEmprise(
                    (int) $instance->position_x, (int) $instance->position_y, $e['l'], $e['h'],
                ));
            }
        }

        // Alliés (3.5) : figures sur le plateau → cases infranchissables.
        foreach (GroupeMercenaire::where('groupe_id', $quete->groupe_id)->where('etat', 'actif')->get() as $allie) {
            if ($allie->id !== $exceptMercenaireId && $allie->position_x !== null) {
                $occupees[] = ['x' => (int) $allie->position_x, 'y' => (int) $allie->position_y];
            }
        }

        // Mobilier (doc 17) : troisième couche de la carte (AssembleurCarte),
        // au même niveau que `leviers`/`pieges`. SEULE boucle d'occupation ET
        // d'opacité du mobilier dans tout le moteur — n'en ouvrir aucune autre
        // ailleurs (déplacement, ciblage, ligne de vue divergeraient sinon).
        // `bloque_mouvement` et `bloque_vue` sont lus INDÉPENDAMMENT, dans
        // deux jeux de cases DISTINCTS de `$occupees` (réservé aux figures) :
        // un meuble peut bloquer le mouvement sans la vue (table → `obstacles`
        // seul), les deux (bibliothèque → `obstacles` ET `opaques`), ou ni
        // l'un ni l'autre (aucun cas aujourd'hui, cf. MobilierSeeder, mais le
        // catalogue le permet).
        $mobilier = (array) ($carte->grille['mobilier'] ?? []);
        if ($mobilier !== []) {
            $types = Mobilier::query()
                ->whereIn('id', array_values(array_unique(array_column($mobilier, 'mobilier_id'))))
                ->get(['id', 'bloque_mouvement', 'bloque_vue'])
                ->keyBy('id');

            foreach ($mobilier as $meuble) {
                $type = $types->get($meuble['mobilier_id'] ?? null);
                // ⚠ Une pièce MISE EN PIÈCES (jet de Body, 2026-08-24) cesse de
                // bloquer le mouvement ET la vue — et il suffit de l'écarter
                // ICI, précisément parce que c'est la seule boucle du mobilier
                // de tout le moteur. C'est tout l'intérêt de n'en avoir qu'une.
                if ($type === null || MoteurMobilier::estDetruite($meuble)) {
                    continue;
                }

                $cellules = $grille->cellulesEmprise(
                    (int) $meuble['x'], (int) $meuble['y'], (int) $meuble['l'], (int) $meuble['h'],
                );

                if ($type->bloque_mouvement) {
                    $obstacles = array_merge($obstacles, $cellules);
                }
                if ($type->bloque_vue) {
                    $opaques = array_merge($opaques, $cellules);
                }
            }
        }

        $grille->occuper($occupees);
        $grille->obstruer($obstacles);
        $grille->occulter($opaques);

        return $grille;
    }
}
