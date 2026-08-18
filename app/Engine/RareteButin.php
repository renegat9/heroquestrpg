<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * RARETÉ DU BUTIN — comment on choisit la rareté d'une pièce trouvée, et
 * comment cette chance suit la progression du groupe (décision de René,
 * 2026-08-17).
 *
 * Deux règles vivent ici, et une seule fois chacune.
 *
 * 1. **La rareté se DÉDUIT DU PRIX.** Elle était posée à la main, ligne par
 *    ligne, et avait dérivé sans que rien ne le voie : la Hachette à 200 po
 *    était « commune » quand la Baguette à 125 était « peu commune », la Cotte
 *    de mailles à 500 était « rare » et les Brassards à 550 ne l'étaient pas.
 *    Un seuil qu'on relit vaut mieux que trente-neuf valeurs qu'on recopie.
 *
 * 2. **Les chances par rareté suivent le NIVEAU MOYEN du groupe.** Un donjon
 *    de niveau 1 rend surtout du commun ; à la fin d'une campagne, le rare
 *    devient courant. Sans cette pente, une armure de plates pouvait tomber au
 *    premier meuble de la première quête, et un groupe de niveau 8 continuait
 *    de trouver des dagues.
 *
 * ⚠ La rareté `unique` n'est JAMAIS tirée : les artefacts n'ont qu'une source,
 * le coffre désigné de la quête, et ils sont uniques par groupe.
 */
final class RareteButin
{
    /** Les trois raretés qu'un butin peut prendre, du plus commun au plus rare. */
    public const ECHELLE = ['commun', 'peu_commun', 'rare'];

    /**
     * Seuils de PRIX (en pièces d'or) séparant les trois raretés.
     *
     * Bornes hautes INCLUSES : ≤ 150 commun · ≤ 400 peu commun · au-delà rare.
     * Choisies sur le catalogue officiel Hasbro pour que chaque bande porte du
     * monde — 10 pièces communes, ~20 peu communes, ~9 rares.
     */
    private const SEUILS = ['commun' => 150, 'peu_commun' => 400];

    /**
     * POIDS par rareté selon le niveau MOYEN du groupe.
     *
     * La clé est le niveau moyen PLANCHER de la tranche ; on prend la plus haute
     * tranche atteinte. Il n'y a pas de plafond de niveau dans ce jeu (doc 01
     * §5), donc la dernière tranche vaut aussi pour tout ce qui la dépasse — la
     * courbe SATURE au lieu de déborder.
     *
     * Une campagne vise 5 à 8 niveaux (doc 01 §5) : la pente est calée pour que
     * le rare passe de 2 % au départ à un tiers des trouvailles en fin d'arc.
     *
     * @var array<int, array<string, int>>
     */
    private const POIDS = [
        1 => ['commun' => 70, 'peu_commun' => 28, 'rare' => 2],
        3 => ['commun' => 50, 'peu_commun' => 40, 'rare' => 10],
        5 => ['commun' => 35, 'peu_commun' => 45, 'rare' => 20],
        7 => ['commun' => 20, 'peu_commun' => 45, 'rare' => 35],
    ];

    /** Rareté d'une pièce d'après son prix de catalogue. */
    public static function pourPrix(int $prix): string
    {
        if ($prix <= self::SEUILS['commun']) {
            return 'commun';
        }

        return $prix <= self::SEUILS['peu_commun'] ? 'peu_commun' : 'rare';
    }

    /**
     * Poids des trois raretés pour ce niveau moyen.
     *
     * @return array<string, int>
     */
    public static function poids(int $niveauMoyen): array
    {
        $retenu = self::POIDS[1];

        foreach (self::POIDS as $plancher => $poids) {
            if ($niveauMoyen >= $plancher) {
                $retenu = $poids;
            }
        }

        return $retenu;
    }

    /**
     * Tire une rareté au sort parmi celles DISPONIBLES, pondérée par le niveau.
     *
     * ⚠ On restreint aux raretés réellement présentes dans le vivier avant de
     * pondérer : un râtelier d'armes sans arme rare ne doit pas rendre « rien »
     * une fois sur trois à haut niveau, il doit rendre ce qu'il a.
     *
     * @param  list<string>  $disponibles
     */
    public static function tirer(array $disponibles, int $niveauMoyen): ?string
    {
        $poids = array_filter(
            self::poids($niveauMoyen),
            fn (string $r) => in_array($r, $disponibles, true),
            ARRAY_FILTER_USE_KEY,
        );

        $total = array_sum($poids);

        if ($total <= 0) {
            return null;
        }

        $tirage = random_int(1, $total);

        foreach ($poids as $rarete => $p) {
            $tirage -= $p;

            if ($tirage <= 0) {
                return $rarete;
            }
        }

        return array_key_first($poids);
    }
}
