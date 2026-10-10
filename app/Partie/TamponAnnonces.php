<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * Les effets AUTOMATIQUES nés pendant une résolution qu'aucune action ne
 * RETOURNE — un objet volé qui se perd faute d'être vu, une détection qui
 * révèle des pièges ou des passages au passage du héros.
 *
 * ⚠ C'est la quatrième fois que la même faute est corrigée (`AnnoncesTalents`,
 * `TamponCharges`, `TamponFaveurs`, ici) : le fil en direct est bâti sur le
 * RÉSULTAT de l'action, jamais sur les événements journalisés à côté. Un effet
 * qui se contente de `Journal::ajouter()` n'apparaît qu'à la reconnexion — « un
 * effet automatique que rien n'annonce est injouable ». Plutôt qu'un cinquième
 * tampon, celui-ci est GÉNÉRIQUE : il porte n'importe quel payload d'action
 * (`{type, …}`), que `JournalCombat::ligneType()` sait rendre comme s'il avait
 * été retourné — le type doit donc figurer dans `JournalCombat::TYPES`, ce que
 * le test de complétude impose.
 *
 * Même patron que ses trois aînés : SINGLETON (pas `scoped`), vidé par
 * `ResolveurTour::resoudre()` à l'entrée ET à la sortie, rendu dans le résultat
 * sous `annonces_automatiques`.
 */
final class TamponAnnonces
{
    /** @var list<array<string, mixed>> */
    private array $entrees = [];

    /** @param  array<string, mixed>  $payload  même forme que l'événement journalisé. */
    public function ajouter(array $payload): void
    {
        $this->entrees[] = $payload;
    }

    /**
     * Rend les annonces posées depuis le dernier vidage, puis le vide.
     *
     * @return list<array<string, mixed>>
     */
    public function vider(): array
    {
        $entrees = $this->entrees;
        $this->entrees = [];

        return $entrees;
    }
}
