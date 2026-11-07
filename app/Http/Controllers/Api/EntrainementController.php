<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Partie\Entrainement;
use App\Partie\EtatGroupe;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * L'ENTRAÎNEMENT au hub (René, 2026-11-07) — `POST /groupes/{identifiant}/entrainement`. La règle vit dans
 * `App\Partie\Entrainement` ; cette route vérifie seulement QUI paie, puis publie.
 */
class EntrainementController extends Controller
{
    public function __construct(
        private readonly Entrainement $entrainement,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    public function entrainer(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            'attribut' => ['required', 'string'],
        ]);

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($heros === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        $heros = $this->entrainement->entrainer($groupe, $heros, (string) $donnees['attribut']);
        $regle = Entrainement::ATTRIBUTS[$donnees['attribut']];

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'entrainement',
            'personnage_id' => $heros->id,
            'personnage' => $heros->nom,
            'attribut' => $donnees['attribut'],
            'valeur' => (int) $heros->{$regle['colonne']},
            'prix' => Entrainement::PRIX,
        ], ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        $groupe = $groupe->fresh();

        // La bourse commune a bougé : toutes les manettes et la table doivent le voir.
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe)));

        return response()->json([
            'personnage' => ['id' => $heros->id, 'attribut_body' => (int) $heros->attribut_body, 'attribut_mind' => (int) $heros->attribut_mind],
            'or' => (int) $groupe->or,
        ]);
    }
}
