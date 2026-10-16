<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\CompagnonOffert;
use App\Partie\EtatGroupe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Le compagnon animal OFFERT à un groupe de moins de quatre héros (*Against the Ogre Horde*
 * p. 8-9, « at no cost »). Toute la décision — quand l'offre existe, qui la prend, ce qu'elle
 * devient — vit dans {@see CompagnonOffert} ; ce contrôleur n'authentifie et ne route que.
 *
 * Deux gestes, tous deux définitifs et ouverts au premier joueur qui les fait : PRENDRE un des
 * animaux (le héros qui le prend en devient le maître), ou REFUSER l'offre.
 */
class CompagnonOffertController extends Controller
{
    public function __construct(
        private readonly CompagnonOffert $compagnon,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /** POST /api/groupes/{identifiant}/compagnon-offert {mercenaire_id, personnage_id?} */
    public function prendre(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();

        $donnees = $request->validate([
            // La liste blanche est celle de `CompagnonOffert::animaux()`, revalidée par `prendre()`.
            'mercenaire_id' => ['required', 'integer', 'min:1'],
            'personnage_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $recruteur = $this->monHeros($groupe, $donnees['personnage_id'] ?? null);

        $recrue = $this->compagnon->prendre($groupe, $recruteur, (int) $donnees['mercenaire_id']);

        EtatGroupeDiffuse::dispatch($groupe, $this->etatGroupe->payload($groupe->fresh()));

        return response()->json([
            'recrue' => [
                'id' => $recrue->id,
                'nom' => $recrue->mercenaire->nom,
                'type' => $recrue->mercenaire->type,
                'animal' => true,
                'offert' => true,
                'recruteur_personnage_id' => $recruteur->id,
            ],
            'or' => (int) $groupe->fresh()->or, // inchangée : « at no cost ».
        ], 201);
    }

    /** DELETE /api/groupes/{identifiant}/compagnon-offert {personnage_id?} */
    public function refuser(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();

        $donnees = $request->validate(['personnage_id' => ['sometimes', 'integer', 'min:1']]);

        $this->compagnon->refuser($groupe, $this->monHeros($groupe, $donnees['personnage_id'] ?? null));

        EtatGroupeDiffuse::dispatch($groupe, $this->etatGroupe->payload($groupe->fresh()));

        return response()->json(['compagnon_offert' => CompagnonOffert::REFUSE]);
    }

    /** Un héros ACTIF du groupe que contrôle le joueur connecté (le premier, à défaut de `personnage_id`). */
    private function monHeros(Groupe $groupe, ?int $personnageId): Personnage
    {
        $joueur = Auth::guard('joueur')->user();

        $mesHeros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $joueur?->id)
            ->orderBy('personnages.id')
            ->get();

        $heros = $personnageId !== null ? $mesHeros->firstWhere('id', $personnageId) : $mesHeros->first();

        if ($heros === null) {
            throw ValidationException::withMessages([
                'personnage_id' => $personnageId !== null
                    ? 'Ce héros n\'est pas un de vos héros actifs de ce groupe.'
                    : 'Vous n\'avez aucun héros actif dans ce groupe.',
            ]);
        }

        return $heros;
    }
}
