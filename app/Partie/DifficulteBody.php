<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Quete;

/**
 * PLAFOND DES JETS DE BODY — point de passage unique (René, 2026-08-24).
 *
 * Aucune difficulté de Body ne dépasse jamais le meilleur `attribut_body` des
 * héros ENGAGÉS dans la quête. La règle tient en une phrase : un jet que la
 * compagnie ne peut mathématiquement pas gagner n'est pas un obstacle, c'est
 * une impasse déguisée en choix. Un Seigneur ogre à 10 PV de Body retombe donc
 * à 4 face à une troupe qui compte un barbare — improbable à bousculer, plus
 * impossible.
 *
 * ⚠ **La valeur BRUTE est stockée, le plafond s'applique à la GÉNÉRATION DU
 * MENU.** Le plafond est mobile : il monte quand un héros achète *Colosse*, il
 * descend quand le costaud quitte le groupe. Figer la difficulté au placement
 * d'un levier, ou dans le catalogue d'un meuble, la ferait mentir dès le niveau
 * suivant. Les données gardent donc la difficulté voulue par le contenu, et
 * `MenuMoteur` publie la difficulté **effective** — celle que le joueur lit dans
 * le libellé et que le résolveur applique.
 *
 * ⚠ Sans quête, sans héros, ou sur un plancher absurde : on rend la valeur
 * brute (fail open). Une donnée de référence manquante ne doit jamais durcir le
 * jeu en silence, c'est la même prudence que les listes de maîtrises vides.
 */
final class DifficulteBody
{
    /** Difficulté minimale d'un jet de compétence (`Engine\JetCompetence`). */
    private const PLANCHER = 1;

    public static function plafonnee(?Quete $quete, int $brute): int
    {
        $brute = max(self::PLANCHER, $brute);
        $heros = self::bodyDesHeros($quete);

        // ⚠ AUCUN héros à interroger (pas de quête, ou une quête sans état de
        // personnage) : on rend la valeur brute. Une donnée de référence
        // manquante ne doit jamais durcir NI adoucir le jeu en silence — c'est
        // la même prudence que les listes de maîtrises vides, qui valent
        // « aucune restriction ».
        //
        // ⚠ À distinguer d'une compagnie dont le meilleur Body VAUT 0 : là, la
        // réponse existe, et le plafond retombe sur le plancher d'un jet de
        // compétence (1). Un héros à 0 en Body échouera — « 0 dé = 0 succès »
        // (doc 09 §2) —, mais la difficulté reste légale pour `JetCompetence`,
        // qui refuse tout ce qui est sous 1.
        if ($heros === []) {
            return $brute;
        }

        return min($brute, max(self::PLANCHER, max($heros)));
    }

    /**
     * Le meilleur `attribut_body` des héros engagés, ou 0 si la question n'a pas
     * de réponse. ⚠ Les deux cas se confondent ici : `plafonnee()` passe par
     * `bodyDesHeros()` justement pour les séparer.
     */
    public static function meilleurBody(?Quete $quete): int
    {
        $heros = self::bodyDesHeros($quete);

        return $heros === [] ? 0 : max($heros);
    }

    /**
     * Les `attribut_body` des héros engagés dans la quête — liste VIDE quand il
     * n'y a personne à interroger.
     *
     * ⚠ Un héros TOMBÉ compte quand même : il se relève, et le plafond ne doit
     * pas osciller au gré des chutes — une porte ne devient pas plus dure parce
     * que le barbare est à terre.
     *
     * @return list<int>
     */
    private static function bodyDesHeros(?Quete $quete): array
    {
        if ($quete === null) {
            return [];
        }

        return $quete->etatsPersonnages()
            ->with('personnage')
            ->get()
            ->map(fn ($etat) => (int) ($etat->personnage?->attribut_body ?? 0))
            ->values()
            ->all();
    }
}
