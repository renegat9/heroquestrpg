<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\PersonnageFaveur;
use App\Partie\EtatGroupe;
use App\Partie\FaveursHopekins;
use App\Partie\RecrutementHub;
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
            // `achats[].personnage_id` au marché. La manette l'envoie : c'est
            // le héros dont elle affiche le prix publié (`groupe.recrutement`).
            'personnage_id' => ['sometimes', 'integer', 'min:1'],
        ]);

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

        $mercenaire = Mercenaire::findOrFail($donnees['mercenaire_id']);

        // LA DÉCISION — phase hub, statut de Gardien, plafond de quatre par
        // héros, allié non recrutable (Hearthkin), or (remise de la Potion de
        // charme comprise), compagnon animal unique — est prise UNE fois, dans
        // `RecrutementHub`, la même que `EtatGroupe.groupe.recrutement` publie.
        // Premier motif = refus, rangé sous la clé de requête qui le concerne.
        $recrutement = app(RecrutementHub::class);
        $motif = $recrutement->blocage($groupe, $mercenaire, $recruteur);

        if ($motif !== null) {
            throw ValidationException::withMessages([$motif['cle'] => $motif['message']]);
        }

        // POTION DE CHARME (Wizards of Morcar) : « You may hire up to three
        // Mercenaries for 25 gold coins each less than normal » — un ÉTAT DURABLE
        // du recruteur, consommé UN par recrutement dans la transaction ci-dessous.
        $prix = $recrutement->prixPour($recruteur, $mercenaire, $recrutement->gratuitPour($groupe, $recruteur));
        $prixAPayerActuel = $prix['prix'];
        $rabais = $prix['rabais_po'];

        $recrue = DB::transaction(function () use ($groupe, $mercenaire, $recruteur, $prixAPayerActuel, $prix) {
            // FAVEUR *Mercenary Guild* (Hopekins Rest) : le droit à UN mercenaire offert est relu
            // SOUS VERROU, dans la transaction — deux requêtes simultanées du même héros ne
            // prennent pas deux fois ce qui n'est offert qu'une fois.
            if ($prix['gratuit_faveur']) {
                $faveur = PersonnageFaveur::query()
                    ->where('personnage_id', $recruteur->id)
                    ->where('cle', FaveursHopekins::MERCENARY_GUILD)
                    ->lockForUpdate()
                    ->first();

                if ($faveur === null || FaveursHopekins::mercenaireOffertEnPoste($faveur, (int) $groupe->id)) {
                    throw ValidationException::withMessages([
                        'mercenaire_id' => 'Le mercenaire offert par la faveur est déjà engagé.',
                    ]);
                }
            }

            if ($prixAPayerActuel > 0) {
                $groupe->decrement('or', $prixAPayerActuel);
            }

            // La potion se consomme recrutement par recrutement : UN rabais
            // dépensé ici, dans la même transaction que l'or.
            if ($prix['remise_active']) {
                $recruteur->decrement('recrutements_a_rabais');
            }

            $recrue = GroupeMercenaire::create([
                'groupe_id' => $groupe->id,
                'mercenaire_id' => $mercenaire->id,
                'recruteur_personnage_id' => $recruteur->id,
                'pv_body' => (int) $mercenaire->pv_body,
                'etat' => 'actif',
            ]);

            // La faveur retient l'allié engagé à son titre : tant qu'il sert ce héros, le droit
            // est consommé ; mort ou parti, il se rouvre (`FaveursHopekins`, section Mercenary Guild).
            if ($prix['gratuit_faveur']) {
                FaveursHopekins::lierMercenaireOffert($recruteur, $recrue);
            }

            return $recrue;
        });

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'mercenaire_recrute',
            'mercenaire' => $mercenaire->nom,
            'prix' => $prixAPayerActuel,
            'prix_catalogue' => (int) $mercenaire->prix,
            'rabais' => $rabais,
            'faveur' => $prix['gratuit_faveur'] ? FaveursHopekins::MERCENARY_GUILD : null,
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
                'offert_par_faveur' => $prix['gratuit_faveur'],
            ],
            'or' => (int) $groupe->fresh()->or,
        ], 201);
    }
}
