<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * LE VOCABULAIRE FERMÉ DES NOTES DE QUÊTE (mode classique, `docs/plan-mode-classique.md` §2 et §3, lot K3).
 *
 * Une quête construite porte des NOTES : `quand` tel événement se produit, `alors` tels effets s'appliquent. C'est ce
 * que le plateau appelle les notes du livret (« le premier héros qui fouille cette salle trouve… »). Presque toute
 * quête fixe se ramène à cela.
 *
 * L'ordre du projet, sans exception : entrée ICI → lecteur sur une couture existante → test EN JEU → et seulement
 * alors la donnée (la validation l'accepte, le constructeur le propose). Un mot-clé que la validation accepterait
 * sans que rien ne le joue serait une règle promise au joueur et jamais tenue. `MotsClesQueteTest` tient ce registre
 * dans les deux sens : rien n'est déclaré sans lecteur, aucun lecteur ne lit un mot absent d'ici.
 *
 * Lecteur UNIQUE : `App\Partie\Classique\NotesDeQuete`. Les coutures du moteur ne font que SIGNALER un événement
 * (`NotesDeQuete::signaler()`) ; lui seul lit les notes.
 */
final class MotsClesQuete
{
    // ─── Déclencheurs (« quand ») ─────────────────────────────────────────────────────────────────────────────────

    /** D1 — un héros fouille le trésor d'une salle. La note REMPLACE la carte de trésor (LR p. 14). */
    public const FOUILLE_TRESOR = 'fouille_tresor';

    /** D2 — un héros entre dans une salle, ou passe sur une case. */
    public const ENTREE = 'entree';

    /** D3 — une porte désignée est ouverte. */
    public const OUVERTURE_PORTE = 'ouverture_porte';

    /** D4 — une figure désignée tombe. */
    public const MORT = 'mort';

    /**
     * D5 — le tour du maître du jeu commence (avant le premier monstre), à partir du N-ième de la quête (lot K5a). Aucun
     * héros ne déclenche : un effet qui vise « le héros qui déclenche » prend le premier de l'ordre du tour, ou rien.
     */
    public const TOUR_DU_MJ = 'tour_du_mj';

    /**
     * D6 — le DERNIER héros debout franchit une case désignée (lot K5l, *Barak Tor* q. 12 note C : « collapses
     * automatically after the last hero passes by it »). Signalé quand chaque héros debout a foulé la case et qu'aucun
     * ne s'y tient plus : un bloc posé en effet tombe derrière le groupe sans écraser personne.
     */
    public const DERNIER_HEROS_PASSE = 'dernier_heros_passe';

    /**
     * D7 — un héros RÉUSSIT la fouille de la zone dans une salle (lot K5g). La note REMPLACE ce que le jet aurait révélé :
     * le résultat est celui que l'auteur impose (« rien », une seule porte), comme la note de fouille de trésor.
     */
    public const FOUILLE_ZONE = 'fouille_zone';

    // ─── Lot E1 (2026-11-22) : ce que les dix derniers livrets demandent ─────────────────────────────────────────────

    /**
     * Lot E2 — au début du tour du MJ, un héros debout se tient dans la salle ou sur l'une des cases (« If a hero is in any
     * of these corridors during Zargon's turn, they must roll one red die », *Against the Ogre Horde* q. 6 B). Signalé une
     * fois par héros ; ce héros est « celui qui déclenche ».
     */
    public const HEROS_AU_TOUR_DU_MJ = 'heros_au_tour_du_mj';

    /**
     * Lot E2 — un héros JETTE cet objet dans un réceptacle de la quête (`regles.receptacle_objets`) : « They do so by throwing
     * Festral's Ring of Power into the Pit » (*Against the Ogre Horde* q. 8). L'objet quitte son sac.
     */
    public const OBJET_JETE = 'objet_jete';

    /** La DERNIÈRE figure d'une salle tombe (« when the room has been cleared of monsters », F9528 q. 5 ; F6646 q. 8-9). */
    public const SALLE_VIDEE = 'salle_videe';

    /** TOUTES les figures désignées sont tombées (« After the heroes have defeated the Skullblights », F9907 q. 1 ; FL, G0052). */
    public const MORTS = 'morts';

    /** TOUS les héros debout sont dans la salle (« As soon as all the heroes enter this room », F5815 q. 5 ; F6646). */
    public const TOUS_ENTRES = 'tous_entres';

    /** Un héros QUITTE la salle (« Each time a room is exited for the first time », G0053 q. 6 ; F9528 q. 10). */
    public const SALLE_QUITTEE = 'salle_quittee';

    /** Un héros TERMINE SON TOUR dans la salle ou sur la case (« If a hero ends their turn in this room », F9907 q. 4, 7). */
    public const FIN_DE_TOUR = 'fin_de_tour';

    /** Un PIÈGE se déclenche dans la salle ou sur la case (« or whenever a trap is triggered », G0053 q. 1, 6 ; F7539). */
    public const PIEGE_DECLENCHE = 'piege_declenche';

    /** Un MEUBLE posé par l'auteur est détruit (« the Dread chests », « destroy the High Altar », G1504 q. 8, 10). */
    public const MEUBLE_DETRUIT = 'meuble_detruit';

    /**
     * Lot E2 (*Prophecy of Telor*, q. 10 G) — « When every hero stands at a different doorway » : chaque héros debout se tient
     * sur une case de l'une des portes désignées, chacun à une porte différente.
     */
    public const TOUS_AUX_PORTES = 'tous_aux_portes';

    /** Un CAPTIF est libéré (« if Revelroot was freed », F9907 q. 5). */
    public const CAPTIF_LIBERE = 'captif_libere';

    /**
     * Lot E2 (*Prophecy of Telor*, q. 7 à 10) — « If the bearer of the talisman fails this dice roll, Melar may immediately
     * cast Summon Orcs » : le porteur de l'objet désigné vient de rater son jet de fardeau (`MoteurFardeau`).
     */
    public const FARDEAU_RATE = 'fardeau_rate';

    /**
     * Lot E2 (*Spirit Queen's Torment*, q. 14 C) — « she breaks the enchantment placed upon her by Zargon and thanks the heroes
     * for saving her before fading » : la figure rappelée à 0 point d'Esprit s'efface, apaisée (`Classique\RappelDeLaReine`).
     */
    public const FIGURE_APAISEE = 'figure_apaisee';

    /**
     * Déclencheur → les formes de `quand.cible` qu'il accepte, et qui le signale.
     *
     * - `salle` : l'`id` d'une salle du plateau ;
     * - `case` : `{x, y}` ;
     * - `porte` : l'index d'une porte dans `quete.portes` ;
     * - `monstre` : l'index d'un monstre dans `quete.monstres` ;
     * - `tour` : un rang de tour du MJ (≥ 1) — atteint à partir de ce tour-là (`quetes.tours_mj`).
     * - `monstres` : une liste d'index de `quete.monstres` (lot E1) — toutes tombées ;
     * - `mobilier` : l'index d'un meuble dans `quete.mobilier` (lot E1) ;
     * - `captif` : le slug d'un mercenaire captif du catalogue (lot E1).
     *
     * @var array<string, array{cibles: list<string>, signale_par: string, libelle: string}>
     */
    public const DECLENCHEURS = [
        self::FOUILLE_TRESOR => [
            'cibles' => ['salle'],
            'signale_par' => 'ResolveurTour::resoudreFouilleTresor()',
            'libelle' => 'un héros fouille le trésor de la salle',
        ],
        self::ENTREE => [
            'cibles' => ['salle', 'case'],
            'signale_par' => 'ResolveurTour::resoudre()',
            'libelle' => 'un héros entre dans la salle, ou passe sur la case',
        ],
        self::OUVERTURE_PORTE => [
            'cibles' => ['porte'],
            'signale_par' => 'ResolveurTour::resoudreOuvrirPorte()',
            'libelle' => 'la porte est ouverte',
        ],
        self::MORT => [
            'cibles' => ['monstre'],
            'signale_par' => 'MoteurDegats::infligerAMonstreBrut()',
            'libelle' => 'la figure tombe',
        ],
        self::FOUILLE_ZONE => [
            'cibles' => ['salle'],
            'signale_par' => 'ResolveurTour::resoudreJet()',
            'libelle' => 'un héros réussit la fouille de la zone (pièges, portes secrètes) dans la salle',
        ],
        self::TOUR_DU_MJ => [
            'cibles' => ['tour'],
            'signale_par' => 'ResolveurTour::phaseMonstres()',
            'libelle' => 'le tour du maître du jeu commence, à partir du N-ième',
        ],
        self::DERNIER_HEROS_PASSE => [
            'cibles' => ['case'],
            'signale_par' => 'ResolveurTour::signalerEntrees()',
            'libelle' => 'le dernier héros en vie franchit la case',
        ],
        self::SALLE_VIDEE => [
            'cibles' => ['salle'],
            'signale_par' => 'MoteurDegats::infligerAMonstreBrut()',
            'libelle' => 'la dernière figure de la salle tombe',
        ],
        self::MORTS => [
            'cibles' => ['monstres'],
            'signale_par' => 'MoteurDegats::infligerAMonstreBrut()',
            'libelle' => 'toutes les figures désignées sont tombées',
        ],
        self::TOUS_ENTRES => [
            'cibles' => ['salle'],
            'signale_par' => 'ResolveurTour::signalerEntrees()',
            'libelle' => 'tous les héros debout sont entrés dans la salle',
        ],
        self::SALLE_QUITTEE => [
            'cibles' => ['salle'],
            'signale_par' => 'ResolveurTour::signalerEntrees()',
            'libelle' => 'un héros quitte la salle',
        ],
        self::FIN_DE_TOUR => [
            'cibles' => ['salle', 'case'],
            'signale_par' => 'ResolveurTour::resoudre()',
            'libelle' => 'un héros termine son tour dans la salle, ou sur la case',
        ],
        self::PIEGE_DECLENCHE => [
            'cibles' => ['salle', 'case'],
            'signale_par' => 'MoteurPieges::declencher() / declencherEphemere()',
            'libelle' => 'un piège se déclenche dans la salle, ou sur la case',
        ],
        // `salle` (lot E2, *Jungles of Delthrak*, les cocons) : n'importe quel meuble de la salle — « For each cocoon a hero
        // destroys ». Le héros qui le détruit est celui de la note.
        self::MEUBLE_DETRUIT => [
            'cibles' => ['mobilier', 'salle'],
            'signale_par' => 'MoteurMobilier::detruire() / infligerDegats()',
            'libelle' => 'le meuble est détruit (ce meuble, ou un meuble de la salle)',
        ],
        self::HEROS_AU_TOUR_DU_MJ => [
            'cibles' => ['salle', 'cases'],
            'signale_par' => 'ResolveurTour::phaseMonstres()',
            'libelle' => 'au tour du maître du jeu, un héros se tient dans la salle ou sur l\'une des cases',
        ],
        self::OBJET_JETE => [
            'cibles' => ['objet'],
            'signale_par' => 'ResolveurTour::resoudreJeterObjetQuete()',
            'libelle' => 'un héros jette l\'objet dans le réceptacle de la quête',
        ],
        self::TOUS_AUX_PORTES => [
            'cibles' => ['portes'],
            'signale_par' => 'ResolveurTour::signalerEntrees()',
            'libelle' => 'chaque héros se tient à une porte différente parmi celles désignées',
        ],
        self::CAPTIF_LIBERE => [
            'cibles' => ['captif'],
            'signale_par' => 'ResolveurTour::resoudreLibererCaptif()',
            'libelle' => 'le captif est libéré',
        ],
        self::FIGURE_APAISEE => [
            'cibles' => ['monstre'],
            'signale_par' => 'Classique\\RappelDeLaReine::rappeler()',
            'libelle' => 'la figure, rappelée à son passé, s\'efface apaisée',
        ],
        self::FARDEAU_RATE => [
            'cibles' => ['objet'],
            'signale_par' => 'MoteurFardeau::debutDeTour()',
            'libelle' => 'le porteur de l\'objet rate son jet de fardeau',
        ],
    ];

    // ─── Effets (« alors ») ───────────────────────────────────────────────────────────────────────────────────────

    /** E1 — lire un texte à la table. */
    public const TEXTE = 'texte';

    /** E2 — donner de l'or au héros qui déclenche. */
    public const OR = 'or';

    /** E2 — donner un objet du catalogue au héros qui déclenche. */
    public const OBJET = 'objet';

    /** E3 — faire apparaître des monstres sur des cases fixées. */
    public const APPARITION = 'apparition';

    /** E7 — infliger des points de Corps au héros qui déclenche. */
    public const DEGATS = 'degats';

    /** E10 — poser un drapeau de quête, relu par une autre note (`quand.si_drapeau`) ou par la condition de fin. */
    public const DRAPEAU = 'drapeau';

    /**
     * E6 — ouvrir une porte de la quête, quel que soit son verrou (lot K5a) : c'est ce qui ouvre la porte « que seul le
     * maître du jeu ouvre » (`portes[].verrou.type = note`). Les salles qu'elle borde se révèlent comme à la main.
     */
    public const OUVRIR_PORTE = 'ouvrir_porte';

    /**
     * E4 — ÉVEILLER des figures posées `inactif` (René, 2026-11-20 : « ce n'est pas le même principe que la gargouille, mais
     * avec l'ouverture d'une porte ? ») : la Légion oubliée, « encased in a magical ice that cannot be broken with any weapon »
     * jusqu'à ce qu'on ouvre la porte B (*Witch Lord* q. 7). Le réveil est celui des dormants (`MoteurDormants`).
     */
    public const EVEILLER = 'eveiller';

    /** E6 — un bloc de pierre tombe sur une case LIBRE et la ferme pour la quête (lot K5b). */
    public const BLOC = 'bloc';

    /** E5 — une porte secrète est révélée à tous, sans fouille (lot K5b). */
    public const REVELER_PORTE = 'reveler_porte';

    /** E8 — le héros qui déclenche est emporté sur une case (ou la plus proche libre) ; la salle d'arrivée se révèle (lot K5f). */
    public const TELEPORTER = 'teleporter';

    /** E9 — détruire un objet précis (artefact, arme, armure) dans l'inventaire des héros présents dans la salle (*The Cold Halls*, q. 2 note D). */
    public const DETRUIRE_OBJET = 'detruire_objet';

    /** Lot E2 (*Prophecy of Telor*, q. 4 D, q. 6 C) — le héros apprend un sort de Dread, pour la campagne (`Classique\TalismanDeTelor`). */
    public const APPRENDRE_SORT_DREAD = 'apprendre_sort_dread';

    /** Lot E2 (*Prophecy of Telor*, q. 10 G) — « can no longer cast Dread spells » : chaque héros oublie les sorts appris. */
    public const OUBLIER_SORTS_DREAD = 'oublier_sorts_dread';

    /** Lot E2 (*Prophecy of Telor*, « Gain 1 Mind Point ») — le total d'Esprit du héros bouge, au-delà du maximum de départ. */
    public const ESPRIT = 'esprit';

    /** Lot E2 (*Prophecy of Telor*, q. 10 G) — « Mind Points revert to their total before starting this quest pack ». */
    public const ESPRIT_D_ORIGINE = 'esprit_d_origine';

    /**
     * Lot E2 (*Prophecy of Telor*, « The Remaining Quests », p. 10) — l'objet `slug` devient l'objet `par` chez tout héros
     * qui le possède, à la même place : le talisman ordinaire devient le talisman maudit (q. 7), puis l'inverse (q. 10 G).
     */
    public const REMPLACER_OBJET = 'remplacer_objet';

    /** Lot E2 (*Prophecy of Telor*, q. 6 C) — « roll 2 red dice. Move the hero that number of squares along the path » (`Classique\CheminImpose`). */
    public const AVANCER_SUR_CHEMIN = 'avancer_sur_chemin';

    /**
     * Lot E2 (*Prophecy of Telor*, q. 5 B) — les héros (sauf celui qui déclenche, avec `sauf_declencheur`) deviennent la figure
     * `en` : ses dés, `pv_body` points de Corps au plus, aucun sort (`Classique\Metamorphose`).
     */
    public const METAMORPHOSER = 'metamorphoser';

    /** Lot E2 (*Prophecy of Telor*, q. 5 D) — « all heroes are returned to their former selves and restored to their full Body Points ». */
    public const RENDRE_FORME = 'rendre_forme';

    /** Lot E2 (*Prophecy of Telor*, q. 10 G) — « Move each hero one space forward » : chaque héros à l'une de ces portes la franchit. */
    public const FRANCHIR_PORTES = 'franchir_portes';

    /** C5 (lot K6, *Dread Moon*) — le groupe gagne (ou perd) des jetons de réputation. */
    public const REPUTATION = 'reputation';

    /**
     * C5 (lot K6, *Dread Moon*) — l'auteur DÉCLARE un choix : un libellé, un coût (or du héros, jetons du groupe) et des
     * effets joués quand un héros l'accepte au menu (« Zargon must declare when the heroes have the opportunity to spend a
     * reputation token »). Les offres d'un même `groupe` s'excluent. `Classique\Reputation`.
     */
    public const OFFRE = 'offre';

    /** Lot K6 (*Dread Moon*) — un mercenaire de la campagne devient engageable (« choose one elven mercenary to unlock »). */
    public const DEBLOQUER_MERCENAIRE = 'debloquer_mercenaire';

    /**
     * Lot E1 (2026-11-22) — on LANCE des dés rouges, et l'issue choisit les effets (« roll one red die: 1-3 … 4-6 … »,
     * F9528 q. 6, G0052, F9907). `{des?: 1|2, issues: [{de, a, alors: [effets]}]}`, les plages couvrant le total.
     */
    public const JET = 'jet';

    /** Lot E1 — un objet TIRÉ AU HASARD dans une liste (« draws a spell scroll card at random », F5815 ; potions, artefacts). */
    public const OBJET_AU_HASARD = 'objet_au_hasard';

    /** Lot E1 — des figures posées QUITTENT le plateau sans tomber (fuite, disparition) : jamais « vaincues ». */
    public const RETIRER = 'retirer';

    /** Lot E1 — un meuble posé disparaît (« the bookcase crumbles to dust », la statue qui s'anime : avec une `apparition`). */
    public const RETIRER_MEUBLE = 'retirer_meuble';

    /** Lot E1 — une porte se ferme et disparaît (« the two doors close and disappear », F5815 q. 5) : murée pour la quête. */
    public const MURER_PORTE = 'murer_porte';

    /** Lot E1 — un bloc est retiré (« remove the blocked square », G0052 q. 12) : la case redevient du couloir. */
    public const RETIRER_BLOC = 'retirer_bloc';

    /** Lot E1 — le captif de la quête est LIBÉRÉ par une note (« If the Barbarian kills the Dread Warriors, Gothar is freed »). */
    public const LIBERER_CAPTIF = 'liberer_captif';

    /** Lot E2 — la marche du héros S'ARRÊTE sur la case de la note (« the hero stops on that square », piège à errant, F5815). */
    public const ARRETER = 'arreter';

    /**
     * Lot E2 — on VOLE le héros qui déclenche : de l'or (`or`), sinon un objet de son SAC — « it cannot be the armor or shield
     * a hero is using, nor the weapon they are wielding » (le Gremlin de glace, F5815 q. 4 B ; le Voleur, F6646).
     */
    public const VOLER = 'voler';

    /**
     * Lot E2 — les MONSTRES ERRANTS de la quête surgissent autour du héros qui déclenche et frappent aussitôt (le piège à
     * monstre errant, F5815 p. 6) : le même surgissement que la carte de fouille, `ResolveurTour::surgissementErrant()`.
     */
    public const ERRANTS = 'errants';

    /**
     * Lot E2 — le piège posé sur la case se déclenche (« If a hero opens the door on the right, the swinging blade trap
     * activates », F9528 q. 7 A ; « The treasure chest is rigged to trigger a swinging blade trap », q. 4 B).
     */
    public const DECLENCHER_PIEGE = 'declencher_piege';

    /**
     * Lot E2 (*Prophecy of Telor*, q. 7 à 11) — Melar lance un sort d'INVOCATION de Dread (« Melar may immediately cast Summon
     * Orcs ») : la table du sort tire les figures, posées au plus près du héros qui déclenche, ou de la case `{x, y}` (« The
     * summoned Orcs may be placed in any vacant square in the room », q. 11 E). Le tirage et la pose sont ceux du sort
     * lancé par une figure (`MoteurDread::invoquerPres()`).
     */
    public const INVOQUER = 'invoquer';

    /**
     * Lot E2 (*Prophecy of Telor*, q. 2 A) — « at the start of each of your turns, place 2 Goblins and 1 Orc to the side of the
     * board in the area marked B » : des figures rejoignent la zone hors du plateau (`regles.reserve_hors_plateau`), d'où
     * elles entrent par sa case (`Classique\ReserveHorsPlateau`).
     */
    public const EN_RESERVE = 'en_reserve';

    /**
     * Lot E2 (*Spirit Queen's Torment*, q. 8 B : « restore all of their lost Mind Points ») — le héros qui déclenche récupère
     * des points de Corps et/ou d'Esprit : un nombre, ou `tout` (jusqu'au maximum). Jamais au-delà du maximum.
     */
    public const SOIGNER = 'soigner';

    /**
     * Lot E2 (*Jungles of Delthrak*, les cocons, q. 3 C, 6 C, 11 D, 13 D) — « one Blightcrawler Spawnling. Place a Spawnling tile
     * on the hero's card. That hero's turn immediately ends » : `nombre` jetons (1 par défaut) sur la fiche du héros qui
     * déclenche ; son tour s'arrête (`Classique\Rejetons`).
     */
    public const REJETONS = 'rejetons';

    /**
     * Lot E2 (*Jungles of Delthrak*, les cocons, q. 3 C, 6 C, 11 D, 13 D) — « For each cocoon a hero destroys, they find the
     * following (one at a time and in order): one Potion of Healing, one gem worth 200 gold coins, and one Blightcrawler
     * Spawnling » : chaque fois que l'effet se joue, l'ÉTAPE suivante (`etapes[].alors`) ; toutes jouées, plus rien. Le
     * compteur vit en base (`quetes.drapeaux._en_ordre`), par note. La note est `une_fois: false`.
     */
    public const EN_ORDRE = 'en_ordre';

    /** Les effets qui en contiennent d'autres (`alors`, `issues[].alors`, `etapes[].alors`, `sinon`) : jamais imbriqués l'un dans l'autre. */
    public const EFFETS_COMPOSES = [self::OFFRE, self::JET, self::EN_ORDRE];

    /**
     * Effet → ses paramètres (obligatoires, puis optionnels), et la méthode de `NotesDeQuete` qui le joue.
     *
     * @var array<string, array{parametres: list<string>, optionnels: list<string>, lecteur: string, libelle: string}>
     */
    public const EFFETS = [
        self::TEXTE => ['parametres' => ['texte'], 'optionnels' => [], 'lecteur' => 'effetTexte', 'libelle' => 'lire un texte à la table'],
        // `partage` (lot E1, 2026-11-22) : « Large amounts of gold should be divided among all surviving heroes » (F5815
        // p. 6, et la règle de plusieurs livrets) — `groupe` le partage, `par_heros` en donne autant à chacun.
        self::OR => ['parametres' => ['or'], 'optionnels' => ['partage'], 'lecteur' => 'effetOr', 'libelle' => 'donner de l\'or au héros'],
        // `sinon` (lot E1) : ce qui se joue à la place quand le groupe a déjà l'objet (« instead finds 500 gold coins », F9907).
        self::OBJET => ['parametres' => ['slug'], 'optionnels' => ['quantite', 'si_absent_du_groupe', 'sinon'], 'lecteur' => 'effetObjet', 'libelle' => 'donner un objet au héros'],
        self::OBJET_AU_HASARD => ['parametres' => ['parmi'], 'optionnels' => ['quantite'], 'lecteur' => 'effetObjetAuHasard', 'libelle' => 'donner un objet tiré au hasard dans une liste'],
        self::JET => ['parametres' => ['issues'], 'optionnels' => ['des'], 'lecteur' => 'effetJet', 'libelle' => 'lancer des dés : l\'issue choisit les effets'],
        self::RETIRER => ['parametres' => ['monstres'], 'optionnels' => [], 'lecteur' => 'effetRetirer', 'libelle' => 'retirer des figures du plateau (fuite, disparition)'],
        self::RETIRER_MEUBLE => ['parametres' => ['mobilier'], 'optionnels' => [], 'lecteur' => 'effetRetirerMeuble', 'libelle' => 'retirer un meuble'],
        self::MURER_PORTE => ['parametres' => ['porte'], 'optionnels' => [], 'lecteur' => 'effetMurerPorte', 'libelle' => 'murer une porte (elle se ferme et disparaît)'],
        self::RETIRER_BLOC => ['parametres' => ['x', 'y'], 'optionnels' => [], 'lecteur' => 'effetRetirerBloc', 'libelle' => 'retirer un bloc'],
        self::LIBERER_CAPTIF => ['parametres' => [], 'optionnels' => [], 'lecteur' => 'effetLibererCaptif', 'libelle' => 'libérer le captif de la quête'],
        self::ARRETER => ['parametres' => [], 'optionnels' => [], 'lecteur' => 'effetArreter', 'libelle' => 'arrêter le héros sur cette case'],
        self::DECLENCHER_PIEGE => ['parametres' => ['x', 'y'], 'optionnels' => [], 'lecteur' => 'effetDeclencherPiege', 'libelle' => 'déclencher le piège de la case'],
        self::ERRANTS => ['parametres' => [], 'optionnels' => [], 'lecteur' => 'effetErrants', 'libelle' => 'faire surgir les monstres errants de la quête autour du héros'],
        self::VOLER => ['parametres' => [], 'optionnels' => ['or'], 'lecteur' => 'effetVoler', 'libelle' => 'voler le héros (de l\'or, ou un objet de son sac)'],
        // `pres_du_heros` (lot E2, *Jungles of Delthrak* q. 6 C) : près du héros qui déclenche, et chaque figure au contact frappe aussitôt.
        self::APPARITION => ['parametres' => ['monstres'], 'optionnels' => ['nombre_d6', 'pres_du_heros'], 'lecteur' => 'effetApparition', 'libelle' => 'faire apparaître des monstres'],
        self::DEGATS => ['parametres' => ['body'], 'optionnels' => ['sur_jet', 'avec_casque', 'toute_la_salle', 'tous_les_heros'], 'lecteur' => 'effetDegats', 'libelle' => 'blesser le héros'],
        self::DRAPEAU => ['parametres' => ['nom'], 'optionnels' => ['campagne'], 'lecteur' => 'effetDrapeau', 'libelle' => 'poser un drapeau de quête'],
        self::OUVRIR_PORTE => ['parametres' => ['porte'], 'optionnels' => [], 'lecteur' => 'effetOuvrirPorte', 'libelle' => 'ouvrir une porte'],
        self::EVEILLER => ['parametres' => ['monstres'], 'optionnels' => [], 'lecteur' => 'effetEveiller', 'libelle' => 'éveiller des figures inactives'],
        self::BLOC => ['parametres' => ['x', 'y'], 'optionnels' => [], 'lecteur' => 'effetBloc', 'libelle' => 'faire tomber un bloc'],
        self::REVELER_PORTE => ['parametres' => ['porte'], 'optionnels' => [], 'lecteur' => 'effetRevelerPorte', 'libelle' => 'révéler une porte secrète'],
        self::TELEPORTER => ['parametres' => ['x', 'y'], 'optionnels' => ['destinations_d6'], 'lecteur' => 'effetTeleporter', 'libelle' => 'téléporter le héros'],
        self::DETRUIRE_OBJET => ['parametres' => ['slug'], 'optionnels' => ['toute_la_salle'], 'lecteur' => 'effetDetruireObjet', 'libelle' => 'détruire un objet du héros'],
        self::APPRENDRE_SORT_DREAD => ['parametres' => ['sort'], 'optionnels' => [], 'lecteur' => 'effetApprendreSortDread', 'libelle' => 'apprendre un sort de Dread au héros'],
        self::OUBLIER_SORTS_DREAD => ['parametres' => [], 'optionnels' => [], 'lecteur' => 'effetOublierSortsDread', 'libelle' => 'faire oublier aux héros les sorts de Dread appris'],
        self::ESPRIT => ['parametres' => ['points'], 'optionnels' => [], 'lecteur' => 'effetEsprit', 'libelle' => 'gagner ou perdre des points d\'Esprit (total du héros)'],
        self::ESPRIT_D_ORIGINE => ['parametres' => [], 'optionnels' => [], 'lecteur' => 'effetEspritDOrigine', 'libelle' => 'ramener l\'Esprit des héros au total d\'avant la campagne'],
        self::REMPLACER_OBJET => ['parametres' => ['slug', 'par'], 'optionnels' => [], 'lecteur' => 'effetRemplacerObjet', 'libelle' => 'changer un objet en un autre chez qui le possède'],
        self::AVANCER_SUR_CHEMIN => ['parametres' => ['des'], 'optionnels' => [], 'lecteur' => 'effetAvancerSurChemin', 'libelle' => 'faire avancer le héros sur le chemin imposé (dés)'],
        self::METAMORPHOSER => ['parametres' => ['en', 'pv_body'], 'optionnels' => ['sauf_declencheur'], 'lecteur' => 'effetMetamorphoser', 'libelle' => 'changer les héros en monstres (dés, Corps plafonné, sans sorts)'],
        self::RENDRE_FORME => ['parametres' => [], 'optionnels' => [], 'lecteur' => 'effetRendreForme', 'libelle' => 'rendre aux héros leur forme et tout leur Corps'],
        self::FRANCHIR_PORTES => ['parametres' => ['portes'], 'optionnels' => [], 'lecteur' => 'effetFranchirPortes', 'libelle' => 'faire franchir aux héros les portes où ils se tiennent'],
        self::REPUTATION => ['parametres' => ['jetons'], 'optionnels' => [], 'lecteur' => 'effetReputation', 'libelle' => 'gagner ou perdre des jetons de réputation'],
        self::OFFRE => ['parametres' => ['libelle', 'alors'], 'optionnels' => ['cout', 'groupe'], 'lecteur' => 'effetOffre', 'libelle' => 'proposer un choix (coût, effets)'],
        self::DEBLOQUER_MERCENAIRE => ['parametres' => ['slug'], 'optionnels' => [], 'lecteur' => 'effetDebloquerMercenaire', 'libelle' => 'débloquer un mercenaire'],
        self::EN_RESERVE => ['parametres' => ['monstres'], 'optionnels' => [], 'lecteur' => 'effetEnReserve', 'libelle' => 'placer des figures dans la zone hors du plateau'],
        self::SOIGNER => ['parametres' => [], 'optionnels' => ['body', 'mind'], 'lecteur' => 'effetSoigner', 'libelle' => 'soigner le héros (Corps, Esprit : un nombre ou « tout »)'],
        self::EN_ORDRE => ['parametres' => ['etapes'], 'optionnels' => [], 'lecteur' => 'effetEnOrdre', 'libelle' => 'jouer l\'étape suivante, une à la fois et dans l\'ordre'],
        self::REJETONS => ['parametres' => [], 'optionnels' => ['nombre'], 'lecteur' => 'effetRejetons', 'libelle' => 'accrocher des Spawnling à la fiche du héros (son tour s\'arrête)'],
        self::INVOQUER => ['parametres' => ['sort'], 'optionnels' => ['x', 'y'], 'lecteur' => 'effetInvoquer', 'libelle' => 'lancer un sort d\'invocation de Dread (les figures surgissent près du héros, ou de la case)'],
    ];

    /**
     * Ce qui n'est PAS ici, et pourquoi (« retirer du contenu est un choix écrit ») :
     * - le RÉVEIL de figures inertes (E4) : par la capacité `passif_jusqua` d'un monstre du catalogue (`MoteurDormants`), et
     *   depuis le 2026-11-20 par une note (`eveiller`) pour une figure posée `inactif` ;
     * - D6, D8 (hors terrain), D9, la salle tournante d'E8 (sortie tirée au dé), E9 et le reste d'E5 (poser une tuile, un meuble) : seconde série (lot K5), une à la fois, chacune
     *   avec son lecteur. D5 et l'ouverture de porte d'E6 sont entrés au lot K5a ; le bloc qui tombe (E6) et la porte
     *   secrète révélée (E5) au lot K5b. La « porte factice » d'E6 n'a pas de mot à elle : c'est une porte au verrou
     *   `note` qu'aucune note n'ouvre.
     */
}
