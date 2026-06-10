<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Joueur;
use App\Partie\Votes\VoteGroupe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Votes de groupe & départs (doc 05 §5, contrat docs/contrat-api.md).
 *
 * Un seul vote actif par groupe (cache + journal) ; la mécanique vit dans
 * App\Partie\Votes\VoteGroupe. En quête, retirer un joueur exige le vote
 * majoritaire des autres ; au hub, le départ est libre avec sa part du pot.
 */
class VoteController extends Controller
{
    public function __construct(private readonly VoteGroupe $votes) {}

    /** POST /api/groupes/{identifiant}/votes — lance un vote. */
    public function lancer(Request $request, string $identifiant): JsonResponse
    {
        [$groupe, $joueur] = $this->groupeEtJoueurMembre($identifiant);

        $donnees = $request->validate([
            'type' => ['required', Rule::in(['retrait_joueur', 'choix_groupe'])],
            'question' => ['nullable', 'string', 'max:500'],
            'options' => ['required_if:type,choix_groupe', 'array', 'min:2'],
            'options.*.id' => ['required_with:options', 'string', 'max:64'],
            'options.*.libelle' => ['required_with:options', 'string', 'max:200'],
            'cible_joueur_id' => ['required_if:type,retrait_joueur', 'integer', 'min:1'],
        ]);

        return response()->json(['vote' => $this->votes->lancer($groupe, $joueur, $donnees)], 201);
    }

    /**
     * POST /api/groupes/{identifiant}/votes/bulletin — vote du joueur ; à
     * complétude des votants, le vote est résolu (`.vote.resultat`).
     */
    public function bulletin(Request $request, string $identifiant): JsonResponse
    {
        [$groupe, $joueur] = $this->groupeEtJoueurMembre($identifiant);

        $donnees = $request->validate([
            'option_id' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($this->votes->voter($groupe, $joueur, $donnees['option_id']));
    }

    /** GET /api/groupes/{identifiant}/votes — vote actif ou null. */
    public function actif(string $identifiant): JsonResponse
    {
        [$groupe] = $this->groupeEtJoueurMembre($identifiant);

        return response()->json(['vote' => $this->votes->actif($groupe)]);
    }

    /**
     * POST /api/groupes/{identifiant}/depart — départ LIBRE entre les
     * quêtes : pas de vote, part égale du pot commun (doc 05 §5).
     */
    public function depart(string $identifiant): JsonResponse
    {
        [$groupe, $joueur] = $this->groupeEtJoueurMembre($identifiant);

        return response()->json($this->votes->departLibre($groupe, $joueur));
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
