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

    /**
     * Le porteur lui-même OU un héros ORTHOGONALEMENT ADJACENT — ni le reste
     * de la ligne de vue, ni la diagonale. **À NE PAS CONFONDRE avec
     * `CIBLE_HEROS`** : celui-ci rend TOUS les héros en vue (portée d'un
     * sort qui traverse une salle) ; celui-là un seul geste de table —
     * « lorsqu'on utilise une potion, il faudrait pouvoir cibler le joueur
     * actuel OU un joueur adjacent » (René, 2026-09-11). Tendre sa fiole à
     * qui est à côté de soi, pas au bout du couloir.
     *
     * ⚠ La restriction de classe d'un objet porté par `cible` doit s'opposer
     * au DESTINATAIRE, jamais au porteur qui le sort de son sac — sans quoi
     * une potion réservée au Barbare contournerait sa carte dès qu'un
     * magicien la tendrait à un voisin. Lecteurs : `MenuMoteur::ciblesObjet()`
     * (adjacence via `Grille::sontAdjacentes()`, `$diagonales` par défaut à
     * `false` — même convention que `ResolveurTour::resoudreRelever()` pour
     * relever un allié tombé) et `MoteurPotions::boire()` (le 4ᵉ paramètre
     * `$cible`, jamais le porteur).
     */
    public const CIBLE_HEROS_ADJACENT = 'heros_adjacent';

    /**
     * Un monstre, et UN SEUL : pour un sort de dégâts ou mental à cible unique,
     * la liste ne contient que des monstres (décision de René, 2026-10-09).
     * « This spell may be cast on any one monster » (*Ball of Flame*, *Fire of
     * Wrath* ; *Sleep*, *Tempest*, *Chains of Darkness* nomment « a monster » /
     * « one monster », doc 16 §3). Le tir ami n'existe plus pour une cible
     * unique : il subsiste pour les sorts de ZONE et de RAYON, qui n'ont pas de
     * liste (`zone`, `rayon`).
     */
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
     * Un monstre LANCEUR DE DREAD en ligne de vue, qui lui reste au moins un
     * sort (*Unlearn*, Wizards of Morcar, *Spells of Protection*, 2026-10-08).
     * Un sort utilitaire : la liste n'est pas restreinte aux monstres en général,
     * seulement à ceux qui ont encore un répertoire. Lecteur :
     * `MoteurSorts::ciblesLegales()` ; l'oubli lui-même : `OubliSorts`.
     */
    public const CIBLE_LANCEUR_DREAD = 'lanceur_dread';

    public const CIBLES = [
        self::CIBLE_SOI,
        self::CIBLE_HEROS,
        self::CIBLE_HEROS_ADJACENT,
        self::CIBLE_MONSTRE,
        self::CIBLE_MONSTRES_ZONE,
        self::CIBLE_LANCEUR_DREAD,
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

    /**
     * AUCUNE résistance : l'effet s'applique, point. La carte *Tempest*
     * (photo de René, doc 16 §3bis) ne laisse au monstre aucun jet — « That
     * monster then misses its next turn » —, alors que nous lui imposions un
     * `jet_mind` de notre invention.
     *
     * ⚠ Le mot existe pour que la donnée le DISE, plutôt que de laisser
     * `resistance` absente : l'absence retombe sur le défaut `jet_mind`, donc
     * elle ne peut pas exprimer « pas de jet ». Lecteur :
     * `ResolveurTour::sortMental()`.
     */
    public const RESISTANCE_AUCUNE = 'aucune';

    /**
     * DÉS ROUGES : la cible lance `des_resistance` d6 BRUTS, et **chaque 5 ou 6
     * annule 1 point de dégât** (René, 2026-09-02). C'est la règle des deux
     * sorts de feu, mot pour mot sur leurs cartes (doc 16 §3bis) — « It inflicts
     * 2 Body Points of damage. The monster then rolls 2 red dice. For each 5 or
     * 6 rolled, the damage is reduced by 1 point. »
     *
     * ⚠ Le seuil se lit sur le d6 BRUT, jamais sur une face de combat : nos
     * faces regroupent 4-5 en bouclier blanc, ce qui écraserait la moitié de la
     * règle — même précaution que le venin des Jungles of Delthrak.
     *
     * ⚠ Elle REMPLACE le jet de défense, elle ne s'y ajoute pas : les sorts qui
     * la portent posent `defense_applicable: false`. Lecteur :
     * `ResolveurTour::sortDegats()`.
     */
    public const RESISTANCE_DES_ROUGES = 'des_rouges';

    /**
     * SOMMEIL : le monstre tente de rompre **sur-le-champ**, puis **à chacun de
     * ses tours**, en lançant 1 d6 par point de Mind — un seul 6 le réveille
     * (carte officielle, doc 16 §3bis ; arbitrage de René, 2026-09-02).
     *
     * ⚠ Ce n'est PAS une résistance au lancer : le sort prend toujours, et
     * c'est sa POURSUITE qui est contestée. D'où un mot distinct de `jet_mind`,
     * qui lui décide au moment du lancer. Lecteurs :
     * `MoteurSorts::tenterRuptureSommeil()`, appelé par
     * `ResolveurTour::appliquerEffetMental()` (sur-le-champ) et
     * `ResolveurTour::jouerMonstre()` (à chaque tour).
     */
    public const RESISTANCE_RUPTURE_PAR_MIND = 'rupture_6_par_mind';

    /**
     * MIND EN DÉFENSE : la cible défend avec autant de dés que de points de
     * Mind actuels — « The target defends with as many dice as they have
     * Mind Points. Monsters with 0 Mind points may not roll defense » (carte
     * *Arrows of the Night*, *Spells of Darkness*, Wizards of Morcar
     * G1504/carton, 2026-10-06). Ni un jet binaire (`jet_mind`) ni un
     * remplacement de la parade par des dés rouges (`des_rouges`) : c'est un
     * combat NORMAL (attaque vs défense, boucliers comptés comme d'habitude),
     * seul le NOMBRE de dés de défense change de source. Lecteur :
     * `ResolveurTour::sortDegats()`, qui substitue `pv_mind` à
     * `defenseEffective()`/`desDefenseHeros()` sans toucher au reste du jet —
     * à 0 Mind, 0 dé de défense, strictement comme la carte le dit.
     */
    public const RESISTANCE_DES_MIND = 'des_mind';

    public const RESISTANCES = [
        self::RESISTANCE_JET_MIND,
        self::RESISTANCE_AUCUNE,
        self::RESISTANCE_DES_ROUGES,
        self::RESISTANCE_RUPTURE_PAR_MIND,
        self::RESISTANCE_DES_MIND,
    ];

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
