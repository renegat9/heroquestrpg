<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Vocabulaire des SORTS DE DREAD — la magie du MJ (doc 09 §4bis).
 *
 * Même principe et même raison d'être que `MotsClesSort` côté héros, que
 * `MotsClesEquipement` côté armurerie et que `MotsClesTalent` côté arbre : un
 * effet est une **donnée**, donc chaque mot qu'il porte doit être déclaré,
 * câblé et documenté. Un mot sans lecteur est une règle promise au joueur et
 * jamais tenue — le projet en a déjà déterré assez (`jetable` décoratif,
 * `deplacement_interdit` sans lecteur, `degats_pv_body_par_tour` muet trois
 * mois durant) pour ne plus en ajouter sciemment.
 *
 * `MECANIQUES` est confronté au seeder **dans les deux sens** par
 * `SortsDreadSourcesTest` : tout mot employé par un sort doit figurer ici, et
 * tout mot déclaré ici doit être employé par au moins un sort. Le lecteur
 * nommé doit exister, et **nommer le mot dans son propre fichier**.
 *
 * Référence : `reference/09_bestiaire.md` §4bis (les 29 cartes de
 * `dread_spells.pdf`, transcrites une à une).
 */
final class MotsClesSortDread
{
    // ------------------------------------------------------------- TYPES

    /** Le sort blesse — dés de combat ou montant fixe. */
    public const TYPE_DEGATS = 'degats';

    /** Le sort pose une condition sur un ou plusieurs héros. */
    public const TYPE_CONTROLE = 'controle';

    /** Le sort fait apparaître des sbires, ou en relève. */
    public const TYPE_INVOCATION = 'invocation';

    /** Le sort rend des PV de Body au lanceur ou à un monstre. */
    public const TYPE_SOIN = 'soin';

    /** Le lanceur se téléporte hors de portée (*Escape*). */
    public const TYPE_FUITE = 'fuite';

    /**
     * Le sort DÉTRUIT une pièce d'équipement (*Rust*).
     *
     * Une famille à part, et pas un `controle` déguisé : elle ne pose aucune
     * condition, ne se rompt pas, et ne s'annule jamais — « so thin, brittle,
     * and useless that it CAN NEVER BE USED AGAIN ». C'est le seul sort du
     * paquet dont l'effet survit à la quête.
     */
    public const TYPE_DESTRUCTION = 'destruction';

    public const TYPES = [
        self::TYPE_DEGATS,
        self::TYPE_CONTROLE,
        self::TYPE_INVOCATION,
        self::TYPE_SOIN,
        self::TYPE_FUITE,
        self::TYPE_DESTRUCTION,
    ];

    // -------------------------------------------------------- RÉSISTANCES

    /**
     * AUCUNE résistance : l'effet s'applique, point. *Tempest* n'accorde aucun
     * jet — « That hero then misses their next turn ». Le mot existe pour que
     * la donnée le DISE : `resistance` absente retomberait sur un défaut.
     */
    public const RESISTANCE_AUCUNE = 'aucune';

    /**
     * DÉS ROUGES : la cible lance `des_resistance` d6 BRUTS, chaque **5 ou 6**
     * annule 1 point de dégât (*Ball of Flame*, *Firestorm*). Elle REMPLACE le
     * jet de défense — les sorts qui la portent posent
     * `defense_applicable: false`. Strictement la même règle que côté héros
     * (`MotsClesSort::RESISTANCE_DES_ROUGES`), et c'est voulu : ce sont les
     * mêmes deux cartes de feu, vues des deux bords de la table.
     */
    public const RESISTANCE_DES_ROUGES = 'des_rouges';

    /**
     * UN DÉ DE COMBAT, et seul un **crâne** fait mal (*Creeping Grasp* : « They
     * must roll 1 combat die. If they roll a skull, they suffer 1 Body Point of
     * damage and are restrained »). Le sort ne rate pas — c'est son effet qui
     * est conditionnel.
     */
    public const RESISTANCE_DES_COMBAT_CRANE = 'des_combat_crane';

    /**
     * PALIERS SUR UN d6 (*Channel Dread*) : 1-3 la cible résiste, 4-5 elle perd
     * 1 PV, 6 et plus 2 PV. Le seuil se lit sur le d6 **brut** ; le « et plus »
     * est ce qui rend `bonus_lanceurs_adjacents` lisible.
     */
    public const RESISTANCE_PALIERS_D6 = 'paliers_d6';

    /**
     * RUPTURE : le sort prend **toujours**, et c'est sa poursuite qui est
     * contestée — la victime lance 1 d6 par point de Mind, un seul **6** brise
     * le sort, tout de suite puis au début de chacun de ses tours. Quatre
     * cartes l'écrivent mot pour mot (*Sleep*, *Command*, *Fear*, *Cloud of
     * Dread*, *Mind Blast*).
     *
     * ⚠ Ce n'est PAS un jet de résistance au lancer : `jet_mind` déciderait au
     * moment du sort, celui-ci décide à chaque tour. C'est le même mot, le même
     * sens et le même lecteur que côté monstres depuis le 2026-09-02.
     */
    public const RESISTANCE_RUPTURE_PAR_MIND = 'rupture_6_par_mind';

    /**
     * RUPTURE À UN DÉ, seuil 5-6 (*Dreadlights*, seule carte à le faire).
     *
     * ⚠ Ne pas l'assimiler à `rupture_6_par_mind` : ce dernier donne autant de
     * dés que de Mind, donc il libère vite un magicien (Mind 4) et presque
     * jamais un barbare (Mind 1). La carte des Feux de l'Effroi ne parle pas du
     * Mind du tout — un dé, 5 ou 6. Confondre les deux inverserait le sort.
     */
    public const RESISTANCE_RUPTURE_5_6 = 'rupture_5_6_un_de';

    public const RESISTANCES = [
        self::RESISTANCE_AUCUNE,
        self::RESISTANCE_DES_ROUGES,
        self::RESISTANCE_DES_COMBAT_CRANE,
        self::RESISTANCE_PALIERS_D6,
        self::RESISTANCE_RUPTURE_PAR_MIND,
        self::RESISTANCE_RUPTURE_5_6,
    ];

    /** Les deux ruptures — celles qui se rejouent au début du tour de la victime. */
    public const RESISTANCES_RUPTURE = [
        self::RESISTANCE_RUPTURE_PAR_MIND,
        self::RESISTANCE_RUPTURE_5_6,
    ];

    // -------------------------------------------------------------- ZONES

    /** Une seule cible, désignée par le moteur (défaut : pas de clé `zone`). */
    public const ZONE_CIBLE = 'cible';

    /** Toute la SALLE du lanceur (*Firestorm* : « in the same room »). */
    public const ZONE_SALLE = 'salle';

    /**
     * La salle **ou le couloir** du lanceur (*Cloud of Dread* : « in the same
     * room or corridor »). Distinct de `salle` parce que `Salles::indexDe()`
     * rend `null` pour un couloir : sans ce mot, la Nuée ne fonctionnerait
     * nulle part hors des salles, ce que sa carte autorise expressément.
     */
    public const ZONE_SALLE_OU_COULOIR = 'salle_ou_couloir';

    /** Un carré de 2×2 cases (*Ice Storm*), posé sur le meilleur amas de cibles. */
    public const ZONE_CARRE_2X2 = 'carre_2x2';

    /** Une ligne droite ou diagonale jusqu'au mur (*Lightning Bolt*) — voir `App\Partie\Rayon`. */
    public const ZONE_RAYON = 'rayon';

    /** Les 4 cases orthogonales du lanceur (*Chill* : « though not diagonally adjacent »). */
    public const ZONE_CONTACT = 'contact';

    public const ZONES = [
        self::ZONE_CIBLE,
        self::ZONE_SALLE,
        self::ZONE_SALLE_OU_COULOIR,
        self::ZONE_CARRE_2X2,
        self::ZONE_RAYON,
        self::ZONE_CONTACT,
    ];

    /** Zones qui frappent plusieurs cases — celles dont le choix compte les cibles présentes. */
    public const ZONES_MULTIPLES = [
        self::ZONE_SALLE,
        self::ZONE_SALLE_OU_COULOIR,
        self::ZONE_CARRE_2X2,
        self::ZONE_RAYON,
    ];

    // ---------------------------------------------------------- MÉCANIQUES
    //
    // Chaque clé d'`effet` avec le lecteur qui l'applique. `lecteur` doit
    // exister ET nommer la clé dans son propre fichier — c'est l'assertion qui
    // manquait à `CapacitesInnees` et qui y a trouvé quatre lecteurs fantômes.

    /**
     * @var array<string, array{lecteur: string, libelle: string}>
     */
    public const MECANIQUES = [
        'des_degats' => [
            'lecteur' => 'App\Partie\MoteurDread::degatsInfliges',
            'libelle' => 'Dés de combat lancés par le sort',
        ],
        'degats_fixes' => [
            'lecteur' => 'App\Partie\MoteurDread::degatsInfliges',
            'libelle' => 'Montant fixe, sans dés d\'attaque',
        ],
        'des_resistance' => [
            'lecteur' => 'App\Partie\MoteurDread::degatsInfliges',
            'libelle' => 'd6 bruts lancés par la cible, chaque 5-6 annule 1 point',
        ],
        'defense_applicable' => [
            'lecteur' => 'App\Partie\MoteurDread::degatsInfliges',
            'libelle' => 'La cible peut-elle parer ?',
        ],
        'type_degat' => [
            'lecteur' => 'App\Partie\MoteurSorts::absorbeDegat',
            'libelle' => 'Nature du dégât (feu, froid) — lue par les protections',
        ],
        'zone' => [
            'lecteur' => 'App\Partie\MoteurDread::casesDeZone',
            'libelle' => 'Étendue du sort',
        ],
        'touche_monstres' => [
            'lecteur' => 'App\Partie\MoteurDread::monstresDuSort',
            'libelle' => 'Le sort blesse aussi les monstres présents',
        ],
        'epargne_lanceur' => [
            'lecteur' => 'App\Partie\MoteurDread::monstresDuSort',
            'libelle' => 'Le lanceur est épargné par sa propre zone',
        ],
        'hors_couloir' => [
            'lecteur' => 'App\Partie\MoteurDread::sortUtilisable',
            'libelle' => 'Interdit en couloir',
        ],
        'condition_appliquee' => [
            'lecteur' => 'App\Partie\MoteurDread::sortDreadControle',
            'libelle' => 'Condition du catalogue posée sur la victime',
        ],
        'resistance' => [
            'lecteur' => 'App\Partie\MoteurDread::degatsInfliges',
            'libelle' => 'Comment la cible s\'oppose au sort',
        ],
        'bonus_lanceurs_adjacents' => [
            'lecteur' => 'App\Partie\MoteurDread::bonusLanceursAdjacents',
            'libelle' => '+1 au dé par lanceur du même sort adjacent',
        ],
        'paliers' => [
            'lecteur' => 'App\Partie\MoteurDread::degatsSelonPaliers',
            'libelle' => 'Table seuil ⇒ dégâts, lue sur un d6',
        ],
        'table_d6' => [
            'lecteur' => 'App\Partie\MoteurDread::sortDreadInvocation',
            'libelle' => 'Composition du renfort, tirée sur un d6',
        ],
        'reanime' => [
            'lecteur' => 'App\Partie\MoteurDread::sortDreadReanimation',
            'libelle' => 'Relève les morts-vivants vaincus de la salle',
        ],
        'soin' => [
            'lecteur' => 'App\Partie\MoteurDread::sortDreadSoin',
            'libelle' => 'PV de Body rendus au lanceur ou à un monstre',
        ],
        'ligne_de_vue' => [
            'lecteur' => 'App\Partie\MoteurDread::sortDreadSoin',
            'libelle' => 'La cible doit être vue du lanceur',
        ],
        'detruit' => [
            'lecteur' => 'App\Partie\MoteurDread::cibleDeRouille',
            'libelle' => 'Ce que le sort peut détruire : matière, emplacements, exemptions',
        ],
        'teleportation' => [
            'lecteur' => 'App\Partie\MoteurDread::sortDreadFuite',
            'libelle' => 'Règle de destination du lanceur qui se dérobe',
        ],
    ];

    /**
     * Mots qu'une carte porte et que le moteur **n'applique pas**. Vide
     * aujourd'hui, et c'est délibéré : les sept cartes non portées le sont
     * ENTIÈREMENT (registre `config/cartes.php`, section `dread`), pas à
     * moitié. Une carte à moitié portée serait la seule chose que cette classe
     * existe pour empêcher.
     *
     * @var array<string, string>
     */
    public const NON_IMPLEMENTES = [];

    public static function estNonImplemente(string $mot): bool
    {
        return array_key_exists($mot, self::NON_IMPLEMENTES);
    }
}
