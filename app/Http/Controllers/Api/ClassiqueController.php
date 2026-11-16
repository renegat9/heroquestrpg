<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\EtatGroupeDiffuse;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\Classique\ChoixCampagne;
use App\Partie\Classique\GroupesClassiques;
use App\Partie\EtatGroupe;
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
            // Lot H1 : `story` (défaut) ou `heroic` — `GroupesClassiques::ouvrir()` le revalide.
            'mode_mort' => ['sometimes', 'string'],
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

        $groupe = $groupes->ouvrir($donnees['nom'], $donnees['campagne'], $fondateur, $donnees['mode_mort'] ?? \App\Models\Groupe::MORT_STORY);

        return response()->json([
            'groupe' => [
                'id' => $groupe->id,
                'identifiant' => $groupe->identifiant,
                'nom' => $groupe->nom,
                'mode' => $groupe->mode,
                'mode_mort' => $groupe->mode_mort,
                'phase' => $groupe->phase,
                'campagne' => $groupe->campagneConstruite->slug,
                'rang_quete' => (int) $groupe->rang_quete,
                'nb_quetes' => (int) $groupe->nb_quetes_total,
            ],
        ], 201);
    }

    /** GET /api/groupes/{identifiant}/campagnes — chaque campagne construite, avec la DÉCISION pour ce groupe. */
    public function campagnes(string $identifiant, ChoixCampagne $choix): JsonResponse
    {
        $groupe = $this->groupeDuJoueur($identifiant);

        return response()->json($choix->publier($groupe));
    }

    /** PUT /api/groupes/{identifiant}/campagne {campagne} — le groupe repart à la quête 1 de cette campagne. */
    public function choisir(Request $request, string $identifiant, ChoixCampagne $choix, EtatGroupe $etatGroupe): JsonResponse
    {
        $groupe = $this->groupeDuJoueur($identifiant);
        $donnees = $request->validate(['campagne' => ['required', 'string', 'max:100']]);

        $groupe = $choix->choisir($groupe, $donnees['campagne']);
        broadcast(new EtatGroupeDiffuse($groupe, $etatGroupe->payload($groupe)));

        return response()->json([
            'groupe' => [
                'campagne' => $groupe->campagneConstruite->slug,
                'rang_quete' => (int) $groupe->rang_quete,
                'nb_quetes' => (int) $groupe->nb_quetes_total,
            ],
        ]);
    }

    /** Le groupe, si ce joueur y pilote un héros actif (403 sinon). */
    private function groupeDuJoueur(string $identifiant): Groupe
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();
        abort_unless($groupe->herosPilotesPar((int) $joueur->id)->exists(), 403, 'Vous ne pilotez aucun héros de ce groupe.');

        return $groupe;
    }
}
