<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Partie\EtatGroupe;
use App\Partie\MoteurPotions;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Boire une potion / consommable.
 *
 * CANON : action GRATUITE jouable à tout moment (même hors de son tour /
 * pendant le tour d'un monstre). N'est donc PAS validée contre le menu courant
 * ni contre l'initiative — seulement contre l'appartenance du héros au groupe.
 */
class PotionController extends Controller
{
    public function boire(
        Request $request,
        string $identifiant,
        MoteurPotions $potions,
        EtatGroupe $etatGroupe,
    ): JsonResponse {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'inventaire_id' => ['required', 'integer'],
        ]);

        // La ligne d'inventaire doit appartenir à un héros ACTIF de ce groupe
        // contrôlé par le joueur connecté.
        $ligne = Inventaire::query()
            ->with('objet')
            ->whereKey($donnees['inventaire_id'])
            ->first();

        $personnage = $ligne === null ? null : $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $ligne->personnage_id)
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($personnage === null) {
            throw ValidationException::withMessages([
                'inventaire_id' => "Cette potion n'appartient pas à un de vos héros actifs dans ce groupe.",
            ]);
        }

        $resultat = $potions->boire($personnage, $ligne);

        Journal::ajouter($groupe, 'action', [
            'action' => 'boire_potion',
            'personnage_id' => $personnage->id,
            'objet' => $resultat['objet'],
            'effets' => $resultat['effets'],
        ]);

        // L'état change (PV / conditions) → tout le monde le voit immédiatement.
        broadcast(new EtatGroupeDiffuse($groupe, $etatGroupe->payload($groupe->fresh())));

        return response()->json(['resultat' => $resultat], 200);
    }
}
