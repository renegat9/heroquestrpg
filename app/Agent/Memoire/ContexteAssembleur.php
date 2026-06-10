<?php

declare(strict_types=1);

namespace App\Agent\Memoire;

use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Personnage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Assemble le contexte d'un appel de skill MJ IA (doc 07 §2-4).
 *
 * Couches injectées :
 *  - état vivant (MariaDB, exact, JAMAIS en RAG) : groupe, héros actifs,
 *    quête courante, monstres en jeu ;
 *  - squelette de campagne (groupes.plan_campagne) : socle stable consulté
 *    à CHAQUE génération pour tenir le fil rouge ;
 *  - événements récents (journal, en clair) ;
 *  - extraits de bible (Qdrant, recherche filtrée group_id) — best effort :
 *    si Qdrant est injoignable, le tour continue sans la bible.
 *
 * Les jobs complètent ensuite le contexte avec leurs sections propres
 * (catalogue + budget pour DetailQuete, resultat_moteur pour Narration…).
 */
class ContexteAssembleur
{
    public const NB_EVENEMENTS_RECENTS = 15;

    public const NB_EXTRAITS_BIBLE = 5;

    public function __construct(private readonly BibleQdrant $bible) {}

    /**
     * @param  string|null  $requeteScene  requête RAG (défaut : thème + dernière narration)
     * @param  array<string, mixed>  $extra  sections supplémentaires du job appelant
     * @return array<string, mixed>
     */
    public function assembler(Groupe $groupe, ?string $requeteScene = null, array $extra = []): array
    {
        $evenements = $this->evenementsRecents($groupe);

        $contexte = [
            'groupe' => [
                'identifiant' => $groupe->identifiant,
                'nom' => $groupe->nom,
                'theme' => $groupe->theme,
                'longueur' => $groupe->longueur,
                'nb_quetes_total' => $groupe->nb_quetes_total,
                'phase' => $groupe->phase,
                'or_commun' => $groupe->or,
                'ton' => $groupe->ton,
            ],
            'squelette' => $groupe->plan_campagne,
            'etat_vivant' => $this->etatVivant($groupe),
            'evenements_recents' => $evenements,
            'bible' => $this->extraitsBible($groupe, $requeteScene ?? $this->requeteParDefaut($groupe, $evenements)),
        ];

        return array_merge($contexte, $extra);
    }

    /**
     * État vivant exact — toujours présent, jamais résumé (doc 07 §2).
     *
     * @return array<string, mixed>
     */
    private function etatVivant(Groupe $groupe): array
    {
        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->get()
            ->map(fn (Personnage $p) => [
                'id' => $p->id,
                'nom' => $p->nom,
                'classe' => $p->classe,
                'niveau' => $p->niveau,
                'pv_body' => "{$p->pv_body}/{$p->pv_body_max}",
                'pv_mind' => "{$p->pv_mind}/{$p->pv_mind_max}",
                'attribut_body' => $p->attribut_body,
                'attribut_mind' => $p->attribut_mind,
                'or' => $p->or,
            ])
            ->values()
            ->all();

        $quete = $groupe->queteCourante;
        $queteCourante = null;

        if ($quete !== null) {
            $queteCourante = [
                'id' => $quete->id,
                'titre' => $quete->titre,
                'position_arc' => $quete->position_arc,
                'type_jalon' => $quete->type_jalon,
                'etat' => $quete->etat,
                'monstres_actifs' => $quete->instancesMonstres()
                    ->where('etat', 'actif')
                    ->with('monstre')
                    ->get()
                    ->map(fn ($instance) => [
                        'instance_id' => $instance->id,
                        'nom' => $instance->habillage['nom'] ?? $instance->monstre?->nom_base,
                        'pv_body' => $instance->pv_body,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'phase' => $groupe->phase,
            'heros' => $heros,
            'quete_courante' => $queteCourante,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function evenementsRecents(Groupe $groupe): array
    {
        return Evenement::query()
            ->where('groupe_id', $groupe->id)
            ->orderByDesc('sequence')
            ->limit(self::NB_EVENEMENTS_RECENTS)
            ->get()
            ->reverse() // ordre chronologique pour le prompt
            ->map(fn (Evenement $e) => [
                'sequence' => $e->sequence,
                'type' => $e->type,
                'acteur' => $e->acteur,
                'payload' => $e->payload,
            ])
            ->values()
            ->all();
    }

    /**
     * Extraits de bible pertinents pour la scène — best effort.
     *
     * @return list<array<string, mixed>>
     */
    private function extraitsBible(Groupe $groupe, string $requete): array
    {
        try {
            return $this->bible->rechercher($groupe->id, $requete, self::NB_EXTRAITS_BIBLE);
        } catch (Throwable $e) {
            Log::warning('Bible Qdrant indisponible — contexte assemblé sans extraits.', [
                'groupe_id' => $groupe->id,
                'erreur' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Requête RAG par défaut : thème de campagne + dernière narration
     * (proxy raisonnable de « la scène courante »).
     *
     * @param  list<array<string, mixed>>  $evenements
     */
    private function requeteParDefaut(Groupe $groupe, array $evenements): string
    {
        $derniereNarration = '';

        foreach (array_reverse($evenements) as $evenement) {
            if ($evenement['type'] === 'narration') {
                $derniereNarration = (string) ($evenement['payload']['texte'] ?? '');
                break;
            }
        }

        return trim($groupe->theme.' '.$derniereNarration);
    }
}
