<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use App\Partie\FaveursHopekins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Le CHOIX du joueur sur une faveur de Hopekins Rest (livret G1504 p. 22-23).
 *
 * Une seule faveur en demande un : *Weapon Expert* — « Select a type of weapon […] when you
 * acquire this Boon ». Décision de René (2026-10-15) : le JOUEUR choisit, au moment où il
 * obtient la faveur ; la faveur elle-même et son porteur restent tirés au hasard
 * (`FaveursHopekins::attribuerFaveurDeFinDeQuete()`). Voir le commentaire de section de
 * `FaveursHopekins` pour la règle complète, le repli des faveurs sans choix et le pourquoi.
 *
 * AU HUB uniquement, comme équiper / la Forge / le rechoix des sorts : la faveur est reçue
 * à la fin d'une quête, le choix se fait entre deux quêtes, avant de repartir.
 */
class FaveurController extends Controller
{
    public function __construct(
        private readonly FaveursHopekins $faveurs,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /** PUT /api/groupes/{identifiant}/faveurs/weapon-expert {personnage_id, objet_id} */
    public function choisirArme(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            // La liste blanche est celle de `FaveursHopekins::typesArmeChoisissables()`,
            // revalidée par `choisirTypeArme()` : ici, seulement la forme.
            'objet_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'phase' => 'On choisit son type d\'arme au hub, entre deux quêtes.',
            ]);
        }

        /** @var Personnage|null $personnage */
        $personnage = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id) // ses propres héros seulement
            ->first();

        if ($personnage === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        $choix = $this->faveurs->choisirTypeArme($groupe, $personnage, (int) $donnees['objet_id']);

        // `.groupe.etat` : chaque manette relit `/moi` à sa réception, la liste de choix
        // disparaît de la fiche du porteur et la spécialité s'y affiche.
        EtatGroupeDiffuse::dispatch($groupe, $this->etatGroupe->payload($groupe->fresh()));

        return response()->json($choix + [
            'faveurs' => FaveursHopekins::publier($personnage->fresh(), true),
        ]);
    }
}
