<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AutoriseLectureGroupe;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Joueur;
use App\Partie\ClotureCampagne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Clôture de campagne (doc 05 §6, contrat docs/contrat-api.md).
 *
 * Toute la mécanique vit dans App\Partie\ClotureCampagne (fenêtre en cache,
 * finalisation en job quand TOUS ont confirmé) ; le contrôleur valide
 * l'appartenance au groupe et la forme des corps de requête.
 */
class ClotureController extends Controller
{
    use AutoriseLectureGroupe;

    public function __construct(private readonly ClotureCampagne $cloture) {}

    /**
     * POST /api/groupes/{identifiant}/cloture — ouvre la fenêtre (fin
     * décidée au hub, ou `abandon` après une quête échouée — TPK).
     */
    public function ouvrir(Request $request, string $identifiant): JsonResponse
    {
        // Le bouton « Clôturer » est sur l'écran de TABLE (narrateur sans
        // compte) : l'ouverture est donc autorisée table-OU-membre. Aucune
        // donnée par joueur n'est requise (l'issue est dérivée de l'état) et
        // RIEN n'est appliqué avant la confirmation de TOUS les joueurs.
        $groupe = $this->groupeLisible($request, $identifiant);

        $donnees = $request->validate([
            'abandon' => ['sometimes', 'boolean'],
        ]);

        return response()->json(
            $this->cloture->ouvrir($groupe, (bool) ($donnees['abandon'] ?? false)),
            201,
        );
    }

    /** GET /api/groupes/{identifiant}/cloture — EtatCloture courant. */
    public function etat(Request $request, string $identifiant): JsonResponse
    {
        // Lecture seule : membre OU table (rattrapage de l'écran de table).
        $groupe = $this->groupeLisible($request, $identifiant);

        $etat = $this->cloture->etat($groupe);

        if ($etat === null) {
            abort(404, 'Aucune fenêtre de clôture ouverte pour ce groupe.');
        }

        return response()->json($etat);
    }

    /**
     * PUT /api/groupes/{identifiant}/cloture/repartition — réassigne un
     * équipement à un héros actif du groupe (annule les confirmations).
     */
    public function repartition(Request $request, string $identifiant): JsonResponse
    {
        [$groupe] = $this->groupeEtJoueurMembre($identifiant);

        $donnees = $request->validate([
            'inventaire_id' => ['required', 'integer', 'min:1'],
            'personnage_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->cloture->reassigner(
            $groupe,
            (int) $donnees['inventaire_id'],
            (int) $donnees['personnage_id'],
        ));
    }

    /**
     * POST /api/groupes/{identifiant}/cloture/confirmation — confirme ;
     * quand TOUS ont confirmé, la finalisation part en job (CloturerCampagne).
     */
    public function confirmer(string $identifiant): JsonResponse
    {
        [$groupe, $joueur] = $this->groupeEtJoueurMembre($identifiant);

        return response()->json($this->cloture->confirmer($groupe, $joueur));
    }

    /** DELETE /api/groupes/{identifiant}/cloture — annule (rien appliqué). */
    public function annuler(Request $request, string $identifiant): Response
    {
        // Symétrique de l'ouverture : table-OU-membre (le narrateur peut
        // annuler la fenêtre qu'il a ouverte). Rien n'a été appliqué.
        $groupe = $this->groupeLisible($request, $identifiant);

        $this->cloture->annuler($groupe);

        return response()->noContent();
    }

    /**
     * Le groupe demandé + le joueur connecté, qui doit y contrôler au moins
     * un héros actif (même règle que le reste de l'API).
     *
     * @return array{0: Groupe, 1: Joueur}
     */
    private function groupeEtJoueurMembre(string $identifiant): array
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $estMembre = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $joueur->id)
            ->exists();

        if (! $estMembre) {
            throw ValidationException::withMessages([
                'identifiant' => 'Vous n\'avez aucun héros actif dans ce groupe.',
            ]);
        }

        return [$groupe, $joueur];
    }
}
