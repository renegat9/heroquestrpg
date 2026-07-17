<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use Illuminate\Validation\ValidationException;

/**
 * Fabrique la grille tactique OCCUPÉE d'une quête (carte + figures présentes) —
 * source de vérité UNIQUE de l'occupation, partagée par le déplacement et le
 * ciblage (ResolveurTour, MoteurSorts, MenuMoteur). Règles d'occupation
 * (doc 03) : héros DEBOUT (un tombé s'enjambe, C4), monstres `actif` avec leur
 * emprise (3.9), alliés `actif` (3.5). Les `except*` retirent une figure du
 * plateau (la sienne, pour se déplacer / voir depuis sa propre case).
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

        $grille->occuper($occupees);

        return $grille;
    }
}
