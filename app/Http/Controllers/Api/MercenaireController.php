<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Partie\EtatGroupe;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recrutement d'alliés au hub (Phase 2, 3.5 — doc 14) : un mercenaire ou un
 * compagnon animal est embauché contre l'or de la BOURSE COMMUNE, AVANT une
 * quête (phase `hub`). PNJ scripté. L'animal est limité à UN par groupe.
 *
 * ⚠ Depuis le chantier 1c (Wizards of Morcar, René 2026-10-06) : le
 * recrutement n'ouvre qu'une fois le groupe GARDIEN (`Groupe::estGardien()`,
 * 2 quêtes achevées), plafonné à 4 mercenaires PAR HÉROS recruteur, et le
 * mercenaire n'est plus consommé en fin de quête — il PERSISTE contre un
 * entretien de 10 po/quête (`App\Partie\FaveursHopekins::reglerEntretien()`,
 * réglé par `ResolveurTour::terminerQuete()`). Livret G1504 p. 8-9.
 */
class MercenaireController extends Controller
{
    /**
     * GET /api/mercenaires — catalogue des alliés recrutables (contrat).
     *
     * Bloc de stats + prix (bourse commune). Group-agnostique comme le
     * catalogue de compétences : la disponibilité (or, animal déjà pris) est
     * calculée côté client à partir de l'état vivant du groupe
     * (`EtatGroupe.groupe.or` + `.mercenaires`), qui bouge à chaque recrutement.
     */
    public function catalogue(): JsonResponse
    {
        return response()->json([
            'mercenaires' => Mercenaire::query()
                // Le Squelette Hearthkin (First Light, lot C) partage ce
                // catalogue sans jamais être recrutable au hub — il n'existe
                // que par l'action du Cor des Hearthkin, en quête.
                ->where('octroi_seul', false)
                ->orderBy('prix')
                ->get()
                ->map(fn (Mercenaire $m) => [
                    'id' => $m->id,
                    'nom' => $m->nom,
                    'type' => $m->type,
                    'prix' => (int) $m->prix,
                    'deplacement' => (int) $m->deplacement,
                    'attaque' => (int) $m->attaque,
                    'portee' => $m->portee,
                    'attaque_distance' => $m->attaque_distance === null ? null : (int) $m->attaque_distance,
                    'defense' => (int) $m->defense,
                    'pv_body' => (int) $m->pv_body,
                    'animal' => (bool) $m->animal,
                    'description' => $m->description,
                    'image_url' => app(\App\Partie\Images\BibliothequeImages::class)->urlMercenaire($m->id, $m->nom),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * POST /api/groupes/{identifiant}/mercenaires  {mercenaire_id}
     */
    public function recruter(Request $request, string $identifiant, EtatGroupe $etatGroupe): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        // Le joueur doit avoir un personnage actif dans ce groupe.
        $membre = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $joueur?->id)
            ->exists();

        if (! $membre) {
            throw ValidationException::withMessages([
                'groupe' => 'Vous n\'êtes pas membre actif de ce groupe.',
            ]);
        }

        $donnees = $request->validate([
            'mercenaire_id' => ['required', 'integer', 'min:1'],
            // QUI le contrôlera en quête (chantier 3a, 2026-10-04 : « un
            // allié est TOUJOURS joué par son joueur ») — optionnel, le
            // PREMIER héros actif de ce joueur par défaut, même patron que
            // `achats[].personnage_id` au marché.
            'personnage_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'groupe' => 'Le recrutement n\'est possible qu\'au hub, entre deux quêtes.',
            ]);
        }

        // STATUT DE GARDIEN (livret G1504 p. 8-9, Wizards of Morcar, chantier
        // 1c 2026-10-06) : « Once a hero has become a Warden (after
        // completing Quest 2) » — débloqué pour TOUS les groupes (décision
        // de René), pas seulement le thème `wizards_of_morcar`. Les
        // mercenaires déjà recrutés AVANT ce chantier (campagnes en cours)
        // ne sont jamais retirés : cette garde ne porte que sur un NOUVEAU
        // recrutement.
        if (! $groupe->estGardien()) {
            throw ValidationException::withMessages([
                'groupe' => 'Le recrutement n\'ouvre qu\'après deux quêtes achevées (statut de Gardien).',
            ]);
        }

        $mesHeros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $joueur?->id)
            ->orderBy('personnages.id')
            ->get();

        $recruteur = isset($donnees['personnage_id'])
            ? $mesHeros->firstWhere('id', $donnees['personnage_id'])
            : $mesHeros->first();

        if ($recruteur === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce héros n\'est pas un de vos héros actifs de ce groupe.',
            ]);
        }

        // 4 MERCENAIRES PAR HÉROS AU PLUS (même livret, même chantier) —
        // « Wardens may each hire up to four followers at any time between
        // quests. » Compté sur CE recruteur, mercenaires encore `actif`
        // (un mercenaire mort ou parti libère son emplacement).
        if ($groupe->mercenaires()->where('recruteur_personnage_id', $recruteur->id)->where('etat', 'actif')->count() >= 4) {
            throw ValidationException::withMessages([
                'personnage_id' => "« {$recruteur->nom} » a déjà engagé 4 mercenaires (maximum de Gardien).",
            ]);
        }

        $mercenaire = Mercenaire::findOrFail($donnees['mercenaire_id']);

        // Le Squelette Hearthkin n'est jamais affiché dans ce catalogue —
        // revalidé ici au cas où un client enverrait son id directement, la
        // même défense que « le menu ne propose jamais ce que le résolveur
        // refusera » lue dans l'autre sens.
        if ($mercenaire->octroi_seul) {
            throw ValidationException::withMessages([
                'mercenaire_id' => "« {$mercenaire->nom} » ne se recrute pas : il n'existe que par son propre effet.",
            ]);
        }

        // POTION DE CHARME (Wizards of Morcar, relue à l'image 2026-10-08) :
        // « You may hire up to three Mercenaries for 25 gold coins each less
        // than normal » — un ÉTAT DURABLE du recruteur
        // (`recrutements_a_rabais` > 0 ⇒ la remise `rabais_recrutement_po`),
        // consommé UN par recrutement dans la transaction ci-dessous. Jamais
        // une remise tant qu'on possède la fiole, jamais en cache.
        $rabais = (int) $recruteur->recrutements_a_rabais > 0
            ? (int) $recruteur->rabais_recrutement_po
            : 0;
        $prixAPayerActuel = max(0, (int) $mercenaire->prix - $rabais);

        if ((int) $groupe->or < $prixAPayerActuel) {
            throw ValidationException::withMessages([
                'mercenaire_id' => "Or insuffisant : {$prixAPayerActuel} requis, {$groupe->or} disponible.",
            ]);
        }

        // Un seul compagnon animal par groupe.
        if ($mercenaire->animal
            && $groupe->mercenaires()->whereHas('mercenaire', fn ($q) => $q->where('animal', true))->exists()) {
            throw ValidationException::withMessages([
                'mercenaire_id' => 'Le groupe a déjà un compagnon animal (un seul autorisé).',
            ]);
        }

        $recrue = DB::transaction(function () use ($groupe, $mercenaire, $recruteur, $prixAPayerActuel, $rabais) {
            $groupe->decrement('or', $prixAPayerActuel);

            // La potion se consomme recrutement par recrutement : UN rabais
            // dépensé ici, dans la même transaction que l'or.
            if ($rabais > 0) {
                $recruteur->decrement('recrutements_a_rabais');
            }

            return GroupeMercenaire::create([
                'groupe_id' => $groupe->id,
                'mercenaire_id' => $mercenaire->id,
                'recruteur_personnage_id' => $recruteur->id,
                'pv_body' => (int) $mercenaire->pv_body,
                'etat' => 'actif',
            ]);
        });

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'mercenaire_recrute',
            'mercenaire' => $mercenaire->nom,
            'prix' => $prixAPayerActuel,
            'prix_catalogue' => (int) $mercenaire->prix,
            'rabais' => $rabais,
            'recruteur_personnage_id' => $recruteur->id,
        ]);

        broadcast(new EtatGroupeDiffuse($groupe, $etatGroupe->payload($groupe->fresh())));

        return response()->json([
            'recrue' => [
                'id' => $recrue->id,
                'nom' => $mercenaire->nom,
                'type' => $mercenaire->type,
                'animal' => (bool) $mercenaire->animal,
                'recruteur_personnage_id' => $recruteur->id,
            ],
            'or' => (int) $groupe->fresh()->or,
        ], 201);
    }
}
