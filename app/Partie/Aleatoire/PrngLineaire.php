<?php

declare(strict_types=1);

namespace App\Partie\Aleatoire;

/**
 * Générateur pseudo-aléatoire linéaire congruentiel, DÉTERMINISTE à graine
 * égale — extrait de `AssembleurCarte::creerPRNG()` pour être partagé avec le
 * deck de fouille (`App\Partie\Fouille\DeckFouille`).
 *
 * **Pourquoi pas `LanceurDes`** : ce PRNG sert à des tirages de PRÉPARATION
 * (composition d'une carte, mélange d'un deck), pas à des jets de jeu.
 * Consommer des d6 au démarrage de quête décalerait la file de dés figée par
 * `desFiges()` dans tous les tests existants — `AssembleurCarte::roulerElite()`
 * prend déjà exactement cette précaution.
 *
 * Les constantes sont celles d'origine : les cartes déjà générées restent
 * identiques (couvert par `AssembleurCarteSpawnsTest`).
 */
final class PrngLineaire
{
    private int $etat;

    public function __construct(int $graine = 0)
    {
        $this->etat = $graine & 0x7FFFFFFF;
    }

    /** Entier pseudo-aléatoire suivant. */
    public function suivant(): int
    {
        return $this->etat = ($this->etat * 1103515245 + 12345) & 0x7FFFFFFF;
    }

    /** Entier dans [$min, $max] inclus. */
    public function entre(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + $this->suivant() % ($max - $min + 1);
    }

    /**
     * Mélange Fisher-Yates. Rend une NOUVELLE liste ; l'entrée n'est pas touchée.
     *
     * ⚠ On tire l'indice sur les bits de POIDS FORT (`>> 15`), jamais sur
     * `suivant() % n` directement. Les bits de poids faible d'un LCG à module
     * 2^31 sont périodiques : `% 2` donne `010101…`, `% 4` donne `0123 0123…`.
     * Fisher-Yates finissant sur les petits modules, les dernières permutations
     * n'en étaient pas — et surtout la TÊTE de liste restait quasi intacte.
     * Mesuré avant correctif (test de jeu 2026-08-05) : la 1re carte de fouille
     * sortait `gemme` dans 25,6 % des donjons contre 8,3 % attendus (l'ordre de
     * construction du deck), et le pool de pièges — mélangé en un seul tas
     * ([...couloirs, ...salles]) précisément pour corriger le « 58 pièges sur
     * 61 en couloir » — replaçait encore 62,7 % des pièges en couloir pour 50 %
     * attendus. Le mélange était donc décoratif là où il comptait le plus.
     *
     * `suivant()` et ses constantes restent INCHANGÉS : les cartes déjà
     * stockées ne bougent pas, seule la qualité du tirage change.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function melanger(array $items): array
    {
        $items = array_values($items);

        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = ($this->suivant() >> 15) % ($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
