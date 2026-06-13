<?php

declare(strict_types=1);

namespace App\Agent\Memoire;

/**
 * Fournisseur d'embeddings pour la bible RAG (doc 11 §6).
 *
 * Fournisseur retenu : Voyage AI (EmbeddingsVoyage) dès que VOYAGE_API_KEY
 * est renseignée ; sinon repli sur EmbeddingsNuls (factice, lexical) pour
 * que la bible reste fonctionnelle en dev. Binding dans AppServiceProvider.
 */
interface Embeddings
{
    /** Dimension des vecteurs produits (fixe la collection Qdrant). */
    public function dimension(): int;

    /**
     * Encode un texte en vecteur.
     *
     * @param  bool  $requete  true pour un texte de RECHERCHE (query),
     *                         false pour un texte INDEXÉ (document) —
     *                         certains fournisseurs optimisent les deux côtés.
     * @return list<float>
     */
    public function vecteur(string $texte, bool $requete = false): array;
}
