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

        // Session de table de ce groupe ?
        if ($request->session()->get('table_groupe') === $groupe->identifiant) {
            return $groupe;
        }

        // Joueur membre (au moins un héros actif) ?
        $joueur = Auth::guard('joueur')->user();
        if ($joueur !== null
            && $groupe->personnages()
                ->wherePivot('actif', true)
                ->where('joueur_id', $joueur->id)
                ->exists()) {
            return $groupe;
        }

        abort(403, 'Accès refusé : vous n\'êtes ni membre ni la table de ce groupe.');
    }
}
