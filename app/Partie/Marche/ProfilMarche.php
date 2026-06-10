<?php

declare(strict_types=1);

namespace App\Partie\Marche;

/**
 * Profils de lieu marchand (doc 04 §3) : raretés accessibles + multiplicateur
 * de prix. Le MJ IA choisit le profil cohérent avec le lieu narratif, le
 * moteur en dérive l'inventaire réel — l'IA n'invente jamais prix ni stock.
 *
 * Choix MVP (doc 04 §3 / M4 — prix statiques) :
 * - le multiplicateur « volatil » du marché noir (×0,8 à ×1,5) est SIMPLIFIÉ
 *   en valeur fixe ×1,2 au MVP (fluctuations en phase 2) ;
 * - la rareté « illicite » du marché noir n'existe pas encore au catalogue
 *   (enum objets : commun/peu_commun/rare/unique) — le profil sert donc le
 *   rare seul en attendant ;
 * - stocks de départ playtest par rareté : commun = illimité (null),
 *   peu_commun = 3, rare = 1 (doc 04 §4 : « limité, souvent 1 ») ;
 * - la rareté « unique » n'est JAMAIS à l'achat (butin de quête seulement).
 */
final class ProfilMarche
{
    /** Profil de repli quand le MJ IA n'a pas choisi (contrat). */
    public const DEFAUT = 'bourg';

    /** @var array<string, array{multiplicateur: float, raretes: list<string>}> */
    public const PROFILS = [
        'village' => ['multiplicateur' => 1.2, 'raretes' => ['commun']],
        'bourg' => ['multiplicateur' => 1.0, 'raretes' => ['commun', 'peu_commun']],
        'cite' => ['multiplicateur' => 1.0, 'raretes' => ['commun', 'peu_commun', 'rare']],
        'marche_noir' => ['multiplicateur' => 1.2, 'raretes' => ['rare']],
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
