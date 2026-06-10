<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenererSqueletteCampagne;
use App\Models\ClasseHeros;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\DemarreurQuete;
use App\Partie\EtatGroupe;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Création / rejointe / état d'un groupe (doc 05 §2-4).
 *
 * Créer une campagne dispatch GenererSqueletteCampagne : le squelette
 * (prémisse, menace, jalons) est généré en job, jamais en bloquant l'API.
 */
class GroupeController extends Controller
{
    /** Bornes de quêtes par longueur de campagne (doc 05 §2). */
    private const QUETES_PAR_LONGUEUR = [
        'tres_courte' => [1, 1],
        'courte' => [3, 5],
        'normale' => [7, 10],
        'longue' => [12, 15],
        'tres_longue' => [17, 20],
    ];

    /** POST /api/groupes — créer une campagne (écran /direction). */
    public function creer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            // Optionnel (contrat) : dérivé du nom si absent — le code est rendu au client.
            'identifiant' => ['nullable', 'string', 'max:32', 'alpha_dash', Rule::unique('groupes', 'identifiant')],
            'nom' => ['required', 'string', 'max:120'],
            'theme' => ['required', 'string', 'max:2000'],
            'longueur' => ['required', Rule::in(array_keys(self::QUETES_PAR_LONGUEUR))],
            'ton' => ['nullable', 'array'],
        ]);

        [$min, $max] = self::QUETES_PAR_LONGUEUR[$donnees['longueur']];

        $groupe = Groupe::create([
            'identifiant' => $donnees['identifiant'] ?? $this->genererIdentifiant($donnees['nom']),
            'nom' => $donnees['nom'],
            'theme' => $donnees['theme'], // le registre fantasy est rappelé au MJ IA par les consignes communes (doc 08 §4)
            'longueur' => $donnees['longueur'],
            'nb_quetes_total' => random_int($min, $max),
            'ton' => $donnees['ton'] ?? null,
        ]);

        GenererSqueletteCampagne::dispatch($groupe->id);

        return response()->json([
            'groupe' => $this->etatGroupe($groupe),
            'message' => 'Campagne créée — le MJ prépare le fil de la campagne.',
        ], 201);
    }

    /**
     * POST /api/groupes/{identifiant}/joueurs — rejoindre avec ses héros.
     *
     * Trois formes acceptées (docs/contrat-api.md) :
     * - {nom, classe} : crée un héros du roster depuis le catalogue de classes ;
     * - {personnage_id} : engage un héros existant du roster ;
     * - {personnage_ids: [...]} : plusieurs héros (un joueur peut en contrôler plusieurs).
     */
    public function rejoindre(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_ids' => ['required_without_all:personnage_id,classe', 'array', 'min:1'],
            'personnage_ids.*' => ['integer'],
            'personnage_id' => ['required_without_all:personnage_ids,classe', 'integer'],
            'nom' => ['required_with:classe', 'string', 'max:120'],
            'classe' => ['required_without_all:personnage_ids,personnage_id', Rule::exists('classes_heros', 'nom')],
        ]);

        if (isset($donnees['classe'])) {
            $personnages = collect([$this->creerHeros($joueur->id, $donnees['nom'], $donnees['classe'])]);
        } else {
            $ids = $donnees['personnage_ids'] ?? [$donnees['personnage_id']];

            $personnages = Personnage::query()
                ->whereIn('id', $ids)
                ->where('joueur_id', $joueur->id)
                ->get();

            if ($personnages->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages([
                    'personnage_ids' => 'Un des personnages n\'existe pas ou ne vous appartient pas.',
                ]);
            }
        }

        $dejaMembre = $groupe->personnages()->where('joueur_id', $joueur->id)->exists();

        // Règle d'arrivée (doc 05 §4) : nouveau joueur seulement entre deux
        // quêtes (au hub) ; un membre existant peut toujours se reconnecter.
        if (! $dejaMembre && $groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'identifiant' => 'Une quête est en cours : un nouveau joueur ne peut rejoindre qu\'au hub, entre deux quêtes.',
            ]);
        }

        DB::transaction(function () use ($groupe, $personnages) {
            foreach ($personnages as $personnage) {
                if ($personnage->groupe_actif_id !== null && $personnage->groupe_actif_id !== $groupe->id) {
                    throw ValidationException::withMessages([
                        'personnage_ids' => "« {$personnage->nom} » est déjà engagé dans un autre groupe actif.",
                    ]);
                }

                if ($personnage->groupe_actif_id === $groupe->id) {
                    continue; // reconnexion : déjà engagé, rien à refaire
                }

                $ordre = (int) $groupe->personnages()->count() + 1;
                $groupe->personnages()->attach($personnage->id, [
                    'ordre_initiative' => $ordre,
                    'actif' => true,
                ]);
                $personnage->update(['groupe_actif_id' => $groupe->id]);

                // L'or personnel est versé au pot commun à l'arrivée (doc 05 §4).
                if ($personnage->or > 0) {
                    $groupe->increment('or', $personnage->or);
                    $personnage->update(['or' => 0]);
                }
            }
        });

        Journal::ajouter($groupe->fresh(), 'systeme', [
            'action' => 'joueur_rejoint',
            'joueur_id' => $joueur->id,
            'personnage_ids' => $personnages->pluck('id')->all(),
        ]);

        return response()->json([
            'groupe' => $this->etatGroupe($groupe->fresh()),
            'personnage' => $personnages->count() === 1 ? [
                'id' => $personnages->first()->id,
                'nom' => $personnages->first()->nom,
                'classe' => $personnages->first()->classe,
            ] : null,
        ]);
    }

    /** Crée un héros du roster aux valeurs de départ du catalogue (doc 01). */
    private function creerHeros(int $joueurId, string $nom, string $classe): Personnage
    {
        $base = ClasseHeros::where('nom', $classe)->firstOrFail();

        return Personnage::create([
            'joueur_id' => $joueurId,
            'nom' => $nom,
            'classe' => $classe,
            'niveau' => 1,
            'attribut_body' => $base->attr_body,
            'attribut_mind' => $base->attr_mind,
            'pv_body_max' => $base->pv_body,
            'pv_body' => $base->pv_body,
            'pv_mind_max' => $base->pv_mind,
            'pv_mind' => $base->pv_mind,
            'des_attaque' => $base->des_attaque,
            'des_defense' => $base->des_defense,
            'deplacement_base' => $base->deplacement_base,
            'or' => 0,
        ]);
    }

    /** Dérive un code de groupe unique et lisible depuis le nom. */
    private function genererIdentifiant(string $nom): string
    {
        $base = substr(str()->slug($nom), 0, 24) ?: 'groupe';

        do {
            $identifiant = $base.'-'.strtolower(str()->random(4));
        } while (Groupe::where('identifiant', $identifiant)->exists());

        return $identifiant;
    }

    /**
     * GET /api/groupes/{identifiant}/etat — payload EtatGroupe du contrat
     * (docs/contrat-api.md), identique au broadcast `.groupe.etat` : carte,
     * entités, initiative, dernière narration. Reprise table / reconnexion.
     */
    public function etat(string $identifiant, EtatGroupe $etatGroupe): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();

        return response()->json($etatGroupe->payload($groupe));
    }

    /**
     * POST /api/groupes/{identifiant}/quetes — démarre la quête suivante
     * (contrat) : carte assemblée depuis les tuiles, monstres spawnés au
     * budget (score de puissance), initiative figée (C1). Entièrement
     * moteur — l'IA habillera par jobs (avec repli garanti).
     */
    public function demarrerQuete(string $identifiant, DemarreurQuete $demarreur): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        // Seul un membre du groupe (au moins un héros actif) peut lancer la quête.
        $estMembre = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $joueur->id)
            ->exists();

        if (! $estMembre) {
            throw ValidationException::withMessages([
                'identifiant' => 'Vous n\'avez aucun héros actif dans ce groupe.',
            ]);
        }

        $quete = $demarreur->demarrer($groupe);

        return response()->json([
            'quete' => [
                'id' => $quete->id,
                'titre' => $quete->titre,
                'position_arc' => $quete->position_arc,
                'type_jalon' => $quete->type_jalon,
                'etat' => $quete->etat,
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function etatGroupe(Groupe $groupe): array
    {
        $quete = $groupe->queteCourante;

        return [
            'id' => $groupe->id,
            'identifiant' => $groupe->identifiant,
            'nom' => $groupe->nom,
            'theme' => $groupe->theme,
            'longueur' => $groupe->longueur,
            'nb_quetes_total' => $groupe->nb_quetes_total,
            'phase' => $groupe->phase,
            'etat' => $groupe->etat,
            'or_commun' => $groupe->or,
            'squelette_pret' => $groupe->plan_campagne !== null,
            'quete_courante' => $quete === null ? null : [
                'id' => $quete->id,
                'titre' => $quete->titre,
                'position_arc' => $quete->position_arc,
                'type_jalon' => $quete->type_jalon,
                'etat' => $quete->etat,
            ],
            'heros' => $groupe->personnages()
                ->wherePivot('actif', true)
                ->get()
                ->map(fn (Personnage $p) => [
                    'id' => $p->id,
                    'joueur_id' => $p->joueur_id,
                    'nom' => $p->nom,
                    'classe' => $p->classe,
                    'niveau' => $p->niveau,
                    'pv_body' => $p->pv_body,
                    'pv_body_max' => $p->pv_body_max,
                    'pv_mind' => $p->pv_mind,
                    'pv_mind_max' => $p->pv_mind_max,
                    'ordre_initiative' => $p->pivot->ordre_initiative,
                ])
                ->values()
                ->all(),
        ];
    }
}
