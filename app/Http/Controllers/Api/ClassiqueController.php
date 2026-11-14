<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Personnage;
use App\Partie\Classique\GroupesClassiques;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * MODE CLASSIQUE — ouvrir un groupe sur une campagne construite (`docs/contrat-api.md` « Mode classique »). Contrôleur
 * mince : la règle vit dans `App\Partie\Classique\GroupesClassiques`.
 */
class ClassiqueController extends Controller
{
    /** POST /api/classique {nom, campagne, personnage_id} */
    public function ouvrir(Request $request, GroupesClassiques $groupes): JsonResponse
    {
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'campagne' => ['required', 'string', 'max:100'],
            'personnage_id' => ['required', 'integer'],
        ]);

        // Le fondateur est un héros que ce joueur POSSÈDE, comme pour toute création de groupe ; jamais une doublure ni
        // un héros d'arène.
        $fondateur = Personnage::query()
            ->horsDoublures()
            ->where('id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($fondateur === null) {
            throw ValidationException::withMessages(['personnage_id' => 'Ce personnage n\'existe pas ou ne vous appartient pas.']);
        }
        if ($fondateur->estHerosArene()) {
            throw ValidationException::withMessages(['personnage_id' => "« {$fondateur->nom} » est un héros d'arène : il n'entre jamais en campagne."]);
        }

        $groupe = $groupes->ouvrir($donnees['nom'], $donnees['campagne'], $fondateur);

        return response()->json([
            'groupe' => [
                'id' => $groupe->id,
                'identifiant' => $groupe->identifiant,
                'nom' => $groupe->nom,
                'mode' => $groupe->mode,
                'phase' => $groupe->phase,
                'campagne' => $groupe->campagneConstruite->slug,
                'rang_quete' => (int) $groupe->rang_quete,
                'nb_quetes' => (int) $groupe->nb_quetes_total,
            ],
        ], 201);
    }
}
