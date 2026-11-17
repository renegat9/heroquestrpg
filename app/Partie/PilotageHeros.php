<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\Personnage;
use App\Support\Journal;
use Illuminate\Support\Collection;
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
     * LA règle d'appartenance, écrite une fois : les joueurs qui PILOTENT ou POSSÈDENT un héros actif (ids uniques).
     * Un pilote est forcément membre (`confier()` le refuse sinon) et un propriétaire l'est toujours.
     *
     * @return Collection<int, int>
     */
    private function idsMembres(Groupe $groupe): Collection
    {
        return $groupe->personnages()->wherePivot('actif', true)->get()
            ->flatMap(fn (Personnage $p) => [Groupe::piloteDuPivot($p), (int) $p->joueur_id])
            ->unique()
            ->values();
    }

    /**
     * Un joueur est MEMBRE du groupe s'il PILOTE un héros actif, OU s'il POSSÈDE un héros actif — même confié à un autre.
     * C'est le prédicat d'appartenance du marché, des votes, de la clôture, des snapshots et de la lecture de l'état.
     */
    public function estMembre(Groupe $groupe, int $joueurId): bool
    {
        return $this->idsMembres($groupe)->contains($joueurId);
    }

    /**
     * LES JOUEURS À QUI UN HÉROS DE CE GROUPE PEUT ÊTRE CONFIÉ : exactement ceux que `confier()` accepte (il applique
     * {@see self::estMembre()}, le même ensemble). Publié au hub (`groupe.pilotes_possibles`) : l'écran ne re-dérive pas
     * la règle. Le propriétaire y figure aussi — il est membre, et « rendre » à soi-même est permis.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Joueur>
     */
    public function pilotesPossibles(Groupe $groupe): \Illuminate\Support\Collection
    {
        return \App\Models\Joueur::whereIn('id', $this->idsMembres($groupe))->orderBy('id')->get();
    }

    /**
     * LES JOUEURS À LA TABLE : ceux qui PILOTENT au moins un héros actif. Ce sont eux qui DÉCIDENT — ils votent et font
     * le marché. Au plateau, le héros d'un absent est joué par un présent, et c'est le présent qui décide pour lui : un
     * absent dont le héros est confié n'a donc ni bulletin à rendre ni panier à confirmer, et ne bloque personne.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Joueur>
     */
    public function joueursALaTable(Groupe $groupe): \Illuminate\Support\Collection
    {
        $ids = $groupe->personnages()->wherePivot('actif', true)->get()
            ->map(fn (Personnage $p) => Groupe::piloteDuPivot($p))
            ->unique();

        return \App\Models\Joueur::whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * LES PROPRIÉTAIRES : ceux qui POSSÈDENT au moins un héros actif, même confié. Ce sont eux qui ont une PART — du pot
     * commun au départ, du groupe tant qu'il existe. On quitte un groupe avec SES héros, on en est retiré avec SES héros,
     * et un groupe sans propriétaire est vide. Piloter le héros d'un autre ne donne aucune part.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Joueur>
     */
    public function proprietaires(Groupe $groupe): \Illuminate\Support\Collection
    {
        $ids = $groupe->personnages()->wherePivot('actif', true)->pluck('joueur_id')->unique();

        return \App\Models\Joueur::whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * Confie `$heros` à `$piloteJoueurId` (`null` ou le propriétaire : rendu à son propriétaire). Refus en français, en
     * `ValidationException`. Le demandeur doit être le propriétaire du héros OU son pilote actuel. Le nouveau pilote doit
     * figurer dans {@see self::pilotesPossibles()} — la même règle (`estMembre()`), publiée au hub.
     */
    public function confier(Groupe $groupe, Personnage $heros, ?int $piloteJoueurId, int $demandeurJoueurId): void
    {
        $groupe->exigerCampagne(\App\Partie\Arene\EtapesCampagne::PILOTAGE);

        // Confier un héros : mode CLASSIQUE seulement, « pour le moment » (René, 2026-11-17 : la manette à plusieurs
        // héros est réservée au classique ; la branche ne change rien au RPG).
        if (! $groupe->estClassique()) {
            throw ValidationException::withMessages([
                'groupe' => 'Confier un héros n\'est ouvert qu\'aux groupes du mode classique, pour le moment.',
            ]);
        }

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
