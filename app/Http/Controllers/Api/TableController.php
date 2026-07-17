<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\MjReflechit;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Partie\EtatGroupe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Session de table / narrateur (docs/contrat-api.md §Modèle de session).
 *
 * La table est identifiée par un code de groupe et maintient une SESSION
 * Laravel (cookie) côté navigateur. Le « narrateur actif » est signalé
 * par un heartbeat cache de 30 s : sans ping régulier (~15 s) la table
 * est considérée absente.
 *
 * Ces routes sont PUBLIQUES (hors guard auth:joueur) — réorganisées dans
 * routes/api.php en dehors du groupe auth.
 */
class TableController extends Controller
{
    /**
     * POST /api/table {code}
     *
     * Ouvre une session de table pour le groupe identifié par `code`.
     * 404 si le code est inconnu. Pose le heartbeat initial (30 s).
     */
    public function ouvrir(Request $request, EtatGroupe $etatGroupe): JsonResponse
    {
        $donnees = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $groupe = Groupe::where('identifiant', $donnees['code'])->firstOrFail();

        // Enregistre la session de table (côté serveur, cookie session).
        $request->session()->put('table_groupe', $groupe->identifiant);

        // Heartbeat initial : la table est active pour 30 secondes.
        Cache::put(self::cleActive($groupe->id), true, now()->addSeconds(30));

        return response()->json([
            'groupe' => $etatGroupe->payload($groupe),
        ]);
    }

    /**
     * POST /api/table/ping
     *
     * Heartbeat : rafraîchit « table active » pour 30 s supplémentaires.
     * À envoyer toutes les ~15 s par le client table.
     * 204 si la session est valide ; 403 sinon.
     */
    public function ping(Request $request): Response
    {
        $identifiant = $request->session()->get('table_groupe');

        if ($identifiant === null) {
            abort(403, 'Aucune session de table active.');
        }

        $groupe = Groupe::where('identifiant', $identifiant)->first();

        if ($groupe === null) {
            $request->session()->forget('table_groupe');
            abort(403, 'Groupe introuvable.');
        }

        Cache::put(self::cleActive($groupe->id), true, now()->addSeconds(30));

        return response()->noContent();
    }

    /**
     * POST /api/table/lecture-terminee — la table signale qu'elle a FINI de LIRE
     * la dernière narration (fin du TTS, ou délai de lecture sans voix). Éteint
     * « MJ réfléchit » (B1) : le joueur suivant n'est activé qu'à cet instant,
     * pas dès la GÉNÉRATION de la narration. Idempotent.
     */
    public function lectureTerminee(Request $request): Response
    {
        $identifiant = $request->session()->get('table_groupe');

        if ($identifiant === null) {
            abort(403, 'Aucune session de table active.');
        }

        $groupe = Groupe::where('identifiant', $identifiant)->first();

        if ($groupe === null) {
            $request->session()->forget('table_groupe');
            abort(403, 'Groupe introuvable.');
        }

        broadcast(new MjReflechit($groupe, false));

        return response()->noContent();
    }

    /**
     * POST /api/table/quitter
     *
     * Ferme la session de table et supprime le heartbeat du cache.
     * 204 dans tous les cas.
     */
    public function quitter(Request $request): Response
    {
        $identifiant = $request->session()->get('table_groupe');

        if ($identifiant !== null) {
            $groupe = Groupe::where('identifiant', $identifiant)->first();

            if ($groupe !== null) {
                Cache::forget(self::cleActive($groupe->id));
            }

            $request->session()->forget('table_groupe');
        }

        return response()->noContent();
    }

    /**
     * Clé de cache du heartbeat « narrateur actif » pour un groupe.
     */
    public static function cleActive(int $groupeId): string
    {
        return "table:active:{$groupeId}";
    }

    /**
     * Le narrateur (table) est-il actif (heartbeat frais) pour ce groupe ?
     */
    public static function narrateurActif(Groupe $groupe): bool
    {
        return Cache::has(self::cleActive($groupe->id));
    }
}
