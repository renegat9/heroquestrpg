<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Engine\ReactionEffet;
use App\Events\EtatGroupeDiffuse;
use App\Events\SceneTable;
use App\Http\Controllers\Controller;
use App\Jobs\GenererMenu;
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

        // …et les MENUS. Une réaction change le plateau (tuile trophée défaussée, PV rendus, riposte, défi refusé : la bête
        // frappe le fouilleur), et les menus en cache, calculés AVANT, le décrivent encore. Validation de la PR #2 (2026-11-12) :
        // un héros qui avait accepté un bouclier blanc se voyait offrir l'élan avec la tuile défaussée — 422. On les relance
        // comme après un choix, quelle que soit la réponse : régénérer un menu exact ne coûte qu'un calcul.
        // SAUF la reprise d'une attaque suspendue : `ExecutionChoix::executer()` la relance déjà, et le menu gardé à dessein
        // quand la reprise échoue doit rester tel quel (`MoteurReactions::reprendreAttaque()`).
        if (! $this->reprendUneAttaque($resultat)) {
            GenererMenu::relancerApresResolution($groupe);
        }

        return response()->json(['reaction' => $resultat]);
    }

    /**
     * La réponse REPREND-elle une attaque suspendue — Vision du futur sur le jet d'attaque, crâne de trophée ? Ce cas-là
     * consomme son menu (`MoteurReactions::reprendreAttaque()`, `repondreTropheeCrane()`) : il n'est pas relancé ici.
     *
     * @param  array<string, mixed>  $resultat
     */
    private function reprendUneAttaque(array $resultat): bool
    {
        return ($resultat['action'] ?? null) === ReactionEffet::TROPHEE_CRANE
            || (($resultat['action'] ?? null) === ReactionEffet::RELANCE_JET && ($resultat['jet'] ?? null) === ReactionEffet::JET_ATTAQUE);
    }
}
