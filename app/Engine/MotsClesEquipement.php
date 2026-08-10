<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Vocabulaire d'`objets.effet` — ce qu'une carte d'équipement du plateau
 * devient une fois convertie en données.
 *
 * Troisième vocabulaire du moteur, après `DureeEffet` (quand un buff s'arrête)
 * et `MotsClesSort` (cible / résistance). Même raison d'être : au plateau, une
 * carte d'équipement dit son effet en toutes lettres — « This weapon allows you
 * to attack diagonally », « You may not use a shield when using the battle
 * axe ». Chez nous cet effet est une **donnée**, donc chaque phrase de carte
 * doit se traduire par un mot déclaré, câblé, documenté. Sans quoi le catalogue
 * promet une règle que le moteur n'applique pas.
 *
 * Le projet a déjà dû retirer `attaque_second_rang` (mécanisme inexistant au
 * plateau) et `ligne_de_vue` (doublon sans lecteur) pour cette raison, et le
 * `jetable` de la dague est resté DEUX ANS purement décoratif. Ce fichier est
 * ce qui rend ces oublis visibles : `ObjetsFonctionnelsTest` refuse toute clé
 * de catalogue qui n'y figure pas.
 *
 * Conversion carte par carte : `reference/16_armurerie.md` §2.2.
 * Référence des mots-clés : `reference/19_mots_cles_effets.md` §9.
 */
final class MotsClesEquipement
{
    // ------------------------------------------------------------- STATISTIQUES

    /**
     * Dés d'attaque de l'arme. **REMPLACE** la valeur du porteur (doc 03 §8 :
     * l'attaque vient de l'arme, comme au plateau) — à mains nues 1 dé.
     * Lecteur : `Partie\Equipement::recalculerCombat()`.
     */
    public const DES_ATTAQUE = 'des_attaque';

    /**
     * Dés de défense de la pièce. **S'AJOUTE** aux 2 dés communs aux quatre
     * classes (LR p. 21). Lecteur : `Partie\Equipement::recalculerCombat()`.
     */
    public const DES_DEFENSE = 'des_defense';

    // ------------------------------------------------------- PORTÉE & CIBLAGE

    /**
     * Arme longue : le contact inclut les **diagonales** — « Some long weapons,
     * like the staff and the longsword, allow you to attack diagonally » (LR
     * p. 14). Asymétrique par construction : le monstre, lui, ne riposte jamais
     * en diagonale, le livret qualifiant cette case de « safe ».
     * Lecteurs : `MenuMoteur`, `ResolveurTour::resoudreAttaque()`.
     */
    public const ATTAQUE_DIAGONALE = 'attaque_diagonale';

    /**
     * Portée de l'arme : `contact` (défaut, clé absente) ou `distance`.
     * `distance` déclenche à lui seul le contrôle de ligne de vue — inutile de
     * doubler avec une clé `ligne_de_vue`, qui a justement été retirée.
     * Lecteurs : `MenuMoteur`, `ResolveurTour::resoudreAttaque()`.
     */
    public const PORTEE = 'portee';

    public const PORTEE_CONTACT = 'contact';

    public const PORTEE_DISTANCE = 'distance';

    /**
     * Arme à distance inutilisable si un ennemi est **au contact** (arbalète).
     * ⚠ Règle **de nous**, pas du livret : rien n'interdit officiellement le tir
     * à bout portant (reference/16 §10). Lecteur : `ResolveurTour`.
     */
    public const INUTILISABLE_ADJACENT = 'inutilisable_adjacent';

    /**
     * L'arme peut être **lancée** sur une cible en ligne de vue, puis elle est
     * **détruite** (`consommerArmeLancee()`).
     * ⚠ La destruction est **de nous** : la dague officielle est groupée avec
     * l'arbalète comme arme à distance permanente (LR p. 14, reference/16 §10).
     * Lecteurs : `MenuMoteur` (option `lancer`), `ResolveurTour`.
     */
    public const JETABLE = 'jetable';

    // ------------------------------------------------------------------ MAINS

    /**
     * Arme à deux mains : interdit le bouclier — « You may not use a shield
     * when using the battle axe » (carte Battle Axe), devenu le label « Both
     * Hands » sur les cartes récentes.
     *
     * **Orthogonal au `tag_equipement`** : ce mot dit « pas de bouclier avec »,
     * le tag dit « qui a le droit d'en porter ». Le Bâton est `deux_mains` ET
     * `arme_legere`, donc jouable par le magicien.
     * Lecteur : `Partie\Equipement::verifierMains()`.
     */
    public const DEUX_MAINS = 'deux_mains';

    /**
     * La pièce EST un bouclier : refuse de cohabiter avec `deux_mains`.
     * Lecteur : `Partie\Equipement::verifierMains()`.
     */
    public const INCOMPATIBLE_DEUX_MAINS = 'incompatible_deux_mains';

    // ------------------------------------------------------------ DÉPLACEMENT

    /**
     * Encombrement de l'armure lourde, **en cases** retranchées du déplacement
     * du tour — « While wearing the Plate Mail, you have a 2 square movement
     * penalty » (carte Plate Mail). Corroboré en creux par *Borin's Armor* :
     * « unlike normal plate mail, this […] does not slow down its wearer »
     * (LR p. 7) dit qu'une armure de plates ordinaire, elle, ralentit.
     *
     * Chiffré, pas booléen : on supprimait auparavant le d6 entier
     * (`deplacement_sans_d6`, −3,5 cases en moyenne) et le déplacement devenait
     * DÉTERMINISTE — deux écarts à la carte pour le prix d'un.
     * Lecteur : `Engine\Deplacement` via `Equipement::valeurEffetPorte()`.
     */
    public const MALUS_DEPLACEMENT = 'malus_deplacement';

    // ------------------------------------------------------- ARTEFACTS D'ARME

    /**
     * Dégâts GARANTIS : l'attaque ne passe par aucun dé et la cible ne défend
     * pas — « This weapon always inflicts one Body Point of damage » (Dague de
     * jet magique). Seule clé du jeu qui court-circuite `Engine\Combat`.
     * Lecteur : `ResolveurTour::resoudreAttaque()` via `ResultatAttaque::sansJet()`.
     */
    public const DEGATS_FIXES = 'degats_fixes';

    /**
     * Dés d'attaque opposés à des créatures NOMMÉES : `{noms: […], des: N}`.
     * « Spirit Blade allows you to roll three combat dice in attack OR four
     * dice against undead creatures such as Skeletons, Zombies and Mummies. »
     *
     * La valeur REMPLACE celle de l'arme (le « OR » de la carte), elle ne s'y
     * ajoute pas. Le test porte sur `monstres.nom_base` — le nom de catalogue,
     * pas le nom habillé par l'IA, qui change à chaque quête.
     * Lecteur : `ResolveurTour::desArmeContre()`.
     */
    public const DES_ATTAQUE_CONTRE = 'des_attaque_contre';

    /**
     * Liste de `nom_base` contre lesquels l'arme accorde une SECONDE attaque ce
     * tour — « You may attack TWICE if you are fighting Orcs » (Fléau des
     * Orques). Réutilise `etat.attaque_supplementaire`, le créneau de la Potion
     * d'héroïsme : deux mécanismes se seraient cumulés sans le vouloir.
     * Lecteur : `ResolveurTour::accorderSecondeAttaque()`.
     */
    public const ATTAQUE_DOUBLE_CONTRE = 'attaque_double_contre';

    // --------------------------------------------------------------- JAUGES

    /**
     * Points de Body MAXIMUM en plus tant que la pièce est portée — « adds 2
     * Body points and 1 Mind point to the Barbarian's totals » (Amulette du
     * Nord et ses trois sœurs de classe).
     *
     * Les gagner DONNE les points ; les perdre écrête la valeur courante.
     * Lecteur : `Partie\Equipement::appliquerEcartJauges()`.
     */
    public const BONUS_PV_BODY_MAX = 'bonus_pv_body_max';

    /** Idem pour le Mind (Talisman du Savoir : +2). */
    public const BONUS_PV_MIND_MAX = 'bonus_pv_mind_max';

    // ------------------------------------------------------------------ OUTIL

    /**
     * Permet de désamorcer un piège — « you must possess a tool kit (or be the
     * dwarf) » (LR p. 19). Lecteur : `Partie\MoteurPieges`.
     */
    public const PERMET_DESAMORCAGE = 'permet_desamorcage';

    // ----------------------------------------------------------- CONSOMMABLES

    /** Soin d'un montant FIXE de PV Body (potion de marché). `MoteurPotions`. */
    public const SOIN_PV_BODY = 'soin_pv_body';

    /**
     * Soin TIRÉ AU DÉ : `soin_pv_body_de: 6` = 1d6 (Fiole de soin du deck de
     * fouille). ⚠ Mécanique **de nous** : toutes les potions officielles
     * soignent un montant annoncé. `MoteurPotions`.
     */
    public const SOIN_PV_BODY_DE = 'soin_pv_body_de';

    /** Soin d'un montant fixe de PV Mind. `MoteurPotions`. */
    public const SOIN_PV_MIND = 'soin_pv_mind';

    /** Bonus TEMPORAIRE de dés d'attaque — s'accompagne toujours d'une `duree`. */
    public const BONUS_DES_ATTAQUE = 'bonus_des_attaque';

    /** Bonus TEMPORAIRE de dés de défense — s'accompagne toujours d'une `duree`. */
    public const BONUS_DES_DEFENSE = 'bonus_des_defense';

    /**
     * Une **seconde attaque** ce tour (Potion d'héroïsme), et non des dés en
     * plus : chez nous l'attaque vient de l'arme, un bonus de dés n'aurait pas
     * rendu la carte. `MoteurPotions`, `etat.attaque_supplementaire`.
     */
    public const ATTAQUE_SUPPLEMENTAIRE = 'attaque_supplementaire';

    /** Nom de la condition posée par le consommable. `MoteurSorts::appliquerBuffPotion()`. */
    public const CONDITION_APPLIQUEE = 'condition_appliquee';

    /** Nom de la condition retirée (Antidote). `MoteurPotions`. */
    public const RETIRE_CONDITION = 'retire_condition';

    /**
     * Quand le buff s'arrête — vocabulaire `DureeEffet`, PAS libre.
     * Un objet qui pose un bonus sans `duree` ne s'arrête jamais : c'est
     * exactement le bug qui a rendu la Potion de défense permanente.
     */
    public const DUREE = 'duree';

    // -------------------------------------------------------------- PARCHEMIN

    /** Sort lancé par le parchemin — autorité. `ResolveurTour::resoudreParchemin()`. */
    public const SORT_ID = 'sort_id';

    /** Confort d'affichage : le nom du sort, qui double celui de la pièce. */
    public const SORT_NOM = 'sort_nom';

    /** Copie d'affichage de `sorts.difficulte_parchemin`, qui reste l'autorité. */
    public const DIFFICULTE_NON_LANCEUR = 'difficulte_non_lanceur';

    // ------------------------------------------------------------------- ---

    /**
     * Toutes les clés qu'un `objets.effet` peut porter. Toute autre est un bug
     * de catalogue : `ObjetsFonctionnelsTest` casse dessus.
     *
     * @return list<string>
     */
    public static function toutes(): array
    {
        return [...self::ACTIVES, ...array_keys(self::INERTES)];
    }

    /**
     * Clés effectivement APPLIQUÉES par le moteur — chacune a un lecteur nommé
     * dans son docbloc ci-dessus.
     *
     * @var list<string>
     */
    public const ACTIVES = [
        self::DES_ATTAQUE,
        self::DES_DEFENSE,
        self::ATTAQUE_DIAGONALE,
        self::PORTEE,
        self::INUTILISABLE_ADJACENT,
        self::JETABLE,
        self::DEUX_MAINS,
        self::INCOMPATIBLE_DEUX_MAINS,
        self::MALUS_DEPLACEMENT,
        self::DEGATS_FIXES,
        self::DES_ATTAQUE_CONTRE,
        self::ATTAQUE_DOUBLE_CONTRE,
        self::BONUS_PV_BODY_MAX,
        self::BONUS_PV_MIND_MAX,
        self::PERMET_DESAMORCAGE,
        self::SOIN_PV_BODY,
        self::SOIN_PV_BODY_DE,
        self::SOIN_PV_MIND,
        self::BONUS_DES_ATTAQUE,
        self::BONUS_DES_DEFENSE,
        self::ATTAQUE_SUPPLEMENTAIRE,
        self::CONDITION_APPLIQUEE,
        self::RETIRE_CONDITION,
        self::DUREE,
        self::SORT_ID,
    ];

    /**
     * Clés SANS lecteur, tolérées en connaissance de cause : pur affichage ou
     * doublon d'une autorité qui vit ailleurs. Chaque entrée dit pourquoi —
     * une clé inerte non justifiée est une clé décorative, donc une règle
     * annoncée au joueur et jamais appliquée.
     *
     * @var array<string, string>
     */
    public const INERTES = [
        self::SORT_NOM => 'Libellé de confort : le nom du sort double déjà celui du parchemin.',
        self::DIFFICULTE_NON_LANCEUR => 'Copie d\'affichage ; ResolveurTour roule contre sorts.difficulte_parchemin.',
    ];

    /** Cette clé est-elle appliquée par le moteur ? */
    public static function estActive(string $cle): bool
    {
        return in_array($cle, self::ACTIVES, true);
    }

    /** Cette clé est-elle connue (active ou inerte assumée) ? */
    public static function estConnue(string $cle): bool
    {
        return self::estActive($cle) || array_key_exists($cle, self::INERTES);
    }
}
