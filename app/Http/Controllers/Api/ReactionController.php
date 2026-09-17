<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Events\SceneTable;
use App\Http\Controllers\Controller;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use App\Partie\MoteurReactions;
use App\Partie\SceneDeTable;
use App\Partie\TamponScenes;
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
            // Soin d'urgence : QUELLE potion / QUEL sort — `potion:{id}` ou
            // `sort:{id}`. La légalité est revalidée contre la liste déposée
            // dans la proposition, jamais prise pour argent comptant.
            'soin' => ['sometimes', 'nullable', 'string', 'max:32'],
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

        $resultat = $this->reactions->resoudre(
            $groupe, $heros, (bool) $donnees['accepte'], $donnees['soin'] ?? null,
        );

        // Les PV ont pu remonter et le héros se relever : tout le monde doit le
        // voir, table comprise.
        EtatGroupeDiffuse::dispatch($groupe, app(EtatGroupe::class)->payload($groupe->fresh()));

        // ⚠ …et la TABLE doit voir la réaction elle-même (René, 2026-09-17 : « une
        // manette peut aussi réagir durant le tour d'un monstre »). La phase des
        // monstres ne s'est pas arrêtée pendant que le joueur réfléchissait : sa
        // parade ou sa riposte arrive après coup, et elle n'avait AUCUNE scène —
        // l'écran montrait l'attaque, jamais ce qui l'avait défaite.
        $sequence = (int) Evenement::query()->where('groupe_id', $groupe->id)->max('sequence');

        foreach (app(SceneDeTable::class)->depuisReaction($resultat, $heros) as $scene) {
            broadcast(new SceneTable($groupe, $scene, $sequence));
        }

        // Une relève provoquée par la réaction (« se relève ») suit sa scène.
        app(TamponScenes::class)->vider();

        return response()->json(['reaction' => $resultat]);
    }
}
