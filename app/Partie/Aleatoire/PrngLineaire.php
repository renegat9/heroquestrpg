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
        $this->etat = $graine & 0x7fffffff;
    }

    /** Entier pseudo-aléatoire suivant. */
    public function suivant(): int
    {
        return $this->etat = ($this->etat * 1103515245 + 12345) & 0x7fffffff;
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
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function melanger(array $items): array
    {
        $items = array_values($items);

        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->suivant() % ($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
