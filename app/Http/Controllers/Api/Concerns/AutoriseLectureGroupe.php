<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Groupe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Autorisation de LECTURE d'un groupe : joueur membre (héros actif) OU session
 * de table de ce groupe (narrateur sans compte) — même règle que GET /etat
 * (docs/contrat-api.md §Autorisations). Utilisé par les rattrapages de phase
 * (marché / vote / clôture) afin que l'écran de table puisse se resynchroniser
 * après un rechargement, sans compte. Les écritures restent réservées aux
 * joueurs (auth:joueur).
 */
trait AutoriseLectureGroupe
{
    protected function groupeLisible(Request $request, string $identifiant): Groupe
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();

        if (! $this->peutLireGroupe($request, $groupe)) {
            abort(403, 'Accès refusé : vous n\'êtes ni membre ni la table de ce groupe.');
        }

        return $groupe;
    }

    /**
     * LE prédicat de lecture d'un groupe — table de ce groupe, joueur membre par un héros actif, ou (lot A9a) joueur
     * d'un camp Monster vs Monster. Un seul point de passage : `GroupeController::etat()` le lit aussi.
     */
    protected function peutLireGroupe(Request $request, Groupe $groupe): bool
    {
        if ($request->session()->get('table_groupe') === $groupe->identifiant) {
            return true;
        }

        $joueur = Auth::guard('joueur')->user();
        if ($joueur === null) {
            return false;
        }

        if ($groupe->personnages()->wherePivot('actif', true)->where('joueur_id', $joueur->id)->exists()) {
            return true;
        }

        return $groupe->estMonstresContreMonstres()
            && app(\App\Partie\Arene\MonstresContreMonstres::class)->estMembre($groupe, (int) $joueur->id);
    }
}
