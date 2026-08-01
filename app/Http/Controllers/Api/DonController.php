<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Partie\DonObjet;
use App\Partie\EtatGroupe;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Don d'un objet à un autre héros du groupe (doc 01 §7).
 *
 * AU HUB uniquement, comme équiper/déséquiper et la Forge : la boucle prévue
 * est marché → répartition du butin → équiper → quête suivante. Rien ne circule
 * en pleine quête, où le partage serait une action de tour à part entière.
 *
 * Asymétrie d'autorisation assumée : on ne donne QUE depuis un de ses propres
 * héros, mais vers **n'importe quel héros actif du groupe**, y compris ceux
 * d'un autre joueur — c'est la même logique que la Forge, où le Nain travaille
 * l'équipement de ses compagnons. Le receveur n'a rien à confirmer (décision de
 * René) : vous jouez autour d'une même table, et sa capacité de sac est
 * vérifiée avant, donc un don ne peut jamais lui nuire.
 */
class DonController extends Controller
{
    public function __construct(
        private readonly DonObjet $dons,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /** POST /api/groupes/{identifiant}/dons {personnage_id, inventaire_id, vers_personnage_id, quantite?} */
    public function donner(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            'inventaire_id' => ['required', 'integer'],
            'vers_personnage_id' => ['required', 'integer'],
            'quantite' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'phase' => 'On ne partage le butin qu\'au hub, entre deux quêtes.',
            ]);
        }

        // Le DONNEUR doit être un héros du joueur authentifié.
        $donneur = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($donneur === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        // Le RECEVEUR est n'importe quel héros actif du groupe.
        $receveur = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $donnees['vers_personnage_id'])
            ->first();

        if ($receveur === null) {
            throw ValidationException::withMessages([
                'vers_personnage_id' => 'Ce héros n\'est pas actif dans ce groupe.',
            ]);
        }

        $ligne = Inventaire::query()
            ->with('objet')
            ->where('id', $donnees['inventaire_id'])
            ->where('personnage_id', $donneur->id)
            ->first();

        if ($ligne === null) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Objet introuvable dans l\'inventaire de ce héros.',
            ]);
        }

        $nomObjet = $ligne->objet?->nom;
        $quantite = max(1, (int) ($donnees['quantite'] ?? 1));

        $this->dons->donner($donneur, $ligne, $receveur, $quantite);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'objet_donne',
            'objet' => $nomObjet,
            'quantite' => $quantite,
            'de_personnage_id' => $donneur->id,
            'vers_personnage_id' => $receveur->id,
            'vers_nom' => $receveur->nom,
        ], ['type' => 'personnage', 'id' => $donneur->id, 'nom' => $donneur->nom]);

        // Le sac du RECEVEUR a changé sans qu'il ait rien fait : sans ce
        // broadcast, sa manette ne le découvrirait qu'au prochain rechargement
        // (elle re-GET /moi à chaque `.groupe.etat`).
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe)));

        return response()->json([
            'don' => [
                'objet' => $nomObjet,
                'quantite' => $quantite,
                'vers' => ['personnage_id' => $receveur->id, 'nom' => $receveur->nom],
            ],
        ]);
    }
}
