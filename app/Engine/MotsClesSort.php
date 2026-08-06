<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Vocabulaire des sorts : CIBLE, COÛT, RÉSISTANCE.
 *
 * Pendant de `DureeEffet` (qui, lui, est partagé avec les objets). Même
 * principe et même raison d'être : un effet de sort est une **donnée**, donc
 * chaque valeur qu'il porte doit être un mot déclaré, câblé et documenté —
 * sans quoi elle promet au joueur une règle que le moteur n'applique pas.
 *
 * Le projet a déjà dû retirer `attaque_second_rang` et `ligne_de_vue` pour
 * cette raison. Référence complète : `reference/19_mots_cles_effets.md` §7.
 */
final class MotsClesSort
{
    // ---------------------------------------------------------------- CIBLE

    /** Le lanceur lui-même — aucune liste de cibles n'est proposée. */
    public const CIBLE_SOI = 'soi';

    /**
     * Un héros de la quête, **LANCEUR COMPRIS** — « This spell may be cast on
     * any one hero, including yourself » (Heal Body, LR p. 8), et « un sort
     * peut cibler soi-même, un autre héros, ou un monstre » (LR p. 14).
     *
     * Un `heros_ou_soi` a existé jusqu'au 2026-08-06 : il produisait EXACTEMENT
     * la même liste que `heros`, la distinction ne recouvrait donc aucune règle.
     * Retiré plutôt que laissé — deux mots pour une seule idée finissent par se
     * contredire.
     */
    public const CIBLE_HEROS = 'heros';

    /** Un monstre. */
    public const CIBLE_MONSTRE = 'monstre';

    /**
     * Plusieurs monstres d'une zone.
     *
     * ⚠ **NON IMPLÉMENTÉ** : `ciblesLegales()` ne distingue aucune zone et
     * `sortMental()` résout sur UNE cible. Un sort marqué ainsi se comporte
     * aujourd'hui comme `CIBLE_MONSTRE`. Déclaré ici pour que le mot existe et
     * soit repérable — pas pour laisser croire que la mécanique tourne. Voir
     * `NON_IMPLEMENTES`.
     */
    public const CIBLE_MONSTRES_ZONE = 'monstres_zone';

    /**
     * ⚠ Pour un sort de **dégâts ou mental**, `cible` documente l'intention,
     * il ne RESTREINT pas : le tir ami est délibéré (doc 02 §5, S3), donc la
     * liste légale contient monstres ET héros en ligne de vue. La restriction
     * ne s'applique qu'aux sorts **utilitaires**.
     */
    public const CIBLES = [
        self::CIBLE_SOI,
        self::CIBLE_HEROS,
        self::CIBLE_MONSTRE,
        self::CIBLE_MONSTRES_ZONE,
    ];

    // ----------------------------------------------------------------- COÛT
    //
    // Il n'y a PLUS de vocabulaire de coût. `deplacement_du_tour` avait été
    // introduit pour Traverser la Pierre, qui le déclarait sans que personne ne
    // le lise. Le texte officiel a tranché autrement (Witch Lord) : le sort ne
    // COÛTE pas le déplacement, il le TRANSFORME — « traverse les murs sur tout
    // le déplacement du jet ». Facturer l'allonce rendait le sort inutilisable.
    // Le mot et son lecteur ont donc été retirés le 2026-08-06 plutôt que
    // laissés sans usage. Le rétablir est trivial si un sort en a besoin.

    // ----------------------------------------------------------- RÉSISTANCE

    /** La cible résiste par son Mind (Engine\SortMental, binaire — Mind 0 immunisé). */
    public const RESISTANCE_JET_MIND = 'jet_mind';

    public const RESISTANCES = [self::RESISTANCE_JET_MIND];

    // ------------------------------------------------------------------ ---

    /**
     * Mots déclarés mais dont la MÉCANIQUE N'EXISTE PAS. Le catalogue peut les
     * porter — le moteur les ignore, et le guide ne doit pas les annoncer comme
     * acquis. Toute entrée d'ici est une dette explicite, pas un oubli.
     *
     * @var array<string, string>
     */
    public const NON_IMPLEMENTES = [
        self::CIBLE_MONSTRES_ZONE => 'Aucun ciblage de surface : le sort touche une seule cible.',
        'invocation_ephemere' => 'Aucun mécanisme d\'invocation : Génie reste un sort de dégâts.',
    ];

    public static function estNonImplemente(string $mot): bool
    {
        return array_key_exists($mot, self::NON_IMPLEMENTES);
    }
}
