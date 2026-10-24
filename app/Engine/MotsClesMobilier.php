<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * VOCABULAIRE FERMÉ de `mobiliers.effet` — le même garde-fou que
 * `MotsClesTerrain`, `MotsClesEpreuve`, `MotsClesTalent`, posé le 2026-10-09 en
 * portant le *Cocon* de *Jungles of Delthrak* : `mobiliers.effet` n'avait
 * jusqu'ici qu'UNE clé (`fouille`, la table de butin) et n'avait donc jamais eu
 * besoin d'un registre ; la deuxième le rend nécessaire (une clé sans lecteur
 * est une règle promise au joueur et jamais tenue — CLAUDE.md, « Hard rules »).
 *
 * Testé DANS LES DEUX SENS (`MobilierCocoonTest`) : toute clé portée par une
 * ligne du catalogue est déclarée ICI avec son lecteur, et toute clé déclarée
 * est portée par au moins une ligne.
 *
 * Les pièces ATTAQUABLES (`pv_body`/`defense_dice`) et destructibles par jet de
 * Body (`difficulte_destruction`) ont leurs PROPRES colonnes et n'entrent pas
 * ici : ce vocabulaire ne couvre que ce qui vit dans la colonne `effet`.
 *
 * @see \App\Partie\MoteurMobilier
 */
final class MotsClesMobilier
{
    /**
     * @var array<string, array{lecteur: string, libelle: string}>
     */
    public const VOCABULAIRE = [
        // Table de butin d'une pièce fouillable (coffre, tombeau, armoire…),
        // tirée PONDÉRÉE par `poids`.
        'fouille' => [
            'lecteur' => 'App\Partie\MoteurMobilier::tirerButin()',
            'libelle' => 'se fouille : table de butin propre à la pièce',
        ],

        // COCON (Jungles of Delthrak p. 4) : « A hero adjacent to a cocoon can
        // spend an action to destroy it. » UNE action, AUCUN jet — ni les PV du
        // mobilier attaquable, ni le jet de Body du mobilier fracassable.
        'detruit_par_action' => [
            'lecteur' => 'App\Partie\MoteurMobilier::detruisiblesParActionAdjacents()',
            'libelle' => 'se détruit en dépensant une action, sans jet',
        ],

        // SALLE DANGEREUSE (Kellar's Keep q. 4 note D, F4543 p. 15 ; Spirit Queen's Torment q. 13 note A, G0053 p. 31) :
        // un meuble qui brûle chaque héros qui FINIT son déplacement (`fin_deplacement`, la forge) ou son tour
        // (`fin_tour`, la fournaise) DANS SA SALLE, d'UN dé de combat — un crâne = 1 PV de Body, sans jet de défense.
        // Les monstres n'en souffrent pas. `exempte` nomme la seule classe épargnée (« except the dwarf »), `null`
        // pour aucune. `desamorcable` : la fournaise « se désamorce comme un piège » (jet de Body, couture
        // `desamorcer_piege`), la forge n'en parle pas et ne se désamorce pas.
        'salle_dangereuse' => [
            'lecteur' => 'App\Partie\MoteurMobilier::salleDangereuseDe()',
            'libelle' => 'brûle un héros qui finit son déplacement ou son tour dans la salle (un dé de combat, un crâne = 1 PV)',
        ],
    ];

    /** La forge des nains : frappe qui FINIT son déplacement dans la salle. */
    public const MOMENT_FIN_DEPLACEMENT = 'fin_deplacement';

    /** La fournaise : frappe qui FINIT son tour dans la salle. */
    public const MOMENT_FIN_TOUR = 'fin_tour';

    /** Les deux moments où une salle dangereuse frappe. */
    public const MOMENTS_SALLE_DANGEREUSE = [self::MOMENT_FIN_DEPLACEMENT, self::MOMENT_FIN_TOUR];

    /** Une clé déclarée porte-t-elle un lecteur RÉEL ? */
    public static function connue(?string $cle): bool
    {
        return $cle !== null && isset(self::VOCABULAIRE[$cle]);
    }

    /** Le mobilier de ce type est-il détruit d'UNE action, sans jet ? */
    public static function detruitParAction(?array $effet): bool
    {
        return ! empty($effet['detruit_par_action']);
    }

    /**
     * Les paramètres normalisés d'une salle dangereuse, ou `null` si ce meuble n'en est pas une.
     * Un `moment` hors vocabulaire LÈVE une exception : un meuble mal déclaré ne doit jamais brûler
     * en silence (le registre le teste dans les deux sens, `SalleDangereuseTest`).
     *
     * @param  array<string, mixed>|null  $effet  `mobiliers.effet`
     * @return array{moment: string, exempte: ?string, desamorcable: bool}|null
     */
    public static function salleDangereuse(?array $effet): ?array
    {
        if (! is_array($effet) || ! array_key_exists('salle_dangereuse', $effet)) {
            return null;
        }

        $brut = (array) $effet['salle_dangereuse'];
        $moment = (string) ($brut['moment'] ?? '');

        if (! in_array($moment, self::MOMENTS_SALLE_DANGEREUSE, true)) {
            throw new \InvalidArgumentException("salle_dangereuse.moment inconnu : « {$moment} ».");
        }

        return [
            'moment' => $moment,
            'exempte' => isset($brut['exempte']) ? (string) $brut['exempte'] : null,
            'desamorcable' => (bool) ($brut['desamorcable'] ?? false),
        ];
    }
}
