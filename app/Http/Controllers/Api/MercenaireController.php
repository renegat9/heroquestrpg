<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Partie\EtatGroupe;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recrutement d'alliés au hub (Phase 2, 3.5 — doc 14) : un mercenaire ou un
 * compagnon animal est embauché contre l'or de la BOURSE COMMUNE, AVANT une
 * quête (phase `hub`). PNJ scripté, consommé en fin de quête. L'animal est
 * limité à UN par groupe.
 */
class MercenaireController extends Controller
{
    /**
     * GET /api/mercenaires — catalogue des alliés recrutables (contrat).
     *
     * Bloc de stats + prix (bourse commune). Group-agnostique comme le
     * catalogue de compétences : la disponibilité (or, animal déjà pris) est
     * calculée côté client à partir de l'état vivant du groupe
     * (`EtatGroupe.groupe.or` + `.mercenaires`), qui bouge à chaque recrutement.
     */
    public function catalogue(): JsonResponse
    {
        return response()->json([
            'mercenaires' => Mercenaire::query()
                ->orderBy('prix')
                ->get()
                ->map(fn (Mercenaire $m) => [
                    'id' => $m->id,
                    'nom' => $m->nom,
                    'type' => $m->type,
                    'prix' => (int) $m->prix,
                    'deplacement' => (int) $m->deplacement,
                    'attaque' => (int) $m->attaque,
                    'portee' => $m->portee,
                    'attaque_distance' => $m->attaque_distance === null ? null : (int) $m->attaque_distance,
                    'defense' => (int) $m->defense,
                    'pv_body' => (int) $m->pv_body,
                    'animal' => (bool) $m->animal,
                    'description' => $m->description,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * POST /api/groupes/{identifiant}/mercenaires  {mercenaire_id}
     */
    public function recruter(Request $request, string $identifiant, EtatGroupe $etatGroupe): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        // Le joueur doit avoir un personnage actif dans ce groupe.
        $membre = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $joueur?->id)
            ->exists();

        if (! $membre) {
            throw ValidationException::withMessages([
                'groupe' => 'Vous n\'êtes pas membre actif de ce groupe.',
            ]);
        }

        $donnees = $request->validate([
            'mercenaire_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'groupe' => 'Le recrutement n\'est possible qu\'au hub, entre deux quêtes.',
            ]);
        }

        $mercenaire = Mercenaire::findOrFail($donnees['mercenaire_id']);

        if ((int) $groupe->or < (int) $mercenaire->prix) {
            throw ValidationException::withMessages([
                'mercenaire_id' => "Or insuffisant : {$mercenaire->prix} requis, {$groupe->or} disponible.",
            ]);
        }

        // Un seul compagnon animal par groupe.
        if ($mercenaire->animal
            && $groupe->mercenaires()->whereHas('mercenaire', fn ($q) => $q->where('animal', true))->exists()) {
            throw ValidationException::withMessages([
                'mercenaire_id' => 'Le groupe a déjà un compagnon animal (un seul autorisé).',
            ]);
        }

        $recrue = DB::transaction(function () use ($groupe, $mercenaire) {
            $groupe->decrement('or', (int) $mercenaire->prix);

            return GroupeMercenaire::create([
                'groupe_id' => $groupe->id,
                'mercenaire_id' => $mercenaire->id,
                'pv_body' => (int) $mercenaire->pv_body,
                'etat' => 'actif',
            ]);
        });

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'mercenaire_recrute',
            'mercenaire' => $mercenaire->nom,
            'prix' => (int) $mercenaire->prix,
        ]);

        broadcast(new EtatGroupeDiffuse($groupe, $etatGroupe->payload($groupe->fresh())));

        return response()->json([
            'recrue' => [
                'id' => $recrue->id,
                'nom' => $mercenaire->nom,
                'type' => $mercenaire->type,
                'animal' => (bool) $mercenaire->animal,
            ],
            'or' => (int) $groupe->fresh()->or,
        ], 201);
    }
}
