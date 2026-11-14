<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Agent\Memoire\BibleQdrant;
use App\Http\Controllers\Api\GroupeController;
use App\Http\Controllers\Api\MercenaireController;
use App\Jobs\CloturerCampagne;
use App\Jobs\GenererImageHub;
use App\Jobs\GenererImagesQuete;
use App\Jobs\GenererRecitsQuete;
use App\Jobs\GenererSqueletteCampagne;
use App\Jobs\HabillerMonstres;
use App\Partie\ClotureCampagne;
use App\Partie\DemarreurQuete;
use App\Partie\DonObjet;
use App\Partie\Entrainement;
use App\Partie\Forge;
use App\Partie\Marche\PhaseMarche;
use App\Partie\MonteeNiveau;
use App\Partie\PilotageHeros;
use App\Partie\Sauvegarde;
use App\Partie\Votes\VoteGroupe;

/**
 * MODE ARÈNE, lot A2 — le registre des étapes PROPRES À LA CAMPAGNE, fermées à un groupe d'arène
 * (`docs/plan-mode-arene.md` §3).
 *
 * Chaque étape nomme le ou les points d'entrée qui appellent `Groupe::exigerCampagne(<clé>)`. Le registre est testé
 * DANS LES DEUX SENS (`tests/Feature/Arene/EtapesCampagneTest.php`) : chaque site déclaré appelle bien la garde avec sa
 * clé, et chaque appel de la garde dans `app/` est déclaré ici. Un autre test appelle chaque site avec un groupe
 * d'arène et exige l'erreur nommée `RefusArene`.
 *
 * ⚠ Une étape de campagne AJOUTÉE plus tard doit entrer ici, sinon elle s'appliquera à l'arène en silence. C'est la
 * question à se poser en ajoutant un job ou une phase de hub : « que fait-elle à un groupe d'arène ? »
 */
final class EtapesCampagne
{
    public const SQUELETTE = 'squelette_campagne';

    public const ADHESION = 'adhesion_campagne';

    public const DEMARRAGE_QUETE = 'demarrage_quete';

    public const HABILLAGE = 'habillage_monstres';

    public const RECITS = 'recits_quete';

    public const IMAGES_QUETE = 'images_quete';

    public const IMAGE_HUB = 'image_hub';

    public const MONTEE_NIVEAU = 'montee_niveau';

    public const MARCHE = 'marche';

    public const FORGE = 'forge';

    public const ENTRAINEMENT = 'entrainement';

    public const MERCENAIRES = 'mercenaires';

    public const DONS = 'dons';

    public const PILOTAGE = 'pilotage_heros';

    public const VOTE_RETRAITE = 'vote_retraite';

    public const VOTE_SORTIE = 'vote_sortie';

    public const CLOTURE = 'cloture_campagne';

    public const RESUME = 'resume_campagne';

    public const PURGE = 'purge_campagne';

    public const SNAPSHOT = 'instantane';

    public const REPRISE = 'reprise';

    /**
     * @var array<string, array{libelle: string, remplacee_par: string, sites: list<array{class-string, string}>}>
     */
    public const ETAPES = [
        self::SQUELETTE => [
            'libelle' => 'squelette de campagne et bible',
            'remplacee_par' => 'rien : aucun appel d\'IA en arène (AQ5), aucune écriture dans une bible Qdrant — '
                .BibleQdrant::class.'::upsert() n\'est appelé que par ce job',
            'sites' => [[GenererSqueletteCampagne::class, 'handle']],
        ],
        self::ADHESION => [
            'libelle' => 'rejoindre une campagne par son code',
            'remplacee_par' => 'l\'engagement d\'un héros d\'arène par sa doublure (GroupesArene::engager)',
            'sites' => [[GroupeController::class, 'rejoindre']],
        ],
        self::DEMARRAGE_QUETE => [
            'libelle' => 'démarrage de quête (thème de bestiaire, jalons, gabarit, carte générée)',
            'remplacee_par' => 'l\'arène fixe et la composition à puissance d\'équipe (tournoi T1, T3)',
            'sites' => [[DemarreurQuete::class, 'demarrer']],
        ],
        self::HABILLAGE => [
            'libelle' => 'habillage des monstres par l\'IA',
            'remplacee_par' => 'rien : en arène, un monstre garde son nom de catalogue (René)',
            'sites' => [[HabillerMonstres::class, 'handle']],
        ],
        self::RECITS => [
            'libelle' => 'récits pré-générés de la quête',
            'remplacee_par' => 'rien : arène scriptée (AQ5)',
            'sites' => [[GenererRecitsQuete::class, 'handle']],
        ],
        self::IMAGES_QUETE => [
            'libelle' => 'illustrations de quête',
            'remplacee_par' => 'rien : arène scriptée (AQ5)',
            'sites' => [[GenererImagesQuete::class, 'handle']],
        ],
        self::IMAGE_HUB => [
            'libelle' => 'illustration du hub',
            'remplacee_par' => 'rien : l\'arène n\'a pas de hub',
            'sites' => [[GenererImageHub::class, 'handle']],
        ],
        self::MONTEE_NIVEAU => [
            'libelle' => 'montée de niveau',
            'remplacee_par' => 'rien : le héros d\'arène suit le livret, qui n\'a pas de niveaux',
            'sites' => [[MonteeNiveau::class, 'appliquer']],
        ],
        self::MARCHE => [
            'libelle' => 'marché',
            'remplacee_par' => 'rien : l\'or ne sort ni n\'entre dans l\'arène',
            'sites' => [[PhaseMarche::class, 'ouvrir']],
        ],
        self::FORGE => [
            'libelle' => 'forge',
            'remplacee_par' => 'rien',
            'sites' => [[Forge::class, 'appliquer']],
        ],
        self::ENTRAINEMENT => [
            'libelle' => 'entraînement au hub',
            'remplacee_par' => 'rien',
            'sites' => [[Entrainement::class, 'entrainer']],
        ],
        self::MERCENAIRES => [
            'libelle' => 'recrutement de mercenaires',
            'remplacee_par' => 'un allié du catalogue engagé sans coût dans le salon (lot A4)',
            'sites' => [[MercenaireController::class, 'recruter']],
        ],
        self::DONS => [
            'libelle' => 'don d\'objet entre héros',
            'remplacee_par' => 'rien : rien ne sort de l\'arène',
            'sites' => [[DonObjet::class, 'donner']],
        ],
        self::PILOTAGE => [
            'libelle' => 'confier un héros à un autre joueur (pilote)',
            'remplacee_par' => 'rien : un héros d\'arène est engagé par sa doublure, jamais confié',
            'sites' => [[PilotageHeros::class, 'confier']],
        ],
        self::VOTE_RETRAITE => [
            'libelle' => 'vote de retraite',
            'remplacee_par' => '« abandonner le combat » (lot A5)',
            'sites' => [[VoteGroupe::class, 'lancerRetraite']],
        ],
        self::VOTE_SORTIE => [
            'libelle' => 'vote de sortie du donjon',
            'remplacee_par' => 'rien : le combat finit quand une équipe est éteinte (livret F9528 p. 12)',
            'sites' => [[VoteGroupe::class, 'lancerSortie']],
        ],
        self::CLOTURE => [
            'libelle' => 'clôture de campagne',
            'remplacee_par' => 'l\'écran de résultat (lot A5) puis GroupesArene::purger()',
            'sites' => [[ClotureCampagne::class, 'ouvrir']],
        ],
        self::RESUME => [
            'libelle' => 'résumé de campagne par l\'IA',
            'remplacee_par' => 'le palmarès (lot A6)',
            'sites' => [[CloturerCampagne::class, 'handle']],
        ],
        self::PURGE => [
            'libelle' => 'purge d\'une campagne',
            'remplacee_par' => 'GroupesArene::purger(), à double verrou (plan §6)',
            'sites' => [[ClotureCampagne::class, 'purger']],
        ],
        self::SNAPSHOT => [
            'libelle' => 'instantané de sauvegarde',
            'remplacee_par' => 'rien : un combat d\'arène se rejoue, il ne se reprend pas',
            'sites' => [[Sauvegarde::class, 'snapshotter']],
        ],
        self::REPRISE => [
            'libelle' => 'reprise d\'une sauvegarde',
            'remplacee_par' => 'rien : un combat d\'arène se rejoue, il ne se reprend pas',
            'sites' => [[Sauvegarde::class, 'reprendre'], [Sauvegarde::class, 'redemarrerQuete'], [Sauvegarde::class, 'restaurer']],
        ],
    ];

    /**
     * MODE CLASSIQUE (`docs/plan-mode-classique.md` §1, §7) — ce que CHAQUE étape fait à un groupe classique : `null` =
     * elle lui est ouverte, telle quelle ; une chaîne = elle lui est FERMÉE, et voici pourquoi.
     *
     * Testé dans les deux sens avec `self::ETAPES` : toute étape a sa réponse ici, et aucune réponse ne vise une étape
     * qui n'existe pas. Une étape absente est fermée par `Groupe::exigerCampagne()`.
     *
     * Les fermetures suivent les décisions de René du 2026-11-13 (plan §10, Q1 et Q2) : aucun travail d'IA, pas de
     * niveaux ni de talents, pas de forge ni d'entraînement, un groupe qui n'est jamais dissous.
     *
     * @var array<string, string|null>
     */
    public const CLASSIQUE = [
        self::SQUELETTE => 'aucun travail d\'IA en classique : la campagne est écrite dans le constructeur',
        self::ADHESION => null,
        self::DEMARRAGE_QUETE => 'la quête n\'est pas générée : elle démarre par DemarreurQueteConstruite',
        self::HABILLAGE => 'aucun travail d\'IA : un monstre garde son nom de catalogue',
        self::RECITS => 'aucun travail d\'IA : les textes sont ceux de l\'auteur',
        self::IMAGES_QUETE => 'aucun travail d\'IA',
        self::IMAGE_HUB => 'aucun travail d\'IA',
        self::MONTEE_NIVEAU => 'pas de niveaux ni de talents en classique',
        self::MARCHE => null,
        self::FORGE => 'pas de forge en classique',
        self::ENTRAINEMENT => 'pas d\'entraînement en classique',
        // ⚠ FERMÉ POUR L'INSTANT : `DemarreurQueteConstruite` ne pose pas encore les alliés sur la carte. À rouvrir avec
        // le lot qui les pose — pas avant, un allié recruté resterait hors du donjon.
        self::MERCENAIRES => 'les alliés d\'un groupe classique arrivent avec un lot ultérieur',
        self::DONS => null,
        self::VOTE_RETRAITE => null,
        // ⚠ ÉCART TEMPORAIRE à la décision Q4 (sortie case par case, par l'escalier ou la porte de sortie) : tant que le
        // lot R3 n'est pas fait, un groupe classique quitte le donjon par le vote existant. À fermer avec R3.
        self::VOTE_SORTIE => null,
        self::CLOTURE => 'un groupe classique n\'est jamais dissous : finir une campagne ramène au choix de campagne',
        self::RESUME => 'aucun travail d\'IA',
        // La purge reste possible, mais jamais automatique : seul `partie:purger`, avec sa confirmation, y mène.
        self::PURGE => null,
        self::SNAPSHOT => null,
        self::REPRISE => null,
        self::PILOTAGE => null,
    ];
}
