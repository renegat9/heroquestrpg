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
     * RÉSERVÉ À SON THÈME (chantier EXT-CRYPTE, 2026-10-23) : la créature ne se tire JAMAIS hors de sa boîte, même
     * quand le pool de la rencontre ne contient aucun autre candidat du thème — un pool partagé ferait sinon fuir
     * ses sous-boss dans les campagnes d'une boîte sans sous-boss (la Première Lumière). Lu par
     * `DemarreurQuete::acheterMonstres()` (filtre de pool, AVANT la préférence de thème), via `Monstre::reserveASonTheme()`.
     */
    public const RESERVE_A_SON_THEME = 'reserve_a_son_theme';

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
     * SYNCHROFORMES (Qwindrak the Warlock, livret de quêtes First Light p. 7 : « the warlock assumes one
     * or more distinct creature forms called Synchroforms … If he transforms, place the new miniature on
     * the square occupied by the previous Synchroform »). À chaque forme vaincue, la créature prend la
     * forme SUIVANTE d'une pioche mélangée — la carte de Banjo, validée par René (2026-10-17) : le DRAGON
     * toujours en dernier. Paramétrée : `['formes' => […], 'dernier' => 'Dragon']`. Lu au point de passage
     * unique de la mort, `App\Partie\MoteurSynchroformes::prochaine()`.
     */
    public const SYNCHROFORMES = 'synchroformes';

    /**
     * FLAMME DU DREAD (Qwindrak, forme de Dragon : « souffle la flamme du Dread : chaque héros dans la même
     * salle que lui lance un dé rouge ; sur 3 ou moins, il perd 1 Body Point », `reference/18` §6.1). Portée
     * par la LIGNE du Dragon : c'est son ARRIVÉE qui souffle. Paramétrée : `['degats' => 1]`, seuil dans
     * `App\Engine\Des\DeRouge::SEUIL_FLAMME`.
     */
    public const FLAMME_DU_DREAD = 'flamme_du_dread';

    /**
     * MÉTÉORE (Qwindrak, livret de quêtes First Light p. 31, note C : « In all forms, Qwindrak knows the Dread
     * spells Tempest, Cloud of Dread, and Lightning Bolt. Each time a hero casts a spell or makes an attack
     * against him, Qwindrak hurls a meteor at them. Roll 2 Attack dice for each meteor. »). Paramétrée :
     * `['des_attaque' => 2]`. Portée par la FICHE D'ORIGINE, donc gardée par chaque forme (`InstanceMonstre::
     * ligneDeReference()`). Lu à UN seul endroit, `App\Partie\MoteurMeteore::declencheur()`, appelé par
     * `ResolveurTour::frapper()` (attaque d'un héros) et `ResolveurTour::sousReactionMj()` (sort d'un héros qui
     * vise la figure). Seuls les HÉROS déclenchent ; le météore part après la résolution de l'action.
     */
    public const METEORE = 'meteore';

    /**
     * IMMUNITÉ PARTIELLE PAR FAMILLE (Kessandria, *Return of the Witch Lord* q. 9, p. 25 : « She is immune to
     * all spells except fire spells. »). Liste des ÉLÉMENTS de sort qui passent (`['feu']`) : tout autre sort
     * de héros est refusé. C'est le second barreau de l'échelle d'immunités, après la TOTALE (`immunite_sorts`,
     * Effigie gardienne). Lue au point de passage unique des sorts, `MoteurSorts::sortRefuseSur()`.
     */
    public const IMMUNITE_SORTS_SAUF = 'immunite_sorts_sauf';

    /**
     * VULNÉRABLE UNIQUEMENT À UNE LISTE DE SOURCES (Seigneur Sorcier, *Witch Lord* q. 10, p. 27 : « The Witch Lord
     * can only be harmed by four things at this time: the Spirit Blade, the fire of wrath spell, the ball of
     * flame spell, and the Magical Throwing Dagger. ») — la liste nomme des ARMES (`Objet::nom`) et des SORTS DE
     * DÉGÂTS (`Sort::nom`). Une source absente ne blesse pas : le coup se joue (jet normal) et ne fait aucun dégât,
     * annoncé. Lue à `ResolveurTour::frapper()` pour les coups, `MoteurSorts::sortRefuseSur()` pour les sorts.
     */
    public const VULNERABLE_UNIQUEMENT_A = 'vulnerable_uniquement_a';

    /**
     * INVULNÉRABLE, ET BRISE L'ARME DE L'ATTAQUANT (les quatre statues d'orcs, *Witch Lord* q. 10, p. 27 : « If a
     * hero attacks any of these statues, their weapon breaks (even a Magical Throwing Dagger or crossbow breaks) »).
     * Paramétrée par la liste des ARMES EXEMPTÉES de casse (`['Lame des Esprits']` : « does not break. However, it
     * still does not harm the statue »). L'arme cassée quitte l'inventaire pour de bon, comme la *Rouille*
     * (`MoteurDread::sortDreadDestruction()`), par le même recalcul de combat. Lue à `ResolveurTour::frapper()`.
     */
    public const INVULNERABLE_CASSE_ARME = 'invulnerable_casse_arme';

    /**
     * FUITE À UN TIERS DES BODY (Skulmar, q. 7 p. 21 ; Kessandria, q. 9 p. 25 : « should try to escape »). Le
     * monstre déclenche SON sort de Fuite (`TYPE_FUITE`, déjà porté) dès que ses Body tombent au plancher du tiers
     * de ses Body de départ — et il passe alors avant les autres sorts. Lu par `MoteurDread::seuilFuiteAtteint()`.
     * Le Seigneur Sorcier ne la porte JAMAIS : « there must be no escape for him ».
     */
    public const FUIT_SI_AFFAIBLI = 'fuit_si_affaibli';

    /**
     * RÉPERTOIRE COMPLET : un lanceur NOMMÉ dont le livret donne le répertoire entier ignore le filtre de palier
     * (`MoteurDread::sortsDisponibles()`), comme `sorts_uniques` pour les sorciers de Morcar — SANS sa règle
     * « chaque sort une fois par quête », que le livret ne donne pas. Kessandria (sous-boss, précédent des
     * lieutenants de Morcar) garde *Nuée d'Effroi* et sa potion (*Patinage*), deux sorts de palier boss.
     */
    public const REPERTOIRE_COMPLET = 'repertoire_complet';

    /**
     * PASSIF JUSQU'À DÉCLENCHEUR (Kellar's Keep q. 1 n.C, q. 5 n.B ; Witch Lord q. 5 n.B) : la figure est VISIBLE, mais ne
     * bouge ni n'attaque tant que son déclencheur ne s'est pas produit. Paramétrée :
     *  - `declencheur` (liste fermée {@see self::PASSIF_DECLENCHEURS}) :
     *      `attaque_du_groupe` — un monstre de sa salle a été attaqué : éveil, elle joue au tour suivant du MJ
     *                            (« the skeletons do not move or attack until one of the skeletons has been attacked.
     *                            Then on Zargon's next turn, they all attack »),
     *      `groupe_dans_la_salle` — tous les héros debout sont dans sa salle : elle joue à ce tour du MJ (« does not move
     *                            or attack on its turn until all of the heroes are in the room together »),
     *      `fouille_de_la_salle` — un héros fouille le trésor de sa salle : éveil (la statue du livret attaque aussitôt ;
     *                            ici, au tour suivant du MJ — écart de calendrier écrit dans `MoteurDormants`) ;
     *  - `intouchable_jusqua` (optionnel, liste fermée {@see self::PASSIF_INTOUCHABLE_JUSQUA}) : tant que la condition
     *    n'est pas remplie, personne ne peut la blesser — « cannot be harmed until it has either moved or attacked »
     *    (gargouille-statue, `action`), « cannot be harmed until after it attacks » (Bellthor, `attaque`).
     * Lu par {@see \App\Partie\MoteurDormants}, seul point de passage du sommeil d'un adversaire.
     */
    public const PASSIF_JUSQUA = 'passif_jusqua';

    /** Les déclencheurs que `passif_jusqua` accepte — registre fermé, testé dans `AdversairesNommesTest`. */
    public const PASSIF_DECLENCHEURS = ['attaque_du_groupe', 'groupe_dans_la_salle', 'fouille_de_la_salle'];

    /** Les conditions qui lèvent l'intouchabilité d'un passif : il a bougé ou attaqué, ou il a attaqué. */
    public const PASSIF_INTOUCHABLE_JUSQUA = ['action', 'attaque'];

    /**
     * ATTAQUE DE L'ESPRIT (Borokk, Kellar's Keep q. 9 n.B : « On each of his turns, he attacks the mind of any hero in the
     * same room or corridor and in his line of sight […] rolls 2 combat dice. For each skull […] loses 1 Mind Point » ;
     * Bellthor, Witch Lord q. 5 n.B : « can breathe on any one hero in the room (once per turn) after it has attacked. To
     * do this, it rolls 6 combat dice »). Hors système de sorts. Paramétrée :
     *  - `des` : dés de combat lancés ;
     *  - `portee` : `salle_ou_couloir` (Borokk) | `salle` (Bellthor) ;
     *  - `apres_attaque` (optionnel, Bellthor) : seulement si la figure a porté une attaque physique à ce tour.
     * Le producteur est `MoteurDegats::infligerMindAHeros()`, appelé par `ResolveurTour::attaqueMindMonstre()`.
     */
    public const ATTAQUE_MIND = 'attaque_mind';

    /**
     * DOUBLE SORT (Spirit Queen's Torment : Kavra « On her first turn only, she may cast both spells simultaneously »,
     * G0053 p. 11 ; Nelath « She may cast two spells in a single turn », p. 33). Un SECOND sort de Dread au même tour,
     * jamais le même sort ; la règle générale « un sort par tour » ne bouge pas pour les autres. Paramétrée :
     *  - nue : chaque tour (Nelath) ;
     *  - `['premier_tour' => true]` : au premier tour de Dread joué seulement (Kavra).
     * Lue à UN point, `MoteurDread::doubleSortDisponible()`, appelé par `jouerTourDreadBrut()`.
     */
    public const DOUBLE_SORT = 'double_sort';

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
        self::RESERVE_A_SON_THEME => [
            'lecteur' => 'App\Models\Monstre::reserveASonTheme',
            'libelle' => 'Tirée seulement sous son propre thème, jamais en repli hors de sa boîte',
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
        self::SYNCHROFORMES => [
            'lecteur' => 'App\Partie\MoteurSynchroformes::prochaine',
            'libelle' => 'Se transforme à chaque forme vaincue (pioche mélangée, le Dragon en dernier) ; ses sorts actifs cessent',
        ],
        self::FLAMME_DU_DREAD => [
            'lecteur' => 'App\Partie\MoteurSynchroformes::flammeALArrivee',
            'libelle' => 'Flamme du Dread à son arrivée : chaque héros de la salle lance un dé rouge, 3 ou moins = 1 PV',
        ],
        self::METEORE => [
            'lecteur' => 'App\Partie\MoteurMeteore::declencheur',
            'libelle' => 'Météore : chaque attaque ou sort d\'un héros qui le vise lui vaut 2 dés d\'attaque contre ce héros, toutes formes',
        ],
        self::IMMUNITE_SORTS_SAUF => [
            'lecteur' => 'App\Models\Monstre::immunitesSorts',
            'libelle' => 'Immunisé à tous les sorts SAUF une famille (éléments listés) : les autres sorts sont refusés',
        ],
        self::VULNERABLE_UNIQUEMENT_A => [
            'lecteur' => 'App\Models\Monstre::immunitesSorts',
            'libelle' => 'Ne blessé que par une liste d\'armes et de sorts nommés ; une autre source ne fait aucun dégât',
        ],
        self::INVULNERABLE_CASSE_ARME => [
            'lecteur' => 'App\Models\Monstre::casseArmeExemptees',
            'libelle' => 'Invulnérable : aucun dégât, et l\'arme de l\'attaquant se brise (sauf les armes exemptées)',
        ],
        self::FUIT_SI_AFFAIBLI => [
            'lecteur' => 'App\Partie\MoteurDread::seuilFuiteAtteint',
            'libelle' => 'Fuit (son sort de Fuite, en priorité) dès que ses Body tombent au tiers de ses Body de départ',
        ],
        self::REPERTOIRE_COMPLET => [
            'lecteur' => 'App\Partie\MoteurDread::sortsDisponibles',
            'libelle' => 'Garde son répertoire entier malgré le palier : un sorcier nommé que son livret donne complet',
        ],
        self::PASSIF_JUSQUA => [
            'lecteur' => 'App\Partie\MoteurDormants::estDormant',
            'libelle' => 'Dort jusqu\'à son déclencheur : visible, immobile, sans attaque ; intouchable jusqu\'à son premier geste',
        ],
        self::ATTAQUE_MIND => [
            'lecteur' => 'App\Partie\ResolveurTour::attaqueMindMonstre',
            'libelle' => 'Attaque l\'esprit d\'un héros en vue : chaque crâne de ses dés de combat = 1 Mind perdu',
        ],
        self::DOUBLE_SORT => [
            'lecteur' => 'App\Partie\MoteurDread::doubleSortDisponible',
            'libelle' => 'Lance deux sorts de Dread au même tour (jamais le même), au premier tour seulement si elle le précise',
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
        // `APPARAIT_A_LA_VUE` n'est plus ici (2026-10-23) : le Crâne du Dread (`MonstreSeeder`, boîte
        // `crypte_tenebres`, tier `sous_boss`) la porte, et `MoteurTourelle::apparitionsEnVue()` la lit en jeu.
        self::INVULNERABLE_CASSE_ARME =>'Les quatre statues d\'orcs (Return of the Witch Lord q. 10, p. 27) ne sont PAS '
            .'semées : elles « bloquent totalement le couloir » — c\'est une règle de CARTE (un obstacle sur le passage), '
            .'et une figure du bestiaire ne se pose qu\'en salle (`AssembleurCarte::casesPourFiguresFixes()`) : les semer '
            .'en couloir créerait une seconde règle de placement, les semer en salle leur retirerait leur sens. La forme '
            .'(invulnérable, arme brisée sauf exemptée) est construite et testée en jeu (`ImmunitesSelectivesTest`).',
    ];

    /** Les clés que ce vocabulaire déclare. @return list<string> */
    public static function cles(): array
    {
        return array_keys(self::CAPACITES);
    }
}
