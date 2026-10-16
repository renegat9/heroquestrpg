<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * CE QUE LE MJ A LE DROIT DE FAIRE d'un héros dont il prend la main — le profil que
 * {@see HerosJoueParLeMj} exécute.
 *
 * « Le MJ joue un héros » existait déjà une fois, en pire : *Possession* (High Mage, Wizards of
 * Morcar) le jetait vers un monstre sans attaque ni sort, par une méthode de `MoteurDread` qui ne
 * servait qu'elle. *Dominate* (Sorcier du Dread d'*Against the Ogre Horde*, p. 10) demande plus —
 * « Zargon may move, fight, cast spells, and perform any other action as the Dominated hero
 * (including attacking other heroes) » — et la *Malédiction du loup-garou* du Mage du Miroir
 * (« Zargon controls them as a monster for one turn ») le demandera demain. Trois cartes, UNE
 * couture : elles ne diffèrent que par ce profil, et jamais par une seconde copie de la marche, du
 * franchissement des pièges, du coup porté à un autre héros.
 *
 * LES DEUX CONSTRUCTEURS NOMMÉS sont les deux clients d'aujourd'hui. Un troisième client = un
 * troisième constructeur nommé, ET — si sa règle diffère par autre chose qu'un de ces cinq champs
 * (le loup-garou joue avec les stats D'UN MONSTRE, pas avec celles du héros) — une branche de
 * `HerosJoueParLeMj::jouer()` qui LIT ce champ, jamais un champ sans lecteur : c'est la couture
 * décrite dans `docs/regles/sorts-dread.md` §« Le MJ joue un héros ».
 *
 * @see HerosJoueParLeMj
 */
final readonly class ControleMj
{
    /** Le MJ le jette vers le MONSTRE le plus proche (Possession). */
    public const CIBLE_MONSTRE = 'monstre';

    /** Le MJ le jette vers un AUTRE HÉROS (Domination). */
    public const CIBLE_HEROS = 'heros';

    /** Le déplacement de base de la classe, sans d6 : la carte ne dit pas de distance (Possession). */
    public const DEPLACEMENT_BASE = 'base';

    /** Son déplacement COMPLET de ce round, tel qu'il l'a lancé pour son propre tour (Domination). */
    public const DEPLACEMENT_COMPLET = 'complet';

    public function __construct(
        /** Le nom du profil, repris par les payloads (`possession`, `domination`). */
        public string $nom,
        /** Vers qui le MJ envoie le héros : {@see self::CIBLE_MONSTRE} ou {@see self::CIBLE_HEROS}. */
        public string $cible,
        /** De combien il avance : {@see self::DEPLACEMENT_BASE} ou {@see self::DEPLACEMENT_COMPLET}. */
        public string $deplacement,
        /** Frappe-t-il à l'arme un héros à son contact ? */
        public bool $attaque,
        /** Lance-t-il un sort offensif de son répertoire sur un héros en vue ? */
        public bool $sorts,
    ) {}

    /**
     * *Possess* : « Zargon will move this figure on its next turn. The affected figure may not
     * attack or cast spells. » Le déplacement de base vers le monstre le plus proche, au contact,
     * SANS frapper.
     */
    public static function possession(): self
    {
        return new self(
            nom: 'possession',
            cible: self::CIBLE_MONSTRE,
            deplacement: self::DEPLACEMENT_BASE,
            attaque: false,
            sorts: false,
        );
    }

    /**
     * *Dominate* : « Zargon […] may immediately take that hero's full movement and action […] move,
     * fight, cast spells, and perform any other action as the Dominated hero (including attacking
     * other heroes). » Le déplacement COMPLET vers un autre héros, le coup à l'arme au contact, ou
     * un sort offensif sur un héros en vue.
     */
    public static function domination(): self
    {
        return new self(
            nom: 'domination',
            cible: self::CIBLE_HEROS,
            deplacement: self::DEPLACEMENT_COMPLET,
            attaque: true,
            sorts: true,
        );
    }
}
