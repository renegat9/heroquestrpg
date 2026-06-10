<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\NiveauMonte;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;

/**
 * Montée de niveau par jalons (doc 01 §5, contrat docs/contrat-api.md) :
 * à la fin VICTORIEUSE d'une quête `sous_boss` ou `boss_final`, chaque héros
 * actif gagne +1 niveau ; à chaque niveau PAIR, +1 PV max (Body pour
 * barbare/nain, Mind pour elfe/magicien — départ playtest), le PV courant
 * suit. Les points de compétence ne sont JAMAIS stockés :
 * `points_competence = (niveau − 1) − nb de nœuds acquis` (dérivé).
 *
 * Broadcast `.niveau.monte` (gains lisibles) émis AVANT le `.groupe.etat`
 * de fin de quête ; journal `systeme`.
 */
final class MonteeNiveau
{
    /** Types de jalon qui déclenchent la montée (doc 06 §4). */
    public const JALONS = ['sous_boss', 'boss_final'];

    /**
     * @return array{personnages: list<array<string, mixed>>}|null null si la quête n'est pas un jalon
     */
    public function appliquer(Groupe $groupe, Quete $quete): ?array
    {
        if (! in_array($quete->type_jalon, self::JALONS, true)) {
            return null;
        }

        $personnages = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get()
            ->map(fn (Personnage $p) => $this->monter($p))
            ->values()
            ->all();

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'niveau_monte',
            'quete_id' => $quete->id,
            'type_jalon' => $quete->type_jalon,
            'personnages' => $personnages,
        ]);

        $resultat = ['personnages' => $personnages];

        // Avant le `.groupe.etat` final (diffusé par ResolveurTour::resoudre).
        broadcast(new NiveauMonte($groupe, $resultat));

        return $resultat;
    }

    /**
     * @return array<string, mixed> ligne du payload `.niveau.monte` du contrat
     */
    private function monter(Personnage $personnage): array
    {
        $niveau = (int) $personnage->niveau + 1;
        $attributs = ['niveau' => $niveau];
        $gains = ['+1 niveau', '+1 point de compétence'];

        if ($niveau % 2 === 0) {
            if (in_array($personnage->classe, ['barbare', 'nain'], true)) {
                $attributs['pv_body_max'] = (int) $personnage->pv_body_max + 1;
                $attributs['pv_body'] = (int) $personnage->pv_body + 1;
                $gains[] = '+1 PV de Body maximum';
            } else {
                $attributs['pv_mind_max'] = (int) $personnage->pv_mind_max + 1;
                $attributs['pv_mind'] = (int) $personnage->pv_mind + 1;
                $gains[] = '+1 PV de Mind maximum';
            }
        }

        $personnage->update($attributs);

        return [
            'id' => $personnage->id,
            'nom' => $personnage->nom,
            'niveau' => $niveau,
            'points_competence' => $personnage->pointsCompetence(),
            'gains' => $gains,
        ];
    }
}
