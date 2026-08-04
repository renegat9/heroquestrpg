<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Quete;
use Illuminate\Validation\ValidationException;

/**
 * Fabrique la grille tactique OCCUPÉE d'une quête (carte + figures présentes) —
 * source de vérité UNIQUE de l'occupation, partagée par le déplacement, le
 * ciblage ET la ligne de vue (ResolveurTour, MoteurSorts, MenuMoteur). Règles
 * d'occupation (doc 03) : héros DEBOUT (un tombé s'enjambe, C4), monstres
 * `actif` avec leur emprise (3.9), alliés `actif` (3.5), et depuis doc 17 le
 * mobilier `bloquant` de la carte (table, coffre, armoire…) avec SA propre
 * emprise l×h — même mécanisme `cellulesEmprise()` que les grandes figurines,
 * réutilisé plutôt que dupliqué. Les `except*` retirent une figure du
 * plateau (la sienne, pour se déplacer / voir depuis sa propre case) ; le
 * mobilier, lui, n'a pas d'`except` : il ne bouge jamais en cours de quête.
 */
final class FabriqueGrille
{
    public static function pour(
        Quete $quete,
        ?int $exceptPersonnageId = null,
        ?int $exceptInstanceId = null,
        ?int $exceptMercenaireId = null,
    ): Grille {
        $carte = $quete->carte;

        if ($carte === null) {
            throw ValidationException::withMessages(['groupe' => 'La quête en cours n\'a pas de carte assemblée.']);
        }

        $grille = Grille::depuisCarte($carte);

        $occupees = [];

        foreach ($quete->etatsPersonnages()->get() as $etat) {
            // Un héros TOMBÉ (à terre) ne bloque ni le passage ni la ligne de vue :
            // il gît au sol, on l'enjambe. Il reste secourable (resoudreRelever) tant
            // qu'aucune AUTRE figure ne se tient sur sa case.
            if ($etat->personnage_id !== $exceptPersonnageId && $etat->position_x !== null && ! $etat->tombe) {
                $occupees[] = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
            }
        }

        foreach ($quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get() as $instance) {
            if ($instance->id !== $exceptInstanceId && $instance->position_x !== null) {
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
        // au même niveau que `leviers`/`pieges`. SEULE boucle d'occupation du
        // mobilier dans tout le moteur — n'en ouvrir aucune autre ailleurs
        // (déplacement, ciblage, ligne de vue divergeraient sinon). Un meuble
        // non `bloquant` (aucun aujourd'hui, cf. MobilierSeeder) n'occupe rien.
        $mobilier = (array) ($carte->grille['mobilier'] ?? []);
        if ($mobilier !== []) {
            $idsBloquants = Mobilier::query()
                ->whereIn('id', array_values(array_unique(array_column($mobilier, 'mobilier_id'))))
                ->where('bloquant', true)
                ->pluck('id')
                ->flip();

            foreach ($mobilier as $meuble) {
                if (! $idsBloquants->has($meuble['mobilier_id'] ?? null)) {
                    continue;
                }
                $occupees = array_merge($occupees, $grille->cellulesEmprise(
                    (int) $meuble['x'], (int) $meuble['y'], (int) $meuble['l'], (int) $meuble['h'],
                ));
            }
        }

        $grille->occuper($occupees);

        return $grille;
    }
}
