<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use App\Partie\MoteurReactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Réactions HORS TOUR (contrat docs/contrat-api.md §Réactions).
 *
 * Le joueur répond à une proposition déposée pendant la phase des monstres —
 * *Dark Wings*, *Twisting Torrent*. C'est la seule action du jeu qui arrive en
 * dehors du tour de son auteur, d'où sa route dédiée : elle ne passe ni par le
 * menu (il n'y en a pas à ce moment) ni par `/choix` (qui suppose que c'est
 * votre tour).
 */
class ReactionController extends Controller
{
    public function __construct(private readonly MoteurReactions $reactions) {}

    /**
     * POST /api/groupes/{identifiant}/reaction — `{personnage_id, accepte}`.
     */
    public function repondre(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            'accepte' => ['required', 'boolean'],
        ]);

        /** @var Personnage|null $heros */
        $heros = Personnage::query()
            ->where('id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id)   // on ne réagit que pour SES héros
            ->first();

        if ($heros === null) {
            throw ValidationException::withMessages([
                'personnage_id' => "Ce héros n'est pas le vôtre.",
            ]);
        }

        $resultat = $this->reactions->resoudre($groupe, $heros, (bool) $donnees['accepte']);

        // Les PV ont pu remonter et le héros se relever : tout le monde doit le
        // voir, table comprise.
        EtatGroupeDiffuse::dispatch($groupe, app(EtatGroupe::class)->payload($groupe->fresh()));

        return response()->json(['reaction' => $resultat]);
    }
}
