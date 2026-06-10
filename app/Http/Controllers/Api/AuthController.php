<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\JoueurAuthentifiable;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
                ->with('competences:competences.id')
                ->get(['id', 'nom', 'classe', 'niveau', 'groupe_actif_id'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'nom' => $p->nom,
                    'classe' => $p->classe,
                    'niveau' => (int) $p->niveau,
                    // Points JAMAIS stockés (contrat) : (niveau − 1) − nœuds acquis.
                    'points_competence' => max(0, ((int) $p->niveau - 1) - $p->competences->count()),
                    'competences' => $p->competences->pluck('id')->values()->all(),
                    'disponible' => $p->groupe_actif_id === null,
                ])
                ->values()
                ->all(),
        ];
    }
}
