<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Vocabulaire des RÉACTIONS DU MJ AU SORT D'UN HÉROS (René, 2026-10-15) — la
 * couture unique `App\Partie\ReactionsMj`, appelée par
 * `ResolveurTour::lancerSort()`, point de passage commun du sort connu et du
 * parchemin.
 *
 * Deux familles y vivent, et ce fichier ne nomme que la première :
 *
 *  1. Les CAPACITÉS d'un monstre nommé (`monstres.capacites.reactions_defense[]`) :
 *     « may use each ability once (no action required) » — usage unique par
 *     rencontre, compté dans `instances_monstres.capacites_reactives_utilisees`
 *     (colonne, donc dans le snapshot), jamais en cache.
 *  2. Les SORTS DE DREAD de type `reaction` (`MotsClesSortDread::REACTIONS`) :
 *     *Dispel* et *Mirror Magic*, qui coûtent un usage du budget du lanceur.
 *
 * Même discipline que les autres vocabulaires fermés : un nom n'entre dans
 * `CAPACITES` qu'avec son lecteur, et `ReactionsMjTest` confronte ce registre
 * aux données DANS LES DEUX SENS — rien de déclaré sans monstre qui le porte,
 * rien de porté sans déclaration. Le fichier du lecteur doit nommer la clé.
 *
 * `ignore_degats_attaque` y figure aussi, bien qu'il soit lu ailleurs
 * (`MoteurDegats::infligerAMonstre()`, au coup fatal) : c'est la SEULE liste de
 * ce que `reactions_defense` peut contenir. Avant ce registre, le test de
 * bestiaire la tenait en dur, à côté.
 */
final class MotsClesReactionMj
{
    /** *Resilience* (Gruzbella), *Demon Wings* (Gretzl) — lu par `MoteurDegats`. */
    public const IGNORE_DEGATS_ATTAQUE = 'ignore_degats_attaque';

    /**
     * *Dispel* (Gretzl, Jungles of Delthrak p. 35) : « Use when Gretzl would be
     * the target of a spell to cancel that spell. » Une seule fois, sans action,
     * sans jet — la capacité d'un monstre, pas la carte de Dread du même nom
     * (qui lance un dé de chaque côté : `MotsClesSortDread::REACTIONS`).
     */
    public const ANNULE_SORT_CIBLANT = 'annule_sort_ciblant';

    /**
     * *Break* (Gruzbella, Against the Ogre Horde p. 25) : « Use at any time. End
     * the effects of any one spell active on Gruzbella. » Une seule fois, sans
     * action. ⚠ Elle ne PRÉVIENT pas le sort — elle le défait après coup : c'est
     * la seule réaction de ce registre lue APRÈS l'effet (`apresSortDeHeros()`).
     */
    public const ROMPT_SORT_ACTIF = 'rompt_sort_actif';

    /**
     * @var array<string, array{lecteur: string, libelle: string}>
     */
    public const CAPACITES = [
        self::IGNORE_DEGATS_ATTAQUE => [
            'lecteur' => 'App\Partie\MoteurDegats::infligerAMonstre',
            'libelle' => 'Ignore tous les dégâts d\'une attaque (Resilience, Demon Wings)',
        ],
        self::ANNULE_SORT_CIBLANT => [
            'lecteur' => 'App\Partie\ReactionsMj::annulerSortCiblant',
            'libelle' => 'Annule le sort d\'un héros qui la prend pour cible (Dispel de Gretzl)',
        ],
        self::ROMPT_SORT_ACTIF => [
            'lecteur' => 'App\Partie\ReactionsMj::apresSortDeHeros',
            'libelle' => 'Met fin aux effets du sort qui vient de la prendre pour cible (Break de Gruzbella)',
        ],
    ];

    /**
     * Les réactions que les livrets NOMMENT et que ce moteur ne sait pas jouer —
     * une dette écrite, jamais un oubli. Aucune donnée ne doit les employer
     * (`ReactionsMjTest`), et chacune dit exactement ce qui lui manque.
     *
     * @var array<string, string>
     */
    public const NON_IMPLEMENTEES = [
        'redirige_attaque' => '*Deflect* (Gruzbella, Against the Ogre Horde p. 25) : « Use when Gruzbella would be '
            .'the target of an ATTACK » — une ATTAQUE, pas un sort : elle se joue à `ResolveurTour::frapper()` '
            .'(le choix de la cible), non à la couture des sorts, et suppose de faire frapper un héros par un autre '
            .'avec les dés de l\'attaquant et la défense de la nouvelle victime.',
        'consomme_magie' => '*Consume Magic* (Magrian, Rise of the Dread Moon p. 33) : annule N\'IMPORTE QUEL sort '
            .'qu\'un héros vient de lancer et rend 2 Body à Magrian — la couture sait déjà annuler ; ce qui manque '
            .'est Magrian lui-même, créature nommée non semée (ses trois autres capacités — *Terror*, *Reflection*, '
            .'*Shift Reality* — et son leurre d\'image-miroir n\'ont aucun lecteur : le semer à un quart de sa fiche '
            .'trahirait la source).',
    ];
}
