<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\MjReflechit;
use App\Http\Controllers\Controller;
use App\Jobs\GenererMenu;
use App\Jobs\GenererNarration;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\ResolveurTour;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Réception d'un choix de menu (contrat docs/contrat-api.md ; doc 11 §4) :
 *
 *  1. le téléphone envoie {option_id, parametres?} ;
 *  2. l'API valide l'option contre le DERNIER MENU PROPOSÉ au joueur
 *     (mémorisé en cache par GenererMenu — garde-fou strict, doc 08 §2) :
 *     option absente du menu → 422 ;
 *  3. le MOTEUR résout (ResolveurTour : déplacement, attaque, jet…), met à
 *     jour l'état, journalise et diffuse `.groupe.etat` ;
 *  4. dispatch des jobs IA (narration + menus suivants) — rien ne bloque ;
 *  5. réponse 202, la suite arrive par Reverb (« le MJ réfléchit… »).
 */
class ChoixController extends Controller
{
    /**
     * POST /api/groupes/{identifiant}/choix
     *
     * ResolveurTour est injecté PAR MÉTHODE (pas au constructeur) : Laravel
     * met en cache l'instance du contrôleur sur la route entre les requêtes
     * d'un même process, et le lanceur de dés doit être résolu à CHAQUE
     * requête (les tests le re-bindent via desFiges()).
     */
    public function choisir(Request $request, string $identifiant, ResolveurTour $resolveur): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'option_id' => ['required', 'string', 'max:64'],
            'parametres' => ['nullable', 'array'],
            'parametres.x' => ['sometimes', 'integer', 'min:0'],
            'parametres.y' => ['sometimes', 'integer', 'min:0'],
            'parametres.cible_id' => ['sometimes', 'integer', 'min:1'],
            // Sorts (doc 02) : type de la cible si un monstre et un héros
            // partagent le même id, et sort à récupérer (Concentration).
            'parametres.cible_type' => ['sometimes', Rule::in(['monstre', 'heros'])],
            'parametres.sort_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Le moteur fait autorité : seule une option du dernier menu proposé
        // à CE joueur est légale.
        $cleMenu = GenererMenu::cleMenu($groupe->id, (int) $joueur->id);
        $dernierMenu = Cache::get($cleMenu);

        if (! is_array($dernierMenu)) {
            throw ValidationException::withMessages([
                'option_id' => 'Aucun menu en attente pour ce joueur — attendez la proposition du MJ.',
            ]);
        }

        $option = collect($dernierMenu['menu']['options'] ?? [])
            ->first(fn ($o) => ($o['id'] ?? null) === $donnees['option_id']);

        if ($option === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Option illégale : elle ne figure pas dans le dernier menu proposé.',
            ]);
        }

        $personnage = $this->personnageLegal($groupe, (int) $joueur->id, (int) $dernierMenu['personnage_id']);
        $acteur = ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom];

        // Le choix lui-même entre au journal (source de vérité rejouable).
        Journal::ajouter($groupe, 'choix', [
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'type' => $option['type'] ?? null,
        ], $acteur);

        // Résolution déterministe par le moteur (jamais par l'IA).
        if ($groupe->phase === 'quete') {
            $resultat = $resolveur->resoudre($groupe, $personnage, $option, $donnees['parametres'] ?? []);
        } else {
            $resultat = [
                'type' => $option['type'] ?? 'action',
                'option_id' => $option['id'],
                'libelle' => $option['libelle'] ?? null,
            ];
        }

        // Un menu = un choix : il est consommé, un nouveau sera proposé.
        Cache::forget($cleMenu);

        // L'IA n'intervient que sur les actions NOTABLES. Un simple déplacement
        // (ou attente), sans changement de phase, reste 100 % moteur → tour
        // instantané : pas de narration, menus moteur seuls (pas d'appel LLM).
        $triviale = in_array($resultat['type'] ?? null, ['deplacement', 'attente'], true)
            && $groupe->fresh()->phase === 'quete';

        if (! $triviale) {
            // Suite du tour en jobs : narration puis nouveaux menus (doc 11 §4).
            broadcast(new MjReflechit($groupe, true));
            GenererNarration::dispatch($groupe->id, $resultat);
        }

        foreach ($groupe->personnages()->wherePivot('actif', true)->get() as $heros) {
            GenererMenu::dispatch($groupe->id, (int) $heros->joueur_id, (int) $heros->id, enrichir: ! $triviale);
        }

        // 202 : le moteur a résolu, l'état et la narration arrivent par Reverb.
        // Le résultat moteur est renvoyé en echo (affichage immédiat des dés).
        return response()->json(['resultat' => $resultat], 202);
    }

    /**
     * Le personnage appartient-il au joueur ET est-il actif dans ce groupe ?
     */
    private function personnageLegal(Groupe $groupe, int $joueurId, int $personnageId): Personnage
    {
        $personnage = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $personnageId)
            ->where('joueur_id', $joueurId)
            ->first();

        if ($personnage === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        return $personnage;
    }
}
