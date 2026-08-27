<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Competence;
use App\Models\Personnage;

/**
 * Les CAPACITÉS INNÉES — les capacités de carte des 8 classes d'extension
 * (2026-08-12).
 *
 * Elles vivent dans `competences` avec `innee = true` : acquises d'emblée,
 * sans coûter de point, parce qu'au plateau la carte vient avec la figurine.
 *
 * ⚠ Depuis le 2026-08-23 cette classe ne fait plus QUE l'attachement : la
 * lecture — `a()`, `noeud()`, `valeur()`, `disponible()`, `consommer()` — est
 * montée dans `Talents`, dont elle hérite. Une capacité de carte n'est qu'une
 * compétence parmi d'autres, et le docblock d'origine le disait déjà : « un
 * nœud d'arbre pourrait un jour porter la même mécanique ». C'est fait — le
 * barbare achète `attaque_balayee`, le moine `franchit_figures`. Deux
 * vocabulaires pour un seul pivot, c'était la dérive à retirer : les mécaniques
 * sont désormais toutes déclarées dans `App\Engine\MotsClesTalent`.
 *
 * ⚠ La plupart de ces cartes portent « **once per quest** ». Le compteur vit
 * dans `etat_personnage_quete.capacites_utilisees` (une liste de noms) plutôt
 * que dans 24 colonnes booléennes, et il se remet à zéro tout seul : l'état
 * d'un héros est recréé à chaque quête.
 */
class CapacitesInnees extends Talents
{
    /**
     * Attache à un héros neuf toutes les capacités de carte de sa classe.
     *
     * ⚠ Point de passage UNIQUE, et il a fallu un test pour s'en rendre compte :
     * la logique vivait dans `GroupeController`, si bien qu'un héros créé
     * autrement — par le helper de test, demain par un seeder ou une reprise —
     * naissait sans ses capacités. Les deux chemins passent ici désormais.
     */
    public function attacherA(Personnage $personnage): void
    {
        $innees = Competence::where('classe', $personnage->classe)
            ->where('innee', true)
            ->pluck('id');

        if ($innees->isNotEmpty()) {
            $personnage->competences()->syncWithoutDetaching($innees->all());
        }
    }
}
