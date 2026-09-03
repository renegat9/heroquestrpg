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

    // -------------------------------------------------------------- CHARGES

    /**
     * Nombre d'utilisations INITIAL de l'objet — « There are only 4 arrows with
     * this bow. It becomes useless afterwards » (Arc elfique de Vindication).
     *
     * Le restant vit sur l'exemplaire (`inventaire.charges`), pas sur le
     * catalogue : deux héros portent le même arc avec des flèches différentes.
     * À zéro l'objet devient INERTE — il reste au sac, son effet ne s'applique
     * plus. Rien n'est détruit : un objet qui disparaîtrait seul du sac serait
     * une surprise, pas une règle.
     * Lecteur : `Partie\MoteurCharges`.
     */
    public const CHARGES = 'charges';

    /**
     * Tue la cible d'emblée, sauf si elle obtient un **bouclier noir** sur un
     * unique dé de défense — « instantly kills any one monster within the Elf's
     * line of sight, unless the monster rolls a black shield on 1 combat die ».
     *
     * S'accompagne TOUJOURS de `charges` : une mort instantanée illimitée
     * viderait un donjon sans combat.
     * Lecteur : `ResolveurTour::resoudreAttaque()`.
     */
    public const TUE_SAUF_BOUCLIER_NOIR = 'tue_sauf_bouclier_noir';

    /**
     * Annule intégralement les dégâts d'une NATURE donnée (`App\Engine\TypeDegat`),
     * au prix d'une charge — « prevents the wearer from being affected by the
     * next two Fire or Chaos Fire spells they encounter. The ring turns to ash
     * after protecting the wearer from the second spell » (Anneau de Feu).
     *
     * Immunité, pas réduction : la carte dit « not affected », pas « moins ».
     * Lecteur : `MoteurSorts::absorbeDegat()`, consulté par les deux chemins qui
     * blessent un héros — un sort de héros en tir ami et un sort de Dread.
     */
    public const IMMUNITE_DEGAT = 'immunite_degat';

    // ------------------------------------------------------- ÉCONOMIE DE SORTS

    /**
     * Rend des sorts épuisés au porteur. **`true` = tous** — « restores all
     * spells that Hero possessed at the beginning of the quest » (Parchemin de
     * Sorts) ; un **entier = ce nombre-là**, ce qu'exigent deux cartes
     * officielles : Potion de magie (« recover up to 3 spells you have cast
     * during this quest ») et Potion de rappel (un seul, Elfe).
     *
     * ⚠ La clé n'a pas changé de nom en changeant de type : `! empty()` reste
     * vrai dans les trois cas, donc aucun appelant existant ne bouge.
     *
     * À distinguer du nœud *Concentration*, qui en récupère un en sacrifiant le
     * tour. Lecteurs : `MoteurPotions` (consommable), `Partie\Equipement::equiper()`
     * (pièce à charge, dépensée en l'enfilant).
     */
    public const RESTAURE_SORTS = 'restaure_sorts';

    /**
     * Un SECOND sort dans le même tour — « allows you to cast two spells instead
     * of one during your turn » (Baguette de Rappel).
     *
     * Exactement le pouvoir du nœud *Réserve arcanique*, mais accordé par un
     * OBJET : les deux passent par `etat.bonus_sort_utilise`, donc ils ne se
     * cumulent pas (un magicien équipé n'obtient pas trois sorts).
     * Lecteurs : `MenuMoteur`, `ResolveurTour`.
     */
    public const SECOND_SORT_PAR_TOUR = 'second_sort_par_tour';

    /**
     * Le prochain sort lancé **ne s'épuise pas**, au prix d'une charge —
     * « enables the Elf or Wizard who carries it to cast one spell twice in the
     * same Quest » (Anneau de Sort).
     *
     * ⚠ Écart assumé : la carte fait CHOISIR le sort au début de la quête. Ici
     * le choix se fait en le lançant, ce qui donne le même résultat sans imposer
     * un pari à l'aveugle avant d'avoir vu le donjon.
     * Lecteur : `ResolveurTour::lancerSort()`.
     */
    public const SORT_NON_EPUISE = 'sort_non_epuise';


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

    // ------------------------------------------------------- OUTIL ET MATÉRIEL

    /**
     * Permet de désamorcer un piège — « you must possess a tool kit (or be the
     * dwarf) » (LR p. 19). Lecteur : `Partie\MoteurPieges`.
     */
    public const PERMET_DESAMORCAGE = 'permet_desamorcage';

    /**
     * Tue d'office les créatures dont le `monstres.nom_base` est listé — Eau
     * bénite : « It kills any undead creature (skeleton, zombie, or mummy) »
     * (carte © 2021, doc 16 §2.1bis).
     *
     * ⚠ La liste vit ICI, sur l'objet, et **aucun tag « mort-vivant » n'est
     * inventé** sur `monstres` : c'est déjà ainsi que la Lame des Esprits
     * (`des_attaque_contre`) et le Fléau des Orques (`attaque_double_contre`)
     * nomment leurs cibles. Le test porte sur `nom_base`, JAMAIS sur le nom
     * habillé par l'IA — sinon l'eau bénite cesserait de reconnaître un
     * squelette à la première quête narrée.
     *
     * Lecteur : `ResolveurTour::resoudreUsageObjet()`.
     */
    public const TUE_CREATURES = 'tue_creatures';

    /**
     * Pose une tuile de chausse-trappes sur la case traversée — « If a creature
     * moves onto a caltrops tile, they roll one combat die. If it lands on a
     * white shield, they may continue their movement » (carte © 2023).
     *
     * Vit dans la couche `cartes.grille['chausse_trappes']`, au même niveau que
     * `leviers` / `pieges` / `mobilier`, mais posée AU RUNTIME.
     * Lecteurs : `ResolveurTour::resoudreUsageObjet()` (pose) et
     * `ResolveurTour::tronquerSurChausseTrappes()` (effet, héros ET monstres).
     */
    public const POSE_CHAUSSE_TRAPPES = 'pose_chausse_trappes';

    /**
     * Enfume un monstre adjacent — « all heroes move unseen through the
     * monster's space » jusqu'à son prochain tour (carte © 2023).
     *
     * Pose la condition monstre `enfume` (`instances_monstres.habillage`), lue
     * par `FabriqueGrille::pour()` : le monstre sort de `$occupees`, donc il ne
     * bloque plus NI le mouvement NI la ligne de vue — les deux tombent
     * ensemble parce que c'est la seule boucle d'occupation du moteur.
     */
    public const ENFUME_MONSTRE_ADJACENT = 'enfume_monstre_adjacent';

    /**
     * Le porteur est « toujours considéré armé de » l'arme nommée —
     * Bandoulière : « you are always considered to be armed with a dagger »
     * (carte © 2022, Rogue uniquement).
     *
     * ⚠ Il ne gagne AUCUN dé : il gagne les règles qui exigent cette arme
     * (l'Ambidextrie du Rogue, la fermeture des techniques mains nues). Aucune
     * arme virtuelle n'est injectée dans `Equipement::armesEnMain()`, dont les
     * entrées sont de vraies lignes d'inventaire qu'on supprime et qu'on
     * équipe. Lecteur : `Equipement::compteCommeArme()`.
     */
    public const COMPTE_COMME_ARME = 'compte_comme_arme';

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
     * Une RELANCE des dés d'attaque ratés — Potion de bataille : « It allows
     * you 1 reroll of your Attack dice » (carte © 2021).
     *
     * Le calcul existait déjà pour le nœud *Coup puissant*
     * (`Engine\Combat::relancerRatees()`, qui garde les réussites) ; la potion
     * ne fait qu'ouvrir un second déclencheur.
     * Lecteur : `ResolveurTour::frapper()`.
     */
    public const RELANCE_DES_ATTAQUE = 'relance_des_attaque';

    /**
     * Multiplie les dégâts d'UNE attaque — Potion de force glaciale : « their
     * next attack causes twice as many Body Points of damage as are rolled »
     * (carte © 2022, Barbare seul).
     *
     * ⚠ Passe par `ResultatAttaque::avecDegatsMultiplies()` et jamais par une
     * multiplication à la main : `frapper()` relit `pvBodyApres` cinq fois
     * (écriture, mort de la cible, regain de sort, bark, payload), et deux
     * vérités s'y contrediraient. Lecteur : `MoteurSorts::multiplicateurDegats()`.
     */
    public const MULTIPLICATEUR_DEGATS = 'multiplicateur_degats';

    /**
     * Cases de déplacement EN PLUS pour ce tour — Potion de dextérité : « adds
     * 5 movement squares to your next dice roll » (carte © 2021).
     *
     * ⚠ Homonyme d'une mécanique de COMPÉTENCE (`competences.effet.mecanique =
     * bonus_deplacement`, nœuds *Pas léger* / *Charge*), qui vit dans une autre
     * table et modifie `personnages.deplacement_base` en permanence. Ici c'est
     * un buff temporaire. Lecteur : `ResolveurTour::pointsDeplacement()`, et
     * son miroir obligatoire dans `MenuMoteur` — sans quoi le menu annonce une
     * portée que le résolveur refuse.
     */
    public const BONUS_DEPLACEMENT = 'bonus_deplacement';

    /**
     * Le franchissement de fosse réussit d'office — l'autre moitié de la Potion
     * de dextérité : « or guarantees one successful pit jump ».
     *
     * Le jet a QUAND MÊME lieu et le joueur voit ce que la potion lui a
     * épargné, exactement comme le *Dragon bondissant* du Moine
     * (`ResultatJet::force()`). Lecteur : `ResolveurTour::resoudreFranchissement()`.
     */
    public const SAUT_FOSSE_AUTOMATIQUE = 'saut_fosse_automatique';

    /**
     * Multiplie les dés de déplacement — Potion de vitesse : « roll twice as
     * many dice as usual the next time you move » (carte © 2021).
     *
     * Aucun lecteur neuf : `MoteurSorts::multiplicateurDeplacement()` existe
     * pour Vent Véloce et lit déjà les buffs de source `potion:` autant que
     * `sort:`.
     */
    public const DEPLACEMENT_MULTIPLIE = 'deplacement_multiplie';

    /**
     * Révèle pièges ET portes secrètes en ligne de vue — Potion de vision :
     * « enables an Elf to see all secret doors and regular traps […] within
     * their line of sight » (carte © 2023, Elfe seul).
     *
     * S'arrête au premier sang (`duree: premier_degat_subi`, déjà câblée par
     * `Personnage::booted()`). Lecteurs : `MoteurPieges::revelerEnVue()` et
     * `MoteurPortes::revelerSecretesEnVue()`.
     */
    public const REVELE_PIEGES_ET_PORTES_EN_VUE = 'revele_pieges_et_portes_en_vue';

    /**
     * Ramène Body ET Mind au niveau du DÉBUT DE LA QUÊTE — Potion de
     * restauration supérieure (carte © 2023).
     *
     * Chez nous c'est littéralement « au maximum » : `DemarreurQuete` remet les
     * deux jauges à leur plafond à chaque quête, donc rien n'a besoin de
     * mémoriser un état de départ. `MoteurPotions`.
     */
    public const RESTAURE_JAUGES_DEPART = 'restaure_jauges_depart';

    /**
     * Une seule potion de ce type par tour — Potion de dextérité : « If you
     * purchase more than one of these potions, you may use only one potion per
     * turn » (carte © 2021).
     *
     * ⚠ La garde ne porte QUE sur les potions marquées : c'est ce que dit la
     * carte, et brider les quatorze autres inventerait une règle. Compteur
     * réutilisé : `etat_personnage_quete.capacites_tour`. `MoteurPotions`.
     */
    public const UNE_PAR_TOUR = 'une_par_tour';

    /**
     * Quand le buff s'arrête — vocabulaire `DureeEffet`, PAS libre.
     * Un objet qui pose un bonus sans `duree` ne s'arrête jamais : c'est
     * exactement le bug qui a rendu la Potion de défense permanente.
     */
    public const DUREE = 'duree';

    // ------------------------------------------------- ARTEFACTS ACTIVABLES
    //
    // Trois cartes officielles (doc 16 §9.2) réunissent sur un OBJET ce qui
    // existait déjà sur des SORTS. Aucune mécanique neuve : ces mots-clés
    // n'ouvrent que le chemin — la liste « Utiliser un objet », le ciblage, et
    // la résolution qui délègue aux lecteurs de sorts.

    /** L'objet entre dans la liste « Utiliser un objet » sans être un consommable. */
    public const ACTIVABLE = 'activable';

    /** Qui l'objet vise : `soi` · `heros` · `monstre` (vocabulaire de MotsClesSort). */
    public const CIBLE = 'cible';

    /** Créneau dépensé par l'usage : `action` ou `gratuit`. */
    public const COUT = 'cout';

    /** Le porteur traverse la ROCHE (Cape des Ombres) — lecteur MoteurSorts::traverseRoche(). */
    public const FRANCHIT_MUR = 'franchit_mur';

    /** Le porteur traverse les FIGURES (Poudre, Cape) — MoteurSorts::franchitFigures(). */
    public const FRANCHIT_FIGURES = 'franchit_figures';

    /** La cible saute son prochain tour (Sceptre de Télékinésie). */
    public const SAUTE_TOUR = 'saute_tour';

    /** Comment la cible résiste — `rupture_6_par_mind` (MoteurSorts::tenterRupture()). */
    public const RESISTANCE = 'resistance';

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
        self::ACTIVABLE,
        self::CIBLE,
        self::COUT,
        self::FRANCHIT_MUR,
        self::FRANCHIT_FIGURES,
        self::SAUTE_TOUR,
        self::RESISTANCE,
        self::DES_ATTAQUE_CONTRE,
        self::ATTAQUE_DOUBLE_CONTRE,
        self::BONUS_PV_BODY_MAX,
        self::BONUS_PV_MIND_MAX,
        self::CHARGES,
        self::TUE_SAUF_BOUCLIER_NOIR,
        self::IMMUNITE_DEGAT,
        self::RESTAURE_SORTS,
        self::SECOND_SORT_PAR_TOUR,
        self::SORT_NON_EPUISE,
        self::PERMET_DESAMORCAGE,
        self::TUE_CREATURES,
        self::POSE_CHAUSSE_TRAPPES,
        self::ENFUME_MONSTRE_ADJACENT,
        self::COMPTE_COMME_ARME,
        self::SOIN_PV_BODY,
        self::SOIN_PV_BODY_DE,
        self::SOIN_PV_MIND,
        self::BONUS_DES_ATTAQUE,
        self::BONUS_DES_DEFENSE,
        self::ATTAQUE_SUPPLEMENTAIRE,
        self::CONDITION_APPLIQUEE,
        self::RETIRE_CONDITION,
        self::RELANCE_DES_ATTAQUE,
        self::MULTIPLICATEUR_DEGATS,
        self::BONUS_DEPLACEMENT,
        self::SAUT_FOSSE_AUTOMATIQUE,
        self::DEPLACEMENT_MULTIPLIE,
        self::REVELE_PIEGES_ET_PORTES_EN_VUE,
        self::RESTAURE_JAUGES_DEPART,
        self::UNE_PAR_TOUR,
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
