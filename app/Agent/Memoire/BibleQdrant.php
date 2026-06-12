<?php

declare(strict_types=1);

namespace App\Agent\Memoire;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client HTTP minimal de la bible d'univers dans Qdrant (docs 11 §6, 12 §7).
 *
 * Une collection unique (`bible`), isolée par campagne via le payload
 * `group_id` : toute écriture porte le group_id, toute recherche est
 * FILTRÉE dessus (jamais de fuite de lore entre groupes, Q7).
 *
 * Schéma de point (doc 12 §7) — le vecteur encode `contenu`,
 * le payload porte les métadonnées filtrables :
 *   group_id, type (pnj|lieu|evenement|branche|reputation|promesse),
 *   titre, contenu, quete_id?, statut?, sequence?, source_evenement_id?
 *
 * Les erreurs remontent en RuntimeException : les appelants (jobs,
 * ContexteAssembleur) décident si la bible est bloquante ou "best effort".
 */
class BibleQdrant
{
    /** Types d'entrées admis dans la bible (doc 12 §7). */
    public const TYPES = ['pnj', 'lieu', 'evenement', 'branche', 'reputation', 'promesse'];

    public function __construct(
        private readonly Embeddings $embeddings,
        private readonly ?string $host = null,
        private readonly ?int $port = null,
        private readonly ?string $collection = null,
    ) {}

    /**
     * Crée la collection (distance cosinus, dimension de l'embedder) et les
     * index de payload `group_id` + `type` si absents. Idempotent.
     */
    public function assurerCollection(): void
    {
        $collection = $this->collection();

        $existe = $this->http()->get("/collections/{$collection}");

        if ($existe->status() === 404) {
            $creation = $this->http()->put("/collections/{$collection}", [
                'vectors' => [
                    'size' => $this->embeddings->dimension(),
                    'distance' => 'Cosine',
                ],
            ]);

            if ($creation->failed()) {
                throw new RuntimeException("Qdrant : création de la collection {$collection} impossible ({$creation->status()}).");
            }
        } elseif ($existe->failed()) {
            throw new RuntimeException("Qdrant injoignable ({$existe->status()}).");
        }

        // Index de payload pour le filtrage rapide (doc 12 §7) — idempotent,
        // Qdrant renvoie une erreur bénigne si l'index existe déjà.
        foreach (['group_id' => 'integer', 'type' => 'keyword'] as $champ => $schema) {
            $this->http()->put("/collections/{$collection}/index", [
                'field_name' => $champ,
                'field_schema' => $schema,
            ]);
        }
    }

    /**
     * Upserte des entrées de bible pour un groupe.
     *
     * @param  list<array{type: string, titre: string, contenu: string, quete_id?: int|null, statut?: string|null, sequence?: int|null, source_evenement_id?: int|null}>  $entrees
     */
    public function upsert(int $groupeId, array $entrees): void
    {
        if ($entrees === []) {
            return;
        }

        $points = [];

        foreach ($entrees as $entree) {
            $points[] = [
                'id' => (string) Str::uuid(),
                'vector' => $this->embeddings->vecteur($entree['titre'].' '.$entree['contenu']),
                'payload' => [
                    'group_id' => $groupeId,
                    'type' => $entree['type'],
                    'titre' => $entree['titre'],
                    'contenu' => $entree['contenu'],
                    'quete_id' => $entree['quete_id'] ?? null,
                    'statut' => $entree['statut'] ?? null,
                    'sequence' => $entree['sequence'] ?? null,
                    'source_evenement_id' => $entree['source_evenement_id'] ?? null,
                ],
            ];
        }

        $reponse = $this->http()->put('/collections/'.$this->collection().'/points?wait=true', [
            'points' => $points,
        ]);

        if ($reponse->failed()) {
            throw new RuntimeException("Qdrant : upsert refusé ({$reponse->status()}).");
        }
    }

    /**
     * Recherche sémantique filtrée `group_id` (+ `type` éventuel) — top-k
     * injecté ensuite dans le prompt (doc 12 §7, récupération RAG).
     *
     * @return list<array{type: string, titre: string, contenu: string, statut: string|null, score: float}>
     */
    public function rechercher(int $groupeId, string $requete, int $topK = 5, ?string $type = null): array
    {
        $filtres = [
            ['key' => 'group_id', 'match' => ['value' => $groupeId]],
        ];

        if ($type !== null) {
            $filtres[] = ['key' => 'type', 'match' => ['value' => $type]];
        }

        $reponse = $this->http()->post('/collections/'.$this->collection().'/points/search', [
            'vector' => $this->embeddings->vecteur($requete),
            'limit' => $topK,
            'with_payload' => true,
            'filter' => ['must' => $filtres],
        ]);

        if ($reponse->failed()) {
            throw new RuntimeException("Qdrant : recherche refusée ({$reponse->status()}).");
        }

        $extraits = [];

        foreach ($reponse->json('result') ?? [] as $point) {
            $payload = $point['payload'] ?? [];
            $extraits[] = [
                'type' => (string) ($payload['type'] ?? ''),
                'titre' => (string) ($payload['titre'] ?? ''),
                'contenu' => (string) ($payload['contenu'] ?? ''),
                'statut' => $payload['statut'] ?? null,
                'score' => (float) ($point['score'] ?? 0.0),
            ];
        }

        return $extraits;
    }

    /**
     * Supprime TOUS les points d'un groupe (clôture de campagne / groupe
     * vide, doc 05 §6, doc 12 §8) : delete par filtre `group_id` — la
     * collection partagée ne garde aucune trace de la campagne purgée.
     */
    public function purgerGroupe(int $groupeId): void
    {
        $reponse = $this->http()->post('/collections/'.$this->collection().'/points/delete?wait=true', [
            'filter' => [
                'must' => [
                    ['key' => 'group_id', 'match' => ['value' => $groupeId]],
                ],
            ],
        ]);

        if ($reponse->failed()) {
            throw new RuntimeException("Qdrant : purge du groupe {$groupeId} refusée ({$reponse->status()}).");
        }
    }

    private function http(): PendingRequest
    {
        $host = $this->host ?? (string) config('services.qdrant.host', 'qdrant');
        $port = $this->port ?? (int) config('services.qdrant.port', 6333);

        return Http::baseUrl("http://{$host}:{$port}")
            ->timeout(10)
            ->acceptJson();
    }

    private function collection(): string
    {
        return $this->collection ?? (string) config('services.qdrant.collection', 'bible');
    }
}
