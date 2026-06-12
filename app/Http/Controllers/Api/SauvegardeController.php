<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Joueur;
use App\Models\Snapshot;
use App\Partie\Sauvegarde;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Snapshots & reprise (contrat docs/contrat-api.md, doc 12 §4, doc 05 §6).
 *
 * Toute la mécanique vit dans App\Partie\Sauvegarde (sérialisation de
 * l'état vivant, rétention, restauration en transaction) ; le contrôleur
 * valide l'appartenance au groupe et la forme des corps de requête.
 */
class SauvegardeController extends Controller
{
    public function __construct(private readonly Sauvegarde $sauvegarde) {}

    /**
     * GET /api/groupes/{identifiant}/snapshots — liste des snapshots
     * disponibles : [{id, etiquette, sequence_evenement, created_at}].
     */
    public function index(string $identifiant): JsonResponse
    {
        [$groupe] = $this->groupeEtJoueurMembre($identifiant);

        return response()->json(
            $groupe->snapshots()->orderBy('id')->get()
                ->map(fn (Snapshot $s) => [
                    'id' => $s->id,
                    'etiquette' => data_get($s->etat, 'etiquette'),
                    'sequence_evenement' => (int) $s->sequence_evenement,
                    'created_at' => $s->created_at?->toISOString(),
                ])->values(),
        );
    }

    /**
     * POST /api/groupes/{identifiant}/reprise {snapshot_id?} — restaure
     * l'état vivant depuis le snapshot (défaut : `debut_quete` de la
     * dernière quête échouée — le « recharger » après TPK). 422 si une
     * quête est en cours et non échouée, ou si aucun snapshot.
     */
    public function reprendre(Request $request, string $identifiant): JsonResponse
    {
        [$groupe] = $this->groupeEtJoueurMembre($identifiant);

        $donnees = $request->validate([
            'snapshot_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $snapshot = $this->sauvegarde->reprendre(
            $groupe,
            isset($donnees['snapshot_id']) ? (int) $donnees['snapshot_id'] : null,
        );

        return response()->json([
            'snapshot_id' => $snapshot->id,
            'etiquette' => data_get($snapshot->etat, 'etiquette'),
            'sequence_evenement' => (int) $snapshot->sequence_evenement,
            'quete_id' => (int) data_get($snapshot->etat, 'quete.id'),
        ]);
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
