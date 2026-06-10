<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Joueur;
use App\Partie\Marche\PhaseMarche;
use App\Partie\Marche\ProfilMarche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase marché (doc 04 §5, contrat docs/contrat-api.md) — au hub uniquement.
 *
 * Toute la mécanique vit dans App\Partie\Marche\PhaseMarche (cache serveur,
 * application atomique à la confirmation de tous) ; le contrôleur valide
 * l'appartenance au groupe et la forme des corps de requête. Le profil de
 * lieu est choisi par le MJ IA ; sans LLM, repli `bourg`.
 */
class MarcheController extends Controller
{
    public function __construct(private readonly PhaseMarche $marche) {}

    /** POST /api/groupes/{identifiant}/marche — ouvre la phase. */
    public function ouvrir(Request $request, string $identifiant): JsonResponse
    {
        [$groupe] = $this->groupeEtJoueurMembre($identifiant);

        $donnees = $request->validate([
            'profil' => ['nullable', Rule::in(ProfilMarche::noms())],
        ]);

        return response()->json($this->marche->ouvrir($groupe, $donnees['profil'] ?? null), 201);
    }

    /** GET /api/groupes/{identifiant}/marche — EtatMarche courant. */
    public function etat(string $identifiant): JsonResponse
    {
        [$groupe] = $this->groupeEtJoueurMembre($identifiant);

        $etat = $this->marche->etat($groupe);

        if ($etat === null) {
            abort(404, 'Aucune phase marché ouverte pour ce groupe.');
        }

        return response()->json($etat);
    }

    /**
     * PUT /api/groupes/{identifiant}/marche/panier — remplace le panier du
     * joueur (achats vers SES héros, ventes depuis SES inventaires) et
     * annule sa confirmation.
     */
    public function panier(Request $request, string $identifiant): JsonResponse
    {
        [$groupe, $joueur] = $this->groupeEtJoueurMembre($identifiant);

        $donnees = $request->validate([
            'achats' => ['present', 'array'],
            'achats.*.objet_id' => ['required', 'integer', 'min:1'],
            'achats.*.quantite' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'achats.*.personnage_id' => ['sometimes', 'integer', 'min:1'],
            'ventes' => ['present', 'array'],
            'ventes.*.inventaire_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(
            $this->marche->majPanier($groupe, $joueur, $donnees['achats'], $donnees['ventes'])
        );
    }

    /**
     * POST /api/groupes/{identifiant}/marche/confirmation — confirme le
     * panier du joueur ; quand TOUS ont confirmé, application atomique et
     * clôture (`.marche.finalise` puis `.groupe.etat`).
     */
    public function confirmer(string $identifiant): JsonResponse
    {
        [$groupe, $joueur] = $this->groupeEtJoueurMembre($identifiant);

        return response()->json($this->marche->confirmer($groupe, $joueur));
    }

    /** DELETE /api/groupes/{identifiant}/marche — annule (rien appliqué). */
    public function annuler(string $identifiant): Response
    {
        [$groupe] = $this->groupeEtJoueurMembre($identifiant);

        $this->marche->annuler($groupe);

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
