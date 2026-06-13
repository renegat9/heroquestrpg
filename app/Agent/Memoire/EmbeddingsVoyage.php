<?php

declare(strict_types=1);

namespace App\Agent\Memoire;

use App\Agent\Exceptions\AppelLlmException;
use Illuminate\Support\Facades\Http;

/**
 * Embeddings via l'API Voyage AI (fournisseur retenu pour la bible RAG,
 * doc 11 §6 / §14.2 — recommandé par Anthropic, qui n'a pas d'API
 * d'embeddings propre).
 *
 * API : POST {base_url}/v1/embeddings, Authorization: Bearer.
 * `input_type` distingue les textes INDEXÉS (`document`, upsert de bible)
 * des textes de RECHERCHE (`query`) — Voyage optimise les deux côtés.
 *
 * Config (config/services.php → voyage) : api_key, model (voyage-3.5),
 * dimension (1024 — doit rester stable, elle fixe la collection Qdrant :
 * en changer impose de recréer la collection), base_url, timeout.
 */
final class EmbeddingsVoyage implements Embeddings
{
    public function dimension(): int
    {
        return (int) config('services.voyage.dimension', 1024);
    }

    public function vecteur(string $texte, bool $requete = false): array
    {
        $reponse = Http::withToken((string) config('services.voyage.api_key'))
            ->timeout((int) config('services.voyage.timeout', 30))
            ->retry(2, 500, throw: false)
            ->post(rtrim((string) config('services.voyage.base_url', 'https://api.voyageai.com'), '/').'/v1/embeddings', [
                'input' => [$texte],
                'model' => (string) config('services.voyage.model', 'voyage-3.5'),
                'input_type' => $requete ? 'query' : 'document',
                'output_dimension' => $this->dimension(),
            ]);

        if ($reponse->failed()) {
            throw new AppelLlmException(
                "Appel Voyage AI échoué ({$reponse->status()}) : ".mb_substr($reponse->body(), 0, 300)
            );
        }

        $vecteur = $reponse->json('data.0.embedding');

        if (! is_array($vecteur) || count($vecteur) !== $this->dimension()) {
            throw new AppelLlmException('Réponse Voyage AI invalide : vecteur absent ou dimension inattendue.');
        }

        return array_map(floatval(...), $vecteur);
    }
}
