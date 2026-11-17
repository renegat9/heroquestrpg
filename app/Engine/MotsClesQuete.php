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
     * D7 — un héros RÉUSSIT la fouille de la zone dans une salle (lot K5g). La note REMPLACE ce que le jet aurait révélé :
     * le résultat est celui que l'auteur impose (« rien », une seule porte), comme la note de fouille de trésor.
     */
    public const FOUILLE_ZONE = 'fouille_zone';

    /**
     * Déclencheur → les formes de `quand.cible` qu'il accepte, et qui le signale.
     *
     * - `salle` : l'`id` d'une salle du plateau ;
     * - `case` : `{x, y}` ;
     * - `porte` : l'index d'une porte dans `quete.portes` ;
     * - `monstre` : l'index d'un monstre dans `quete.monstres` ;
     * - `tour` : un rang de tour du MJ (≥ 1) — atteint à partir de ce tour-là (`quetes.tours_mj`).
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

    /** E6 — un bloc de pierre tombe sur une case LIBRE et la ferme pour la quête (lot K5b). */
    public const BLOC = 'bloc';

    /** E5 — une porte secrète est révélée à tous, sans fouille (lot K5b). */
    public const REVELER_PORTE = 'reveler_porte';

    /** E8 — le héros qui déclenche est emporté sur une case (ou la plus proche libre) ; la salle d'arrivée se révèle (lot K5f). */
    public const TELEPORTER = 'teleporter';

    /**
     * Effet → ses paramètres (obligatoires, puis optionnels), et la méthode de `NotesDeQuete` qui le joue.
     *
     * @var array<string, array{parametres: list<string>, optionnels: list<string>, lecteur: string, libelle: string}>
     */
    public const EFFETS = [
        self::TEXTE => ['parametres' => ['texte'], 'optionnels' => [], 'lecteur' => 'effetTexte', 'libelle' => 'lire un texte à la table'],
        self::OR => ['parametres' => ['or'], 'optionnels' => [], 'lecteur' => 'effetOr', 'libelle' => 'donner de l\'or au héros'],
        self::OBJET => ['parametres' => ['slug'], 'optionnels' => ['quantite'], 'lecteur' => 'effetObjet', 'libelle' => 'donner un objet au héros'],
        self::APPARITION => ['parametres' => ['monstres'], 'optionnels' => [], 'lecteur' => 'effetApparition', 'libelle' => 'faire apparaître des monstres'],
        self::DEGATS => ['parametres' => ['body'], 'optionnels' => [], 'lecteur' => 'effetDegats', 'libelle' => 'blesser le héros'],
        self::DRAPEAU => ['parametres' => ['nom'], 'optionnels' => ['campagne'], 'lecteur' => 'effetDrapeau', 'libelle' => 'poser un drapeau de quête'],
        self::OUVRIR_PORTE => ['parametres' => ['porte'], 'optionnels' => [], 'lecteur' => 'effetOuvrirPorte', 'libelle' => 'ouvrir une porte'],
        self::BLOC => ['parametres' => ['x', 'y'], 'optionnels' => [], 'lecteur' => 'effetBloc', 'libelle' => 'faire tomber un bloc'],
        self::REVELER_PORTE => ['parametres' => ['porte'], 'optionnels' => [], 'lecteur' => 'effetRevelerPorte', 'libelle' => 'révéler une porte secrète'],
        self::TELEPORTER => ['parametres' => ['x', 'y'], 'optionnels' => [], 'lecteur' => 'effetTeleporter', 'libelle' => 'téléporter le héros'],
    ];

    /**
     * Ce qui n'est PAS ici, et pourquoi (« retirer du contenu est un choix écrit ») :
     * - le RÉVEIL de figures inertes (E4) : il existe, mais par la capacité `passif_jusqua` d'un monstre du catalogue
     *   (`MoteurDormants`), pas par une note ;
     * - D6, D8 (hors terrain), D9, la salle tournante d'E8 (sortie tirée au dé), E9 et le reste d'E5 (poser une tuile, un meuble) : seconde série (lot K5), une à la fois, chacune
     *   avec son lecteur. D5 et l'ouverture de porte d'E6 sont entrés au lot K5a ; le bloc qui tombe (E6) et la porte
     *   secrète révélée (E5) au lot K5b. La « porte factice » d'E6 n'a pas de mot à elle : c'est une porte au verrou
     *   `note` qu'aucune note n'ouvre.
     */
}
