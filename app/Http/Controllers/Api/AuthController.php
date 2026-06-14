<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\JoueurAuthentifiable;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Login simple identifiant / mot de passe (cadre interne, doc 11 §11).
 *
 * Sanctum n'étant pas installé, l'auth est en SESSION : la SPA Vue
 * (même origine) envoie les cookies + le jeton CSRF du blade — voir
 * resources/js/composables/useApi.js et bootstrap/app.php (middlewares
 * de session ajoutés au groupe api).
 */
class AuthController extends Controller
{
    /**
     * POST /api/inscription {pseudo, identifiant, mot_de_passe}
     *
     * Crée le compte joueur et connecte immédiatement (session).
     * 422 si identifiant déjà pris.
     */
    public function inscription(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'pseudo' => ['required', 'string', 'max:120'],
            'identifiant' => ['required', 'string', 'alpha_dash', Rule::unique('joueurs', 'identifiant')],
            'mot_de_passe' => ['required', 'string', 'min:6'],
        ]);

        $joueur = JoueurAuthentifiable::create([
            'pseudo' => $donnees['pseudo'],
            'identifiant' => $donnees['identifiant'],
            'mot_de_passe' => $donnees['mot_de_passe'], // hashé via le cast 'hashed' du modèle Joueur
        ]);

        Auth::guard('joueur')->login($joueur);
        $request->session()->regenerate();

        return response()->json(['joueur' => $this->profil()], 201);
    }

    /** POST /api/connexion */
    public function connexion(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'identifiant' => ['required', 'string'],
            'mot_de_passe' => ['required', 'string'],
        ]);

        $ok = Auth::guard('joueur')->attempt([
            'identifiant' => $donnees['identifiant'],
            'password' => $donnees['mot_de_passe'], // clé `password` attendue par le provider
        ]);

        if (! $ok) {
            throw ValidationException::withMessages([
                'identifiant' => 'Identifiant ou mot de passe incorrect.',
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['joueur' => $this->profil()]);
    }

    /** POST /api/deconnexion */
    public function deconnexion(Request $request): JsonResponse
    {
        Auth::guard('joueur')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['deconnecte' => true]);
    }

    /** GET /api/moi — profil du joueur connecté (reprise / reconnexion). */
    public function moi(): JsonResponse
    {
        return response()->json(['joueur' => $this->profil()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profil(): array
    {
        /** @var JoueurAuthentifiable $joueur */
        $joueur = Auth::guard('joueur')->user();

        return [
            'id' => $joueur->id,
            'pseudo' => $joueur->pseudo,
            'identifiant' => $joueur->identifiant,
            'personnages' => $joueur->personnages()
                ->with(['competences:competences.id', 'sorts', 'groupeActif'])
                ->get(['id', 'nom', 'classe', 'niveau', 'groupe_actif_id'])
                ->map(function ($p) {
                    $disponible = $p->groupe_actif_id === null;

                    $data = [
                        'id' => $p->id,
                        'nom' => $p->nom,
                        'classe' => $p->classe,
                        'niveau' => (int) $p->niveau,
                        // Points JAMAIS stockés (contrat) : (niveau − 1) − nœuds acquis.
                        'points_competence' => max(0, ((int) $p->niveau - 1) - $p->competences->count()),
                        'competences' => $p->competences->pluck('id')->values()->all(),
                        // Répertoire de sorts (contrat) : l'onglet Sorts de la
                        // manette s'en nourrit, disponibilité par quête comprise.
                        'sorts' => $p->sorts
                            ->map(fn ($s) => [
                                'sort_id' => $s->id,
                                'nom' => $s->nom,
                                'element' => $s->element,
                                'type' => $s->type,
                                'disponible' => (bool) $s->pivot->disponible,
                            ])
                            ->values()
                            ->all(),
                        'disponible' => $disponible,
                    ];

                    // Personnage engagé : expose le groupe avec narrateur_actif (contrat).
                    if (! $disponible && $p->groupeActif !== null) {
                        /** @var Groupe $g */
                        $g = $p->groupeActif;
                        $data['groupe'] = [
                            'identifiant' => $g->identifiant,
                            'nom' => $g->nom,
                            'phase' => $g->phase,
                            'narrateur_actif' => TableController::narrateurActif($g),
                        ];
                    }

                    return $data;
                })
                ->values()
                ->all(),
        ];
    }
}
