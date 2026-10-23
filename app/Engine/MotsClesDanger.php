<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * VOCABULAIRE FERMÉ du DANGER MOBILE AUTONOME (2026-10-23) — `dangers.effet`.
 *
 * Une seule mécanique pour deux pièces de carte, décidée par René le 2026-10-15 (Kellar's Keep q. 3 n. A,
 * livret F4543 p. 12-13 ; Return of the Witch Lord q. 3 n. A, livret F4193 p. 13) :
 *
 *  - le **rocher roulant** : tombe quand le dernier héros franchit sa case déclencheur, roule de 2 dés rouges par
 *    tour du MJ jusqu'au mur de fin de couloir, puis reste en travers du passage (bloc permanent) ;
 *  - la **brume de la mort** : posée au départ, se déplace de 6 cases par tour du MJ dans les couloirs seulement,
 *    fait perdre 1 PV à chaque héros qu'elle traverse, et ne se détruit que par la Tempête ou la Lame des Esprits.
 *
 * Même garde-fou que `MotsClesTerrain` / `MotsClesMobilier` : une clé n'entre ici qu'avec son LECTEUR, et
 * `DangerMobileTest` confronte ce registre aux données DANS LES DEUX SENS — rien d'écrit dans `dangers.effet` qui
 * ne soit déclaré ici, rien de déclaré ici que la fiche d'un danger ne porte pas, et le fichier du lecteur doit
 * nommer la clé.
 *
 * Pourquoi un vocabulaire de plus, et pas `MotsClesAdversaires` ni `MotsClesTerrain` : un danger n'est pas une
 * FIGURE (une figure bloque, ce qui ferait de la brume un verrou sans issue, et un monstre ne se frappe pas ainsi),
 * et ce n'est pas un TERRAIN (un terrain agit en entrant sur la case ; celui-ci se déplace seul, au tour du MJ).
 * Ses précédents sont les deux couloirs voisins : l'effet DIFFÉRÉ au tour du MJ de `MoteurPieges` (le Fireburst),
 * et le BLOC PERMANENT de la Chute de blocs (`ETAT_BLOC`, mouvement ET vue).
 */
final class MotsClesDanger
{
    /** Le danger avance de `deplacement` à chaque tour du MJ : `['des_rouges' => 2]` (somme de deux dés) ou `['cases' => 6]`. */
    public const DEPLACEMENT = 'deplacement';

    /** Le trajet : `ligne` (le rocher suit la ligne droite de son couloir) ou `reseau_de_couloirs` (la brume). */
    public const CHEMIN = 'chemin';

    /** Ce qu'un passage fait subir : `['des_combat' => 5]` (dés de combat SANS défense, un crâne = 1 PV) ou `['pv' => 1]`. */
    public const DEGATS = 'degats';

    /** Le sort du danger : `mur` (il s'arrête en fin de couloir et BLOQUE le passage) ou `jamais` (il dure jusqu'à sa destruction). */
    public const ARRET = 'arret';

    /** Ce qui le détruit : la liste des sorts et des armes qui peuvent le faire. Absente = indestructible (le rocher). */
    public const DESTRUCTION = 'destruction';

    /** Quand il apparaît : `depart` (posé au lancement de la quête) ou `declencheur` (tombe quand le dernier héros franchit sa case). */
    public const POSE = 'pose';

    /** Valeurs fermées de `deplacement`, `chemin`, `arret` et `pose`. */
    public const CHEMINS = ['ligne', 'reseau_de_couloirs'];
    public const ARRETS = ['mur', 'jamais'];
    public const POSES = ['depart', 'declencheur'];

    /**
     * @var array<string, array{lecteur: string|list<string>, libelle: string}>
     */
    public const VOCABULAIRE = [
        self::DEPLACEMENT => [
            'lecteur' => 'App\Partie\MoteurDanger::distance',
            'libelle' => 'avance de {valeur} à chaque tour du MJ',
        ],
        self::CHEMIN => [
            'lecteur' => 'App\Partie\MoteurDanger::avancer',
            'libelle' => 'se déplace {valeur}',
        ],
        self::DEGATS => [
            'lecteur' => 'App\Partie\MoteurDanger::toucher',
            'libelle' => 'blesse chaque héros qu\'il touche',
        ],
        self::ARRET => [
            'lecteur' => 'App\Partie\MoteurDanger::avancer',
            'libelle' => 's\'arrête {valeur}',
        ],
        self::DESTRUCTION => [
            'lecteur' => [
                'App\Partie\MoteurDanger::frapperAvecArme',
                'App\Partie\MoteurDanger::detruireParSort',
            ],
            'libelle' => 'ne se détruit que par {valeur}',
        ],
        self::POSE => [
            'lecteur' => [
                'App\Partie\AssembleurCarte::placerDangers',
                'App\Partie\MoteurDanger::franchissementDuHeros',
            ],
            'libelle' => 'apparaît {valeur}',
        ],
    ];

    /** Une clé déclarée porte-t-elle un lecteur RÉEL ? */
    public static function connue(?string $cle): bool
    {
        return $cle !== null && isset(self::VOCABULAIRE[$cle]);
    }

    /** Les clés que ce vocabulaire déclare. @return list<string> */
    public static function cles(): array
    {
        return array_keys(self::VOCABULAIRE);
    }
}
