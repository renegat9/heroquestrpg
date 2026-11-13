<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use App\Partie\PilotageHeros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Confier un héros à un autre joueur du groupe, ou le lui reprendre (MANETTE À PLUSIEURS HÉROS, lot M1,
 * `docs/plan-mode-classique.md` §5). Toute la règle vit dans App\Partie\PilotageHeros ; ce contrôleur ne fait que
 * résoudre le héros, lire le demandeur, puis rediffuser l'état du groupe.
 */
class PiloteController extends Controller
{
    public function __construct(
        private readonly PilotageHeros $pilotage,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /** PUT /api/groupes/{identifiant}/pilote {personnage_id, pilote_joueur_id|null} */
    public function confier(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            // Présent, mais `null` permis : « rendre le héros à son propriétaire ».
            'pilote_joueur_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        $heros = Personnage::query()->find($donnees['personnage_id']);

        if ($heros === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe.',
            ]);
        }

        $this->pilotage->confier(
            $groupe,
            $heros,
            $donnees['pilote_joueur_id'] === null ? null : (int) $donnees['pilote_joueur_id'],
            (int) $joueur->id,
        );

        $groupe = $groupe->fresh();

        EtatGroupeDiffuse::dispatch($groupe, $this->etatGroupe->payload($groupe));

        return response()->json([
            'personnage_id' => (int) $heros->id,
            'pilote_joueur_id' => $groupe->piloteDe($heros),
        ]);
    }
}
