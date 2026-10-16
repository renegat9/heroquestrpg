<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Vocabulaire des CAPACITÉS d'ADVERSAIRES NOMMÉS construites le 2026-10-15
 * (René) : le patron TOURELLE et les quatre adversaires de *Jungles of Delthrak*
 * (quêtes 3, 6, 14 et 15 du livret F9907) — `monstres.capacites`.
 *
 * Le patron « tourelle » est UN mécanisme, pas deux : le *Guardian Effigy*
 * (*Against the Ogre Horde* q. 4 p. 27) et le *Dread Skull* (*The Crypt of
 * Perpetual Darkness* q. 9 p. 23) sont des figures IMMOBILES (déplacement 0)
 * qui tirent sur un héros en ligne de vue ; le *Bloomrot Scion* (Move 0 lui
 * aussi) en reprend l'immobilité, et la règle de PLACEMENT qui va avec : en
 * salle seulement, jamais en couloir, jamais au détriment du plancher de cases
 * jouables (`AssembleurCarte::casesPourFiguresFixes()`).
 *
 * Même discipline que les autres vocabulaires fermés (`MotsClesReactionMj`) :
 * une clé n'entre ici qu'avec son LECTEUR ; `AdversairesNommesTest` confronte ce
 * registre aux données DANS LES DEUX SENS (rien de déclaré sans monstre qui le
 * porte — sauf `SANS_DONNEE`, écrit —, rien de porté sans déclaration) et exige
 * que le fichier du lecteur NOMME la clé. Le test de bestiaire lit
 * `CAPACITES` pour sa liste de capacités implémentées.
 */
final class MotsClesAdversaires
{
    /**
     * TOURELLE — figure immobile qui fait feu à chaque tour du MJ sur un héros
     * en ligne de vue, au contact comme à distance, aux MÊMES dés (Effigy :
     * « Each turn, it may launch a ball of fire of 3 Attack dice at any one hero in
     * its line of sight. It is unable to move »).
     */
    public const TOURELLE = 'tourelle';

    /**
     * Immunité TOTALE aux effets de sort (Effigy : « immune to all spell effects »,
     * René 2026-10-15 : totale, comme la Gargouille gardienne de Kellar's Keep).
     * Ni sort de héros (menu, zone, rayon), ni sort de Dread qui frappe ou soigne.
     */
    public const IMMUNITE_SORTS = 'immunite_sorts';

    /**
     * Immunité PARTIELLE : les conditions de monstre qu'un sort ne peut pas poser
     * (Bloomrot Scion : « immune to spells that would cause it to sleep or miss its
     * turn » = `endormi`, `saute_tour`). Liste de clés `MoteurSorts::CONDITIONS_MONSTRE`.
     */
    public const IMMUNITE_CONDITIONS = 'immunite_conditions';

    /**
     * APPARITION À LA VUE (Dread Skull : « As soon as a hero sees a Dread Skull, place
     * it on the board. Immediately, and on Zargon's turn, the Dread Skull makes a ranged
     * attack »). La figure attend cachée (`revele = false`), paraît au premier regard
     * d'un héros — en marchant comme à l'ouverture d'une porte — et tire AUSSITÔT.
     */
    public const APPARAIT_A_LA_VUE = 'apparait_a_la_vue';

    /**
     * SILENCING SNARE (Deathspinner, F9907 p. 17) : « may cast Channel Dread at will on
     * any hero that ends their turn within her line of sight (no action required). She
     * cannot use this ability if she would not be able to take her action ». Paramétrée :
     * `['sort' => 'Canaliser l\'Effroi']`.
     */
    public const FILET_SILENCIEUX = 'filet_silencieux';

    /**
     * BREATH OF THE CONSTRUCT (Forgeheart Golem, F9907 p. 23) : « once as an action […]
     * 2 Body Points of damage to all heroes located in the same room or corridor. Each
     * affected hero then rolls two red dice. For each 5 or 6 rolled, the damage is reduced
     * by 1 point. » Paramétrée : `['degats' => 2, 'des_rouges' => 2]`.
     */
    public const SOUFFLE_DU_CONSTRUIT = 'souffle_du_construit';

    /**
     * « At the end of the Bloomrot Scion's turn, place one Tendril in an unoccupied square
     * as close as possible to the Bloomrot Scion » (F9907 p. 39). Paramétrée :
     * `['creature' => 'Vrille']`.
     */
    public const VRILLE_EN_FIN_DE_TOUR = 'vrille_en_fin_de_tour';

    /**
     * « If the Bloomrot Scion would be reduced to 0 body points, it may sacrifice one of
     * its Tendrils and instead be reduced to 1 Body Point » — lu au point de passage unique
     * de la mort d'un monstre (`MoteurDegats::infligerAMonstre()`).
     */
    public const SACRIFICE_DE_VRILLE = 'sacrifice_de_vrille';

    /**
     * @var array<string, array{lecteur: string, libelle: string}>
     */
    public const CAPACITES = [
        self::TOURELLE => [
            'lecteur' => 'App\Models\Monstre::estTourelle',
            'libelle' => 'Tourelle : immobile, fait feu chaque tour sur un héros en vue',
        ],
        self::IMMUNITE_SORTS => [
            'lecteur' => 'App\Models\Monstre::immuniseAuxSorts',
            'libelle' => 'Immunisé à tous les effets de sort',
        ],
        self::IMMUNITE_CONDITIONS => [
            'lecteur' => 'App\Models\Monstre::conditionsImmunisees',
            'libelle' => 'Immunisé aux sorts qui l\'endorment ou lui font perdre son tour',
        ],
        self::APPARAIT_A_LA_VUE => [
            'lecteur' => 'App\Partie\MoteurTourelle::apparitionsEnVue',
            'libelle' => 'Paraît dès qu\'un héros le voit, et fait feu aussitôt',
        ],
        self::FILET_SILENCIEUX => [
            'lecteur' => 'App\Partie\AdversairesDelthrak::filetsSilencieux',
            'libelle' => 'Filet silencieux : Canaliser l\'Effroi sur tout héros qui finit son tour en sa vue (sans action)',
        ],
        self::SOUFFLE_DU_CONSTRUIT => [
            'lecteur' => 'App\Partie\AdversairesDelthrak::souffle',
            'libelle' => 'Souffle du Construit (une fois) : 2 PV à toute la salle ou le couloir, réduits par 2 dés rouges',
        ],
        self::VRILLE_EN_FIN_DE_TOUR => [
            'lecteur' => 'App\Partie\AdversairesDelthrak::vrilleEnFinDeTour',
            'libelle' => 'Pose une Vrille à la fin de chacun de ses tours',
        ],
        self::SACRIFICE_DE_VRILLE => [
            'lecteur' => 'App\Partie\AdversairesDelthrak::vrilleASacrifier',
            'libelle' => 'Sacrifie une Vrille pour rester à 1 PV au lieu de mourir',
        ],
    ];

    /**
     * Les clés dont le LECTEUR est construit et testé EN JEU mais qu'AUCUNE ligne du
     * catalogue ne porte encore — une dette écrite, jamais un oubli. La valeur dit
     * pourquoi, et ce qui la lèverait.
     *
     * @var array<string, string>
     */
    public const SANS_DONNEE = [
        self::APPARAIT_A_LA_VUE => 'Le Dread Skull (The Crypt of Perpetual Darkness, G1798 p. 5 et q. 9 p. 23) est la seule '
            .'carte à la porter, et cette boîte n\'est pas un thème actif (plan-crypte-tenebres.md, Q5 différée) : aucune '
            .'boîte existante ne peut accueillir proprement une figure de palier base à pose cachée — en bestiaire '
            .'automatique le tier `base` se tire dans TOUTES les boîtes, et le crâne y serait acheté comme un sbire '
            .'ordinaire. Le semer est la première étape du portage de la Crypte ; le mécanisme est déjà prêt '
            .'(MoteurTourelle::apparitionsEnVue, testé en jeu avec une ligne de catalogue de test).',
    ];

    /** Les clés que ce vocabulaire déclare. @return list<string> */
    public static function cles(): array
    {
        return array_keys(self::CAPACITES);
    }
}
