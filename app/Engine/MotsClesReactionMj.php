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
     * *Consume Magic* (Magrian, Rise of the Dread Moon p. 33) : « Use immediately after a hero casts a spell
     * on their turn. The spell is canceled. Magrian regains 2 Body Points. » Une seule fois, sans action.
     * Lue à la couture des sorts de héros, comme *Dispel* — mais sans cible : elle vise le sort lui-même,
     * celui qui prend le camp du MJ, et non une figure qui serait la cible.
     */
    public const CONSOMME_MAGIE = 'consomme_magie';

    /**
     * *Deflect* (Gruzbella, Against the Ogre Horde p. 25) : « Use when Gruzbella would be the target of an
     * attack. You can instead direct the attack to any one hero in Gruzbella's surrounding 10 squares. » Une
     * ATTAQUE détournée vers un héros : c'est `ResolveurTour::frapper()` qui la lit (le choix de la cible), et
     * le héros visé frappe avec sa propre défense (`HerosJoueParLeMj::frapperUnHeros()`).
     */
    public const REDIRIGE_ATTAQUE = 'redirige_attaque';

    /**
     * *Reflection* (Magrian, Rise of the Dread Moon p. 33) : « Use immediately after Magrian receives damage.
     * Choose a hero in Magrian's line of sight; that hero takes the damage instead. » Lue au point de passage
     * des dégâts d'un monstre, `MoteurDegats::infligerAMonstreBrut()`, au coup qui achèverait sinon la figure
     * (politique écrite de la Résilience : jamais gaspillée sur une égratignure).
     */
    public const REFLEXION = 'reflexion';

    /**
     * *Terror* (Magrian, Rise of the Dread Moon p. 33) : « Use at the start of any one hero's turn. For the
     * remainder of that hero's turn, Magrian cannot be the target of spells or attacks. » Posée au début du
     * tour d'un héros (`ResolveurTour::ouvrirTourDesHeros()`), levée à la fin de SON tour.
     */
    public const TERREUR = 'terreur';

    /**
     * *Shift Reality* (Magrian, Rise of the Dread Moon p. 33) : « Use at the start of Zargon's turn. Magrian
     * casts one Dread spell it knows. » Lue au début du tour du MJ (`ResolveurTour::phaseMonstres()`), par
     * `MoteurDread::realiteDecalee()`, qui lance un sort de son répertoire par le chemin des sorts de Dread.
     */
    public const REALITE_DECALEE = 'realite_decalee';

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
        self::CONSOMME_MAGIE => [
            'lecteur' => 'App\Partie\ReactionsMj::consommerMagie',
            'libelle' => 'Annule le sort d\'un héros qui frappe le camp du MJ, et rend 2 PV de Body (Consume Magic de Magrian)',
        ],
        self::REDIRIGE_ATTAQUE => [
            'lecteur' => 'App\Partie\ResolveurTour::frapper',
            'libelle' => 'Détourne l\'attaque qui la vise vers un héros dans ses 10 cases (Deflect de Gruzbella)',
        ],
        self::REFLEXION => [
            'lecteur' => 'App\Partie\MoteurDegats::infligerAMonstreBrut',
            'libelle' => 'Renvoie sur un héros en vue le coup qui l\'achèverait (Reflection de Magrian)',
        ],
        self::TERREUR => [
            'lecteur' => 'App\Partie\ResolveurTour::ouvrirTourDesHeros',
            'libelle' => 'La rend intouchable par sorts et coups pour le tour d\'un héros (Terror de Magrian)',
        ],
        self::REALITE_DECALEE => [
            'lecteur' => 'App\Partie\MoteurDread::realiteDecalee',
            'libelle' => 'Lance un sort de Dread de son répertoire au début du tour du MJ (Shift Reality de Magrian)',
        ],
    ];

    /**
     * Les réactions que les livrets NOMMENT et que ce moteur ne sait pas encore jouer — une dette écrite, jamais
     * un oubli. Vide depuis le chantier EXT-MAGRIAN (2026-10-23) : *Deflect* et *Consume Magic* y sont portés,
     * et les quatre capacités de Magrian aussi. Le registre reste là : une nouvelle réaction nommée par un livret
     * et non portée s'y écrit, avec ce qui lui manque, plutôt que de disparaître.
     *
     * @var array<string, string>
     */
    public const NON_IMPLEMENTEES = [];
}
