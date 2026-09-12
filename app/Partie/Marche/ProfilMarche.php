<?php

declare(strict_types=1);

namespace App\Partie\Marche;

/**
 * Profils de lieu marchand (doc 04 §3) : multiplicateur de prix. Le MJ IA
 * choisit le profil cohérent avec le lieu narratif, le moteur en dérive
 * l'inventaire réel — l'IA n'invente jamais prix ni stock.
 *
 * ⚠ **Les QUATRE profils vendent désormais TOUTES les raretés** (décision de
 * René, 2026-09-11, en partie réelle : tombé sur un « bourg » sans la moindre
 * armure sérieuse — Cotte de mailles et Armure de plates sont `rare`, et la
 * rareté se DÉDUIT du prix (`RareteButin`), donc aucun stock d'objets
 * `commun`/`peu_commun` ne pouvait jamais la contenir). Avant ce correctif,
 * `raretes` était le filtre qui décidait quelles pièces apparaissent en rayon
 * (`PhaseMarche::ouvrir()`, `whereIn('rarete', $config['raretes'])`) — un
 * groupe pouvait traverser toute une campagne sans jamais croiser un
 * marchand vendant du `rare` si le MJ IA choisissait toujours `village`/
 * `bourg`. La clé RESTE (elle a toujours un lecteur, et une divergence future
 * entre profils n'aurait qu'à y revenir), mais les quatre listes sont
 * désormais identiques.
 *
 * ⚠ **Le multiplicateur, lui, NE BOUGE PAS** : René n'a pas demandé de le
 * retirer, et c'est la couleur du LIEU (le marché noir se paie plus cher,
 * pas parce qu'il a plus de choix). Choix MVP (doc 04 §3 / M4 — prix
 * statiques) : le multiplicateur « volatil » du marché noir (×0,8 à ×1,5)
 * reste SIMPLIFIÉ en valeur fixe ×1,2 (fluctuations en phase 2).
 *
 * ⚠ **`STOCKS` (quantité par pièce) n'est PAS touché** — décision prise faute
 * de demande contraire : René a signalé une rareté ABSENTE de l'étal, jamais
 * une quantité insuffisante d'une pièce déjà en rayon. « rare, souvent 1 »
 * (doc 04 §4) est une rareté délibérée de l'OBJET lui-même (une Armure de
 * plates reste un trésor rare même chez le meilleur armurier), distincte de
 * la question réglée ici : quels PROFILS peuvent en vendre. Élargir l'un ne
 * commande pas d'élargir l'autre.
 *
 * - la rareté « unique » n'est JAMAIS à l'achat (butin de quête seulement),
 *   filtrée séparément dans `PhaseMarche::ouvrir()` — inchangé.
 */
final class ProfilMarche
{
    /** Profil de repli quand le MJ IA n'a pas choisi (contrat). */
    public const DEFAUT = 'bourg';

    /**
     * ⚠ LE SEUL PROFIL EN USAGE (René, 2026-09-12) : « force l'utilisation d'un
     * marchand qui vend tout au prix normal ». `PhaseMarche::ouvrir()` ignore le
     * profil demandé et prend celui-ci — tout le catalogue, multiplicateur 1.0.
     *
     * Les trois autres restent déclarés plus bas et ne sont plus atteignables.
     * C'est un choix ÉCRIT, pas un oubli : ils seront la matière de la
     * négociation (« si on intègre la négociation on regardera pour différents
     * types de marchands »), et les effacer obligerait à les réinventer.
     */
    public const UNIQUE = 'cite';

    /**
     * @var array<string, array{multiplicateur: float, raretes: list<string>}>
     */
    public const PROFILS = [
        'village' => ['multiplicateur' => 1.0, 'raretes' => ['commun', 'peu_commun', 'rare']],
        'bourg' => ['multiplicateur' => 1.0, 'raretes' => ['commun', 'peu_commun', 'rare']],
        'cite' => ['multiplicateur' => 1.0, 'raretes' => ['commun', 'peu_commun', 'rare']],
        'marche_noir' => ['multiplicateur' => 1.0, 'raretes' => ['commun', 'peu_commun', 'rare']],
    ];

    /** Stock de départ par rareté — null = illimité (valeurs playtest). */
    public const STOCKS = [
        'commun' => null,
        'peu_commun' => 3,
        'rare' => 1,
    ];

    /** @return list<string> */
    public static function noms(): array
    {
        return array_keys(self::PROFILS);
    }
}
