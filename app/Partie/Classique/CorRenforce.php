<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\GroupeMercenaire;
use App\Models\Quete;
use App\Partie\Salles;

/**
 * LE COR RENFORCÉ — lot E2, *First Light* q. 9 C (FL-Q p. 29) : « Hearthkin Horn – Skeletons summoned by the horn roll 2 additional
 * Attack and Defend dice in this room. »
 *
 * `regles.cor_renforce: {salle: id, attaque: n, defense: n}` — un Squelette Hearthkin (le mercenaire du Cor) qui se tient dans la
 * salle ajoute ces dés à son attaque (`ResolveurTour::resoudreAttaqueDAllie()`) et à sa défense
 * (`resoudreAttaqueMonstreSurAllie()`).
 */
final class CorRenforce
{
    public const SQUELETTE = 'Squelette Hearthkin';

    /** @return int les dés de plus pour `attaque` ou `defense` */
    public static function bonus(Quete $quete, GroupeMercenaire $allie, string $stat): int
    {
        if (! $quete->estConstruite() || $allie->position_x === null || $allie->mercenaire?->nom !== self::SQUELETTE) {
            return 0;
        }
        $regle = data_get($quete->documentConstruit(), 'regles.cor_renforce');
        if (! is_array($regle)) {
            return 0;
        }
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $index = collect($salles)->search(fn ($s) => ($s['id'] ?? null) === ($regle['salle'] ?? null));

        return $index !== false && Salles::indexDe($salles, (int) $allie->position_x, (int) $allie->position_y) === $index
            ? max(0, (int) ($regle[$stat] ?? 0)) : 0;
    }
}
