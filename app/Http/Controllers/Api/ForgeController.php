<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ForgeAmelioration;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Partie\Forge;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Forge du Nain (nœud d'arbre, doc 01 §6 + doc 04 §4) : AU HUB uniquement,
 * un Nain ayant acquis le nœud Forge améliore définitivement une pièce
 * d'équipement d'un membre actif du groupe (or de la bourse commune).
 */
class ForgeController extends Controller
{
    public function __construct(private readonly Forge $forge) {}

    /** GET /api/forge — catalogue des améliorations. */
    public function catalogue(): JsonResponse
    {
        return response()->json([
            'ameliorations' => ForgeAmelioration::query()
                ->orderBy('cible')->orderBy('id')
                ->get(['id', 'nom', 'cible', 'effet', 'prix']),
        ]);
    }

    /** POST /api/groupes/{identifiant}/forge {personnage_id, inventaire_id, amelioration_id} */
    public function appliquer(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            'inventaire_id' => ['required', 'integer'],
            'amelioration_id' => ['required', 'integer'],
        ]);

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'phase' => 'La Forge n\'opère qu\'au hub, entre deux quêtes.',
            ]);
        }

        $forgeron = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($forgeron === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        if (! $forgeron->competences()->where('nom', 'Forge')->exists()) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce héros n\'a pas acquis le nœud Forge du Nain.',
            ]);
        }

        // La cible peut être n'importe quel héros ACTIF du groupe (pas
        // seulement le forgeron) : le Nain forge pour toute la troupe.
        $ligne = Inventaire::query()
            ->with('objet', 'personnage')
            ->where('id', $donnees['inventaire_id'])
            ->whereIn('personnage_id', $groupe->personnages()->wherePivot('actif', true)->pluck('personnages.id'))
            ->first();

        if ($ligne === null) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Objet introuvable dans l\'inventaire d\'un héros actif de ce groupe.',
            ]);
        }

        $amelioration = ForgeAmelioration::find($donnees['amelioration_id']);

        if ($amelioration === null) {
            throw ValidationException::withMessages(['amelioration_id' => 'Amélioration de Forge inconnue.']);
        }

        $ligne = $this->forge->appliquer($groupe, $ligne, $amelioration);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'forge_appliquee',
            'personnage_id' => $ligne->personnage_id,
            'objet' => $ligne->objet?->nom,
            'amelioration' => $amelioration->nom,
        ], ['type' => 'personnage', 'id' => $forgeron->id, 'nom' => $forgeron->nom]);

        return response()->json([
            'inventaire' => [
                'id' => $ligne->id,
                'objet' => $ligne->objet?->nom,
                'ameliorations' => $ligne->ameliorations,
            ],
            'groupe' => ['or' => (int) $groupe->fresh()->or],
        ], 201);
    }
}
