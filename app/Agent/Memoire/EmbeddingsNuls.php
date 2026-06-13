<?php

declare(strict_types=1);

namespace App\Agent\Memoire;

/**
 * Embeddings factices — repli de dev quand VOYAGE_API_KEY est absente
 * (le fournisseur réel est Voyage AI, voir EmbeddingsVoyage).
 *
 * Implémentation "hashing trick" : sac de mots projeté sur un vecteur de
 * dimension fixe via crc32, puis normalisé L2. C'est DÉTERMINISTE (un même
 * texte donne toujours le même vecteur) et ça permet à BibleQdrant de
 * fonctionner de bout en bout (upsert + search), mais la similarité n'est
 * que LEXICALE (mots partagés), pas sémantique. À remplacer sans changer
 * la dimension de collection… ou en recréant la collection.
 */
final class EmbeddingsNuls implements Embeddings
{
    public const DIMENSION = 384;

    public function dimension(): int
    {
        return self::DIMENSION;
    }

    public function vecteur(string $texte, bool $requete = false): array
    {
        $vecteur = array_fill(0, self::DIMENSION, 0.0);

        $mots = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($texte), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($mots as $mot) {
            if (mb_strlen($mot) < 2) {
                continue; // ignore les mots d'une lettre (bruit)
            }
            $index = crc32($mot) % self::DIMENSION;
            $vecteur[$index] += 1.0;
        }

        // Normalisation L2 (la distance cosinus de Qdrant la suppose).
        $norme = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vecteur)));

        if ($norme < 1e-9) {
            // Texte vide : vecteur unitaire arbitraire plutôt que vecteur nul
            // (le cosinus n'est pas défini sur le vecteur nul).
            $vecteur[0] = 1.0;

            return $vecteur;
        }

        return array_map(fn (float $v) => $v / $norme, $vecteur);
    }
}
