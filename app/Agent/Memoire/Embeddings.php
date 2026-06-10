<?php

declare(strict_types=1);

namespace App\Agent\Memoire;

/**
 * Fournisseur d'embeddings pour la bible RAG (doc 11 §6).
 *
 * Le fournisseur réel (API distante au MVP, modèle local en phase 2 —
 * doc 11 §14.2) n'est pas encore choisi : l'implémentation par défaut
 * est EmbeddingsNuls (factice, déterministe). Le binding se fait dans
 * AppServiceProvider — remplacer là-bas quand le fournisseur sera retenu.
 */
interface Embeddings
{
    /** Dimension des vecteurs produits (fixe la collection Qdrant). */
    public function dimension(): int;

    /**
     * Encode un texte en vecteur.
     *
     * @return list<float>
     */
    public function vecteur(string $texte): array;
}
