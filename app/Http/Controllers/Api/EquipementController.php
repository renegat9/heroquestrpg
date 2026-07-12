<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Partie\Equipement;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Équiper / déséquiper une pièce d'équipement (doc 01 §7, §13).
 *
 * AU HUB uniquement (entre deux quêtes) au MVP : la boucle prévue est
 * marché → sac → équiper → quête suivante. L'« équipement comme action de
 * tour » en pleine quête (doc 01 §149) reste à faire.
 *
 * Toute la mécanique (déplacement de slot, application/révocation des deltas
 * de combat, auto-swap, incompatibilité deux-mains/bouclier, capacité de sac)
 * vit dans App\Partie\Equipement ; le contrôleur valide l'appartenance et la
 * phase, puis renvoie la fiche à jour du héros.
 */
class EquipementController extends Controller
{
    public function __construct(private readonly Equipement $equipement) {}

    /** POST /api/groupes/{identifiant}/equipement {personnage_id, inventaire_id} */
    public function equiper(Request $request, string $identifiant): JsonResponse
    {
        [$groupe, $personnage, $ligne] = $this->contexte($request, $identifiant);

        $this->equipement->equiper($personnage, $ligne);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'equipement_equipe',
            'personnage_id' => $personnage->id,
            'objet' => $ligne->objet?->nom,
        ], ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom]);

        return response()->json(['personnage' => $this->fiche($personnage->refresh())]);
    }

    /** DELETE /api/groupes/{identifiant}/equipement {personnage_id, inventaire_id} */
    public function desequiper(Request $request, string $identifiant): JsonResponse
    {
        [$groupe, $personnage, $ligne] = $this->contexte($request, $identifiant);

        $this->equipement->desequiper($personnage, $ligne);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'equipement_retire',
            'personnage_id' => $personnage->id,
            'objet' => $ligne->objet?->nom,
        ], ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom]);

        return response()->json(['personnage' => $this->fiche($personnage->refresh())]);
    }

    /**
     * Groupe + héros du joueur actif dans ce groupe + ligne d'inventaire visée.
     * Hub uniquement (422 en quête). 422 si le héros n'est pas au joueur.
     *
     * @return array{0: Groupe, 1: Personnage, 2: Inventaire}
     */
    private function contexte(Request $request, string $identifiant): array
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            'inventaire_id' => ['required', 'integer'],
        ]);

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'phase' => 'On ne gère son équipement qu\'au hub, entre deux quêtes.',
            ]);
        }

        $personnage = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($personnage === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        $ligne = Inventaire::query()
            ->with('objet')
            ->where('id', $donnees['inventaire_id'])
            ->where('personnage_id', $personnage->id)
            ->first();

        if ($ligne === null) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Objet introuvable dans l\'inventaire de ce héros.',
            ]);
        }

        return [$groupe, $personnage, $ligne];
    }

    /**
     * Fiche minimale renvoyée après une manipulation : dés effectifs (colonnes,
     * équipement inclus) + équipement à jour — le front peut mettre la fiche à
     * jour sans re-GET, mais /moi reste la source complète.
     *
     * @return array<string, mixed>
     */
    private function fiche(Personnage $personnage): array
    {
        return [
            'id' => $personnage->id,
            'des_attaque' => (int) $personnage->des_attaque,
            'des_defense' => (int) $personnage->des_defense,
        ];
    }
}
