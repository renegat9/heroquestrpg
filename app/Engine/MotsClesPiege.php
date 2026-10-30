<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * VOCABULAIRE FERMÉ de `pieges.effet` — le même garde-fou que `MotsClesMobilier`, `MotsClesTerrain`, `MotsClesDanger`.
 *
 * Jusqu'ici les clés d'effet des pièges n'étaient déclarées NULLE PART : une ligne de `PiegeSeeder` pouvait porter une clé
 * que plus aucun moteur ne lisait, et le catalogue l'aurait acceptée sans un mot. Le registre rend la liste explicite, et
 * le test `MotsClesPiegeTest` la confronte aux lignes RÉELLES DANS LES DEUX SENS : toute clé semée est déclarée ICI avec un
 * lecteur nommé (qui doit exister, et dont le PREMIER lecteur doit LIRE la clé : `data_get`, `effet[]`, `issue[]`), et toute clé déclarée est semée — ou figure dans
 * `SANS_DONNEE` avec sa raison.
 *
 * Ordre maison : vocabulaire → lecteur → test EN JEU → donnée. Une clé ajoutée ici est lue par le lecteur qu'elle nomme,
 * jamais par une copie.
 *
 * `SANS_LECTEUR` est une DETTE NOMMÉE : une clé semée que plus aucun code ne lit. Elle n'est pas supprimée en silence (le
 * retrait d'une valeur déjà en base passe par une migration, et c'est un choix de René, pas une omission). Le test exige
 * qu'elle reste déclarée ici, avec sa raison.
 *
 * @see \App\Partie\MoteurPieges
 */
final class MotsClesPiege
{
    /**
     * Chaque clé d'effet, son ou ses lecteurs (`Classe::méthode()`, joints par ` + `) et ce que la clé fait au joueur.
     *
     * @var array<string, array{lecteur: string, libelle: string}>
     */
    public const VOCABULAIRE = [
        'degats_pv_body' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencher() + App\Partie\MoteurPieges::declencherEphemere()',
            'libelle' => 'perd un nombre fixe de PV de Body (la Fosse, l\'Aiguille empoisonnée)',
        ],
        'franchissable' => [
            'lecteur' => 'App\Partie\MoteurPieges::estFranchissable()',
            'libelle' => 'se saute par un jet de Body une fois détecté',
        ],
        'des_combat' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencher() + App\Partie\MoteurPieges::declencherEphemere()',
            'libelle' => 'lance N dés de combat sans défense ; un crâne = 1 PV de Body',
        ],
        'bloc_permanent' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencher()',
            'libelle' => 'devient un bloc permanent (Chute de blocs)',
        ],
        'declencheur' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencherSurCase() + App\Partie\MoteurPieges::jetonsEmbrasementLegaux() + App\Partie\AssembleurCarte::placerPieges()',
            'libelle' => 'nomme la résolution propre au piège : ouverture de trésor, ouragan, embrasement différé',
        ],
        'aleatoire' => [
            'lecteur' => 'App\Partie\MoteurPieges::tirerIssueAleatoire()',
            'libelle' => 'tire une branche au d6 entre plusieurs issues (Piège de coffre)',
        ],
        'condition_appliquee' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencherEphemere()',
            'libelle' => 'pose une condition au fouilleur (Empoisonné)',
        ],
        'teleportation' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencherSurCase() + App\Partie\MoteurPieges::declencherTeleportation()',
            'libelle' => 'envoie le héros sur la case jumelle de sa paire',
        ],
        'portee_recul' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencherHurricane()',
            'libelle' => 'repousse le héros d\'au plus N cases (Piège de l\'ouragan)',
        ],
        'des_attaque_zone' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencherZone() + App\Partie\MoteurPieges::explosionsFireburstEnAttente()',
            'libelle' => 'frappe chaque figure d\'une zone avec N dés d\'attaque, défense normale',
        ],
        'zone_lames' => [
            'lecteur' => 'App\Partie\AssembleurCarte::placerLameBalanciere()',
            'libelle' => 'trace la zone relative de la lame balançoire',
        ],
        'desarmage_special' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreDesamorcage()',
            'libelle' => 'nomme une procédure de désamorçage propre au piège (lame balançoire : un dé de combat)',
        ],
        'degats_selon_armure' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencher()',
            'libelle' => 'les dégâts dépendent de l\'armure portée au moment de la chute (Fosse des ténèbres)',
        ],
        'esquive_sur_bouclier' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencher()',
            'libelle' => 'un jet sans crâne n\'a aucune suite : ni dégât, ni arrêt (lianes)',
        ],
        'retient' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencher()',
            'libelle' => 'sur un crâne, tient le héros sur sa case par la condition nommée',
        ],
        'type_degat' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencher() + App\Partie\MoteurPieges::explosionsFireburstEnAttente()',
            'libelle' => 'nature du dégât, interceptable par MoteurSorts::absorbeDegat()',
        ],
        'mare' => [
            'lecteur' => 'App\Partie\MoteurPieges::estMare()',
            'libelle' => 'reste au sol et se déclenche à chaque pas, sans être un trou (mare d\'acide)',
        ],
        'sort_dread' => [
            'lecteur' => 'App\Partie\MoteurPieges::declencherEphemere() + App\Partie\MoteurDread::lancerSortDePiege()',
            'libelle' => 'lance sur le fouilleur le sort de Dread NOMMÉ, lu au catalogue (Boule de Flammes)',
        ],
    ];

    /**
     * Clés semées que plus aucun code ne lit. DETTE NOMMÉE, jamais un oubli : elles restent déclarées (le test les exige
     * semées) et seront retirées par migration quand René l'aura tranché.
     *
     * @var array<string, string>
     */
    public const SANS_LECTEUR = [
        'detection' => 'semée par la Fouille du trésor (`fouille_du_tresor`) dans le Piège de coffre, l\'Aiguille, la Fiole, '
            .'le Poison : aucun lecteur. Le déclenchement est déjà porté par `declencheur: ouverture_tresor`, qui est lu ; '
            .'cette clé ne redit qu\'une seconde fois ce qui est câblé. À retirer par migration si René ne veut rien d\'autre.',
    ];

    /**
     * Clés déclarées mais portées par AUCUNE ligne : rien à lire pour l'instant. Vide : toute clé déclarée est semée.
     *
     * @var array<string, string>
     */
    public const SANS_DONNEE = [];

    /** Une clé est-elle déclarée (lue, ou dette nommée) ? */
    public static function connue(string $cle): bool
    {
        return isset(self::VOCABULAIRE[$cle]) || isset(self::SANS_LECTEUR[$cle]);
    }
}
