<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\JournalCombatDiffuse;
use App\Models\Groupe;
use App\Support\Journal;

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

    /**
     * Une résolution de `ResolveurTour::resoudre()` est-elle EN COURS ? Tant que
     * oui, les annonces attendent d'être rendues dans le résultat ; sinon (un
     * menu qui s'ouvre, une réaction qui se règle) personne ne videra ce tampon
     * à temps : {@see self::annoncer()} diffuse alors tout de suite.
     */
    private bool $ouvert = false;

    /** @param  array<string, mixed>  $payload  même forme que l'événement journalisé. */
    public function ajouter(array $payload): void
    {
        $this->entrees[] = $payload;
    }

    /** Ouvre la fenêtre d'une résolution : `resoudre()` la ferme en vidant. */
    public function ouvrir(): void
    {
        $this->ouvert = true;
    }

    /**
     * LA PORTE d'un effet automatique qui s'écoule ou se rompt sans qu'aucune
     * action ne le retourne (fin d'une condition à durée, tic de poison, sort
     * regagné, apparition d'un boss, résolution d'un vote) : le journal pour le
     * REJEU, et le fil en direct — par le résultat de la résolution en cours,
     * ou diffusé tout de suite s'il n'y en a pas.
     *
     * ⚠ Le type du payload doit figurer dans `JournalCombat::TYPES` (le test de
     * complétude l'impose) : c'est ce qui garantit qu'il rend une vraie phrase.
     *
     * @param  array<string, mixed>  $payload  `{type, texte?, …}`
     * @param  array<string, mixed>|null  $acteur
     */
    public function annoncer(Groupe $groupe, array $payload, ?array $acteur = null): void
    {
        $evenement = Journal::ajouter($groupe, 'action', $payload, $acteur);

        if ($this->ouvert) {
            $this->entrees[] = $payload;

            return;
        }

        $lignes = app(JournalCombat::class)->depuisResultat($payload, (string) ($acteur['nom'] ?? 'Le maître du jeu'));

        if ($lignes !== []) {
            broadcast(new JournalCombatDiffuse($groupe, $lignes, (int) $evenement->sequence));
        }
    }

    /**
     * Rend les annonces posées depuis le dernier vidage, puis le vide — et ferme
     * la fenêtre de résolution.
     *
     * @return list<array<string, mixed>>
     */
    public function vider(): array
    {
        $entrees = $this->entrees;
        $this->entrees = [];
        $this->ouvert = false;

        return $entrees;
    }
}
