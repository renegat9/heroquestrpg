<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use Illuminate\Support\Collection;

/**
 * À QUI est-ce le tour ? — point de passage unique.
 *
 * Le premier héros de l'ordre d'initiative FIGÉ (C1) qui soit encore debout et
 * n'ait pas joué ce round.
 *
 * ⚠ Cette lecture vivait en DEUX exemplaires, `ChoixController::estSonTour()`
 * et `ResolveurTour::verifierInitiative()`, et le docblock du contrôleur
 * assumait la copie : « les deux doivent simplement dire non au même moment ».
 * Le 2026-09-16, le jet de déplacement a dû apprendre la même chose — il se
 * lançait pour TOUS les héros dès que leur menu était calculé, donc au début du
 * round, alors que René voulait le voir « au début d'un tour de joueur ». Une
 * troisième copie d'une règle assez simple pour que personne ne remarque l'une
 * dériver, c'est le défaut le plus répété du projet : la règle est ici.
 */
final class OrdreDuTour
{
    /**
     * L'id du héros dont c'est le tour, ou `null` si plus aucun n'attend (phase
     * des monstres, tous tombés, hors quête).
     *
     * @param  Collection<int, EtatPersonnageQuete>|null  $etats  déjà chargés par l'appelant, sinon relus
     */
    public function herosActifId(Groupe $groupe, ?Collection $etats = null): ?int
    {
        $etats ??= $groupe->queteCourante?->etatsPersonnages()->get();

        if ($etats === null) {
            return null;
        }

        $ordre = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->pluck('personnages.id');

        foreach ($ordre as $id) {
            $etat = $etats->firstWhere('personnage_id', $id);

            if ($etat === null || $etat->a_joue || $etat->tombe) {
                continue;
            }

            return (int) $id;
        }

        return null;
    }

    public function estSonTour(Groupe $groupe, int $personnageId, ?Collection $etats = null): bool
    {
        return $this->herosActifId($groupe, $etats) === $personnageId;
    }
}
