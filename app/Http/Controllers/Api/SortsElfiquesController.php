<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use App\Partie\MoteurSorts;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * RECHOIX des sorts elfiques (Mage of the Mirror — décision de René,
 * 2026-08-11).
 *
 * L'Elfe choisit à la création une **école élémentaire** ou **3 sorts du
 * répertoire elfique**. La différence entre les deux voies n'est pas seulement
 * la liste : l'école est **définitive**, les 3 sorts elfiques se **rechoisissent
 * au hub**, entre deux quêtes. C'est ce qui donne son intérêt à une voie qui
 * offre 8 sorts pour 3 emplacements — on emporte ce que la prochaine quête
 * semble demander.
 *
 * AU HUB uniquement, comme équiper / la Forge / les dons : rien ne se reconfigure
 * en pleine quête, où le choix des sorts est un engagement pris avant d'entrer.
 */
class SortsElfiquesController extends Controller
{
    public function __construct(
        private readonly MoteurSorts $sorts,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /** PUT /api/groupes/{identifiant}/sorts-elfiques {personnage_id, sorts: [3]} */
    public function rechoisir(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            'sorts' => ['required', 'array', 'size:'.MoteurSorts::NB_SORTS_ELFIQUES_DEPART],
            'sorts.*' => ['integer', 'distinct'],
        ]);

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'phase' => 'On ne rechoisit ses sorts qu\'au hub, entre deux quêtes.',
            ]);
        }

        /** @var Personnage|null $elfe */
        $elfe = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id) // ses propres héros seulement
            ->first();

        if ($elfe === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        if ($elfe->classe !== MoteurSorts::CLASSE_ELFIQUE) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Seul l\'Elfe puise dans le répertoire elfique.',
            ]);
        }

        // ⚠ Un Elfe parti sur une ÉCOLE ne rechoisit rien : ce choix-là est
        // définitif, et c'est le prix de ses 3 sorts garantis. Sans cette
        // garde, la voie élémentaire deviendrait strictement meilleure — même
        // liberté, plus la progression par l'arbre.
        if (! $this->sorts->aRepertoireElfique($elfe)) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Cet Elfe a choisi une école élémentaire : ce choix est définitif.',
            ]);
        }

        $choisis = $this->sorts->fixerSortsElfiques($elfe, $donnees['sorts']);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'sorts_elfiques_rechoisis',
            'personnage_id' => $elfe->id,
            'sorts' => $choisis->pluck('nom')->all(),
        ], ['type' => 'personnage', 'id' => $elfe->id, 'nom' => $elfe->nom]);

        EtatGroupeDiffuse::dispatch($groupe, $this->etatGroupe->payload($groupe->fresh()));

        return response()->json([
            'personnage_id' => $elfe->id,
            'sorts' => $choisis->map(fn ($s) => [
                'sort_id' => $s->id,
                'nom' => $s->nom,
                'element' => $s->element,
                'type' => $s->type,
            ])->values()->all(),
        ]);
    }
}
