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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Les CHOIX du joueur sur une faveur de Hopekins Rest (livret G1504 p. 21-23).
 *
 * 1. Le LIEU (`PUT …/faveurs/lieu`) : « At the start of each quest, a hero may select one location to
 *    visit » — chaque joueur, pour son héros, à chaque tour de hub, ou aucun (René, 2026-10-16 : « Choisissons
 *    la faveur au hub si les extensions qui les utilisent sont utilisées »). Le tirage de fin de quête est retiré.
 * 2. Le SOUS-CHOIX d'une faveur qui en demande un : *Weapon Expert* (« Select a type of weapon […] when you
 *    acquire this Boon », René 2026-10-15) et *Arcane Library* (« you may take an additional set of spells »).
 *
 * Voir le commentaire de section de `FaveursHopekins` (« RÈGLE DE CHOIX ») pour la règle complète, le repli des
 * campagnes en cours et le pourquoi. AU HUB uniquement, comme équiper / la Forge / le rechoix des sorts.
 */
class FaveurController extends Controller
{
    public function __construct(
        private readonly FaveursHopekins $faveurs,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /** PUT /api/groupes/{identifiant}/faveurs/lieu {personnage_id, lieu: string|null} */
    public function choisirLieu(Request $request, string $identifiant): JsonResponse
    {
        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            // Une clé de `FaveursHopekins::TOUTES` ; `null` = le héros n'en visite aucun (« may »). La liste
            // blanche (`lieuxPour()`, publiée dans `/moi` → `visite_hopekins.lieux`) est revalidée par le service.
            'lieu' => ['present', 'nullable', 'string', Rule::in(FaveursHopekins::TOUTES)],
        ]);

        [$groupe, $personnage] = $this->porteurAuHub($identifiant, (int) $donnees['personnage_id'], 'On choisit son lieu de Hopekins Rest au hub, entre deux quêtes.');

        $choix = $this->faveurs->choisirLieu($groupe, $personnage, $donnees['lieu']);

        return $this->repondre($groupe, $personnage, $choix);
    }

    /** PUT /api/groupes/{identifiant}/faveurs/weapon-expert {personnage_id, objet_id} */
    public function choisirArme(Request $request, string $identifiant): JsonResponse
    {
        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            // La liste blanche est celle de `FaveursHopekins::typesArmeChoisissables()`,
            // revalidée par `choisirTypeArme()` : ici, seulement la forme.
            'objet_id' => ['required', 'integer', 'min:1'],
        ]);

        [$groupe, $personnage] = $this->porteurAuHub($identifiant, (int) $donnees['personnage_id'], 'On choisit son type d\'arme au hub, entre deux quêtes.');

        $choix = $this->faveurs->choisirTypeArme($groupe, $personnage, (int) $donnees['objet_id']);

        return $this->repondre($groupe, $personnage, $choix);
    }

    /** PUT /api/groupes/{identifiant}/faveurs/arcane-library {personnage_id, element} */
    public function choisirRepertoire(Request $request, string $identifiant): JsonResponse
    {
        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            // La liste blanche est celle de `FaveursHopekins::repertoiresChoisissables()`
            // (`MoteurSorts::repertoiresSupplementaires()`), revalidée par `choisirRepertoire()`.
            'element' => ['required', 'string', 'max:32'],
        ]);

        [$groupe, $personnage] = $this->porteurAuHub($identifiant, (int) $donnees['personnage_id'], 'On choisit son répertoire de sorts au hub, entre deux quêtes.');

        $choix = $this->faveurs->choisirRepertoire($groupe, $personnage, (string) $donnees['element']);

        return $this->repondre($groupe, $personnage, $choix);
    }

    /**
     * Le groupe et le héros ACTIF de CE joueur, au hub — le garde commun des choix de faveur.
     *
     * @return array{0: Groupe, 1: Personnage}
     */
    private function porteurAuHub(string $identifiant, int $personnageId, string $messageHorsHub): array
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages(['phase' => $messageHorsHub]);
        }

        /** @var Personnage|null $personnage */
        $personnage = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $personnageId)
            ->where('joueur_id', $joueur->id) // ses propres héros seulement
            ->first();

        if ($personnage === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        return [$groupe, $personnage];
    }

    /**
     * @param  array<string, mixed>  $choix
     */
    private function repondre(Groupe $groupe, Personnage $personnage, array $choix): JsonResponse
    {
        // `.groupe.etat` : chaque manette relit `/moi` à sa réception, la liste de choix
        // disparaît de la fiche du porteur et son choix s'y affiche.
        EtatGroupeDiffuse::dispatch($groupe, $this->etatGroupe->payload($groupe->fresh()));

        // La visite de Hopekins Rest de ce tour, relue : `null` une fois le lieu (ou le refus) posé.
        return response()->json($choix + [
            'faveurs' => FaveursHopekins::publier($personnage->fresh(), true),
            'visite_hopekins' => FaveursHopekins::visiteOuverte($groupe->fresh(), $personnage->fresh()),
        ]);
    }
}
