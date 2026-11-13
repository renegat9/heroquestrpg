<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\Personnage;
use App\Support\Journal;
use Illuminate\Validation\ValidationException;

/**
 * MANETTE À PLUSIEURS HÉROS, lot M1 (`docs/plan-mode-classique.md` §5) — CONFIER un héros à un autre joueur du groupe.
 *
 * Un héros a un PROPRIÉTAIRE (`personnages.joueur_id`, son compte) et, dans un groupe, un PILOTE
 * (`groupe_personnages.pilote_joueur_id`, `null` = le propriétaire). Ce service est la seule écriture de cette colonne
 * hors de la sortie d'un joueur. Les LECTURES passent par `Groupe::piloteDe()` / `Groupe::herosPilotesPar()`, et
 * l'appartenance au groupe par {@see self::estMembre()}.
 */
class PilotageHeros
{
    /**
     * Un joueur est MEMBRE du groupe s'il PILOTE un héros actif, OU s'il POSSÈDE un héros actif — même confié à un autre.
     * C'est le prédicat d'appartenance du marché, des votes, de la clôture, des snapshots et de la lecture de l'état.
     */
    public function estMembre(Groupe $groupe, int $joueurId): bool
    {
        return $groupe->herosPilotesPar($joueurId)->exists()
            || $groupe->personnages()
                ->wherePivot('actif', true)
                ->where('personnages.joueur_id', $joueurId)
                ->exists();
    }

    /**
     * Confie `$heros` à `$piloteJoueurId` (`null` ou le propriétaire : rendu à son propriétaire). Refus en français, en
     * `ValidationException`. Le demandeur doit être le propriétaire du héros OU son pilote actuel.
     */
    public function confier(Groupe $groupe, Personnage $heros, ?int $piloteJoueurId, int $demandeurJoueurId): void
    {
        $groupe->exigerCampagne(\App\Partie\Arene\EtapesCampagne::PILOTAGE);

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'phase' => 'On ne confie un héros qu\'au hub, entre deux quêtes.',
            ]);
        }

        $actif = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $heros->id)
            ->exists();

        if (! $actif) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe.',
            ]);
        }

        $proprietaire = (int) $heros->joueur_id;
        $pilotePrecedent = $groupe->piloteDe($heros);

        if ($demandeurJoueurId !== $proprietaire && $demandeurJoueurId !== $pilotePrecedent) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Seul le propriétaire de ce héros, ou le joueur qui le pilote aujourd\'hui, peut le confier.',
            ]);
        }

        // Le propriétaire lui-même n'est pas un « pilote confié » : la colonne reprend `null`.
        $nouveau = $piloteJoueurId === null || $piloteJoueurId === $proprietaire ? null : $piloteJoueurId;

        if ($nouveau !== null && ! $this->estMembre($groupe, $nouveau)) {
            throw ValidationException::withMessages([
                'pilote_joueur_id' => 'Ce joueur n\'a aucun héros actif dans ce groupe : on ne lui confie pas un héros.',
            ]);
        }

        $groupe->personnages()->updateExistingPivot($heros->id, ['pilote_joueur_id' => $nouveau]);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'pilote_change',
            'personnage_id' => $heros->id,
            'personnage' => $heros->nom,
            'de_joueur_id' => $pilotePrecedent,
            'vers_joueur_id' => $nouveau ?? $proprietaire,
            'par_joueur_id' => $demandeurJoueurId,
        ], ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);
    }
}
