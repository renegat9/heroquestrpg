<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use App\Partie\MoteurPotions;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Boire une potion AU HUB — entre deux quêtes (Wizards of Morcar, Potion of
 * Charm : « Drink this potion between quests when you want to hire
 * Mercenaries »). En quête, une potion se boit par le menu (`utiliser_objet`,
 * `ChoixController`) ; au hub, il n'y a pas de menu, donc une route dédiée —
 * réservée aux potions de `MotsClesEquipement::CLES_AU_HUB`.
 *
 * Même garde de contexte que `EquipementController` : phase hub, héros actif
 * du groupe contrôlé par le joueur connecté, exemplaire réellement dans SON
 * inventaire.
 */
class PotionController extends Controller
{
    public function __construct(private readonly MoteurPotions $potions) {}

    /** POST /api/groupes/{identifiant}/potions/boire-au-hub {personnage_id, inventaire_id} */
    public function boireAuHub(Request $request, string $identifiant, EtatGroupe $etatGroupe): JsonResponse
    {
        [$groupe, $personnage, $ligne] = $this->contexte($request, $identifiant);

        $resultat = $this->potions->boireAuHub($personnage, $ligne);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'potion_bue_au_hub',
            'objet' => $resultat['objet'],
            'personnage_id' => $personnage->id,
            'personnage' => $personnage->nom,
            'effets' => $resultat['effets'],
        ], ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom]);

        $personnage->refresh();

        // La remise restante change le verdict de recrutement publié au hub
        // (`groupe.recrutement`) : les manettes doivent le recevoir sans attendre.
        broadcast(new EtatGroupeDiffuse($groupe, $etatGroupe->payload($groupe->fresh())));

        return response()->json([
            'potion' => $resultat,
            'personnage' => [
                'id' => $personnage->id,
                'nom' => $personnage->nom,
                // La remise DÉCIDÉE par le serveur, publiée telle quelle : le
                // recrutement la lira côté serveur (`MercenaireController`).
                'rabais_recrutement' => [
                    'restants' => (int) $personnage->recrutements_a_rabais,
                    'po' => (int) $personnage->rabais_recrutement_po,
                ],
            ],
        ]);
    }

    /**
     * @return array{0: Groupe, 1: Personnage, 2: Inventaire}
     */
    private function contexte(Request $request, string $identifiant): array
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            'inventaire_id' => ['required', 'integer'],
        ]);

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'phase' => 'On ne boit une potion d\'entre-deux-quêtes qu\'au hub.',
            ]);
        }

        $personnage = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($personnage === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        $ligne = Inventaire::query()
            ->with('objet')
            ->where('id', $donnees['inventaire_id'])
            ->where('personnage_id', $personnage->id)
            ->first();

        if ($ligne === null) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Objet introuvable dans l\'inventaire de ce héros.',
            ]);
        }

        return [$groupe, $personnage, $ligne];
    }
}
