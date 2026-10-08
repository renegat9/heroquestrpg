<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * Le jet d'un héros vient de tomber, et le serveur doit attendre sa réponse
 * (*Vision du futur*) AVANT d'appliquer quoi que ce soit.
 *
 * Lancée par `ResolveurTour::frapper()` entre le jet et son application, et
 * attrapée par `ResolveurTour::resoudre()` HORS de sa transaction : l'action
 * entière est annulée — rien n'a été écrit —, l'offre est déposée avec ce qu'il
 * faut pour la REJOUER, et c'est la réponse du joueur (ou l'expiration de la
 * fenêtre) qui la rejoue. Une exception plutôt qu'un retour : `frapper()` a
 * quatre appelants et trente variables locales, aucune continuation n'est
 * sérialisable — mais l'action, elle, est rejouable à l'identique tant qu'on lui
 * redonne les dés qui sont tombés (`Combat::resoudreAttaque()`, volées imposées).
 *
 * @see \App\Partie\MoteurReactions::suspendreAttaque()
 */
final class JetEnAttente extends \RuntimeException
{
    /**
     * @param  list<string>  $facesAttaque  faces du jet d'attaque du héros (valeurs de `FaceDeCombat`)
     * @param  list<string>  $facesDefense  faces de défense du monstre
     * @param  array<string, mixed>  $resume  de quoi décrire le jet à la manette
     */
    public function __construct(
        public readonly string $jet,
        public readonly int $rang,
        public readonly array $facesAttaque,
        public readonly array $facesDefense,
        public readonly array $resume,
    ) {
        parent::__construct('Jet en attente d\'une relance.');
    }
}
