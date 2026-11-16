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
     * Déclencheur → les formes de `quand.cible` qu'il accepte, et qui le signale.
     *
     * - `salle` : l'`id` d'une salle du plateau ;
     * - `case` : `{x, y}` ;
     * - `porte` : l'index d'une porte dans `quete.portes` ;
     * - `monstre` : l'index d'un monstre dans `quete.monstres`.
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
        self::DRAPEAU => ['parametres' => ['nom'], 'optionnels' => [], 'lecteur' => 'effetDrapeau', 'libelle' => 'poser un drapeau de quête'],
    ];

    /**
     * Ce qui n'est PAS ici, et pourquoi (« retirer du contenu est un choix écrit ») :
     * - le RÉVEIL de figures inertes (E4) : il existe, mais par la capacité `passif_jusqua` d'un monstre du catalogue
     *   (`MoteurDormants`), pas par une note ;
     * - D5 à D9, E5, E6, E8, E9 : seconde série (lot K5), une à la fois, chacune avec son lecteur.
     */
}
