<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Epreuve;
use App\Models\GabaritQuete;
use App\Models\Mobilier;
use App\Models\Piege;
use App\Models\Terrain;
use App\Models\Tuile;
use App\Partie\Aleatoire\PrngLineaire;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Assemblage procédural de la carte d'une quête depuis la bibliothèque de
 * tuiles seedées (doc 06 §3) : salles posées sur une GRILLE 2D en ARBRE
 * BRANCHU (une salle peut avoir jusqu'à 4 embranchements), reliées par des
 * couloirs d'UNE ou DEUX voies (LR p. 11, tirées une sur deux — 2026-09-27)
 * débouchant par un seuil d'une case — fini la chaîne gauche-droite (playtest F).
 *
 * Algorithme :
 *  1. nb de salles tiré dans [salles.min (plancher NB_SALLES_MIN=2), salles.max] ;
 *     tuiles « salle » génériques mélangées (Fisher-Yates), une par salle
 *     (bouclage si moins de tuiles que de salles) ; si le gabarit prévoit une
 *     rencontre finale, la DERNIÈRE salle (une feuille, cf. 2) utilise la
 *     tuile de thème « boss » ;
 *  2. ARBRE : la salle 0 est la racine. Chaque salle i≥1 choisit un PARENT
 *     parmi les salles déjà posées qui a encore une case de grille libre
 *     orthogonalement adjacente (N/S/E/W) — la salle boss, posée en DERNIER,
 *     ne reçoit jamais d'enfant (feuille) ;
 *  3. GRILLE UNIFORME : chaque salle occupe un « slot » de taille fixe
 *     (max des tuiles + couloir + marge) et est CENTRÉE dedans → deux salles
 *     adjacentes sur la grille ont leurs lignes/colonnes médianes ALIGNÉES,
 *     ce qui garantit des couloirs droits ;
 *  4. chaque tuile est peinte sur `cases` (ses propres cases « p » sont
 *     refermées en mur : les portes réelles sont percées par le générateur) ;
 *  5. pour chaque arête (parent, enfant) : un couloir à 1 ou 2 voies (tirage,
 *     voir `creuserArete()`), débouchant
 *     par un SEUIL D'UNE SEULE CASE — une porte-arête par salle, soit 2 par
 *     jonction (décision de René, 2026-08-08 : le plateau n'a que des portes
 *     d'une case). Les seuils étaient larges de 2 cases pour qu'un tank ne
 *     bouche pas le passage au tireur (test de jeu 2026-07-31, magicien muet
 *     8 tours) ; mais le jeu officiel règle ce cas AUTREMENT, par l'attaque en
 *     DIAGONALE — « deux héros à la fois peuvent attaquer un monstre qui bloque
 *     un seuil de porte », LR p. 14, cf. reference/16_armurerie.md §6.2, règle
 *     que le moteur applique déjà (`attaque_diagonale`). La seconde voie du
 *     couloir subsiste : elle élargit le COULOIR, jamais le seuil ; ⚠ EXCEPTION
 *     (René, 2026-09-11) — `accolerSallesMitoyennes()` (juste avant, à l'étape
 *     4bis) glisse par défaut chaque salle-FEUILLE mur contre mur avec sa
 *     parente : l'arête devient alors MITOYENNE, le couloir se réduit à
 *     l'ancienne case de mur des deux salles, et cette même arête ne pousse
 *     plus qu'UNE SEULE porte (pas 2) — « dans le jeu original il n'y en a
 *     pas » ; les vrais couloirs ne subsistent que pour les salles non-feuilles
 *     et celles que le chevauchement empêche d'accoler ;
 *  6. pièges (structure.pieges.min) posés au milieu des couloirs ET en salle —
 *     jamais de Chute de blocs dans un couloir à voie unique ;
 *  7. spawns : héros dans la salle 0 ; monstres en ROUND-ROBIN sur les autres
 *     salles (répartition — fini « tous dans la dernière pièce ») en
 *     commençant par la salle finale (boss/feuille posée en dernier), pour
 *     que `spawn_monstres[0]` (= le boss côté DemarreurQuete) y atterrisse.
 *
 * Codes de case (TuileSeeder) : m = mur, s = sol, p = porte.
 * Toutes les tuiles « salle » seedées ont un intérieur en sol PLEIN (rectangle
 * sans alcôve), ce qui garantit qu'une porte percée sur la ligne/colonne
 * médiane du slot ouvre toujours sur du sol, quelle que soit la tuile.
 */
final class AssembleurCarte
{
    /** Longueur du couloir entre deux salles (dimensionne la grille de slots). */
    public const LONGUEUR_COULOIR = 3;

    /**
     * Largeur MAXIMALE des couloirs en cases (correctifs F) : 2 = deux
     * figurines de front — la ligne/colonne médiane et celle JUSTE AVANT. Le
     * seuil, lui, fait toujours UNE case (René, 2026-08-08).
     *
     * ⚠ Un couloir sur deux n'a plus qu'UNE voie (René, 2026-09-27 : « il est
     * plutôt facile de contourner les trappes plutôt que de les désamorcer ou
     * sauter par-dessus ») — le livret dit « one or two squares wide » (LR
     * p. 11). Voir `CHANCE_VOIE_UNIQUE` et `creuserArete()`.
     */
    public const LARGEUR_COULOIR = 2;

    /**
     * Chance (sur 100) qu'un couloir n'ait qu'UNE voie (René, 2026-09-27). Un
     * tirage par couloir, et pas « les couloirs piégés sont étroits » : ce
     * serait un signal que les joueurs apprennent (« couloir étroit = piège »),
     * le même défaut que « un couloir = un piège » corrigé dans `placerPieges()`.
     */
    public const CHANCE_VOIE_UNIQUE = 50;

    public const NB_SALLES_MIN = 2;

    /** Nb max de positions de spawn retournées (héros / monstres). */
    public const MAX_SPAWNS_HEROS = 8;

    /**
     * @return array{
     *   largeur: int, hauteur: int,
     *   cases: list<list<string>>,
     *   salles: list<array{x: int, y: int, largeur: int, hauteur: int, theme: string, mediane_x: int, mediane_y: int}>,
     *   portes: list<array{x: int, y: int, etat: string, verrou?: array<string, mixed>, revele?: bool}>,
     *   leviers: list<array{x: int, y: int, levier_id: string}>,
     *   pieges: list<array{x: int, y: int, piege_id: int|null, etat: string}>,
     *   mobilier: list<array{mobilier_id: int, x: int, y: int, l: int, h: int, salle: int}>,
     *   epreuves: list<array{x: int, y: int, epreuve_id: int, salle: int, tentee_par: list<int>}>,
     *   terrain: list<array{x: int, y: int, terrain_id: int, paire_id?: string}>,
     *   escalier: array{x: int, y: int, l: int, h: int}|null,
     *   spawn_heros: list<array{x: int, y: int}>,
     *   spawn_monstres: list<array{x: int, y: int}>,
     *   aretes: list<array{a: int, b: int, porte_a: array{x: int, y: int}, porte_b: array{x: int, y: int}}>
     * }
     */
    /** Chance de base qu'une carte cache une salle derrière une porte secrète. */
    public const CHANCE_PASSAGE_SECRET = 50;

    /** Ce que chaque carte SANS passage ajoute à la chance de la suivante. */
    public const PALIER_PASSAGE_SECRET = 10;

    /** Nom du terrain « Tunnel de glace » — identifie l'entrée de catalogue à poser par paires. */
    private const NOM_TERRAIN_TUNNEL = 'Tunnel de glace';

    /**
     * @param  int  $chancePassageSecret  probabilité (en %) qu'une salle soit
     *                                    cachée derrière une porte secrète —
     *                                    le compteur de pitié du groupe, qui
     *                                    monte tant qu'aucune n'est tombée
     * @param  ?BestiaireGroupe  $bestiaire  bestiaire du groupe (automatique
     *                                   ou manuel, `BestiaireGroupe`) — filtre
     *                                   la couche TERRAIN (doc 18 §4) : un
     *                                   terrain dont `boite` n'est ni `null` ni
     *                                   un thème de la campagne n'est jamais
     *                                   posé. `null` (les tests) ne pose QUE
     *                                   les terrains `boite = null` — fail
     *                                   open, jamais une erreur.
     * @param  ?\Closure  $sallesACoffre  `(array{salles: list<...>, aretes:
     *                                   list<...>, portes: list<...>}): list<int>`
     *                                   — désigne les salles qui DOIVENT
     *                                   recevoir un Coffre réel (René,
     *                                   2026-09-18 : la narration décrivait un
     *                                   coffre qu'aucune carte ne portait).
     *                                   Appelée ICI, entre la pose des portes
     *                                   et `placerMobilier()`, avec la carte
     *                                   PARTIELLE déjà stable à ce stade
     *                                   (salles/arêtes/portes ne bougent plus
     *                                   ensuite) — jamais après coup, sous
     *                                   peine de contourner le plancher de
     *                                   cases jouables de `placerMobilier()`
     *                                   (§2.12 ter) ou de poser le coffre sur
     *                                   une case qu'un monstre occupera déjà
     *                                   (`spawnsMonstres()` ne tourne qu'en
     *                                   toute fin d'assemblage). Le CALCUL des
     *                                   salles-coffre reste entièrement celui
     *                                   de `DeckFouille::construire()` — câblage
     *                                   des récompenses, INTACT — cette
     *                                   fermeture ne fait QUE lui fournir, au
     *                                   bon moment, la carte partielle dont il
     *                                   a besoin ; `null` (défaut, tous les
     *                                   appelants existants) ne garantit rien,
     *                                   comportement inchangé.
     */
    public function assembler(GabaritQuete $gabarit, int $graine = 0, int $chancePassageSecret = self::CHANCE_PASSAGE_SECRET, ?BestiaireGroupe $bestiaire = null, ?\Closure $sallesACoffre = null): array
    {
        $structure = $gabarit->structure ?? [];
        $suivant = $this->creerPRNG($graine);

        $tuiles = $this->choisirTuiles($structure, $suivant);

        // §2.12 — la salle 0 accueille TOUT le groupe : elle doit être assez
        // grande pour que personne ne démarre encerclé par ses propres alliés.
        // Certaines tuiles « 4×4 » n'offrent que 5 à 6 cases utiles (le contour
        // d'une salle est du mur) : quatre héros y tenaient à peine, et le
        // premier joueur à jouer perdait tout son déplacement du tour 1.
        // On promeut donc la plus grande tuile en tête — l'ordre de `$tuiles`
        // pilotant à la fois l'arbre, les salles et les spawns, la carte reste
        // parfaitement cohérente et déterministe.
        $tuiles = $this->plusGrandeTuileEnTete($tuiles);

        $n = count($tuiles);

        // --- Arbre branchu sur grille 2D --------------------------------
        ['grille' => $positionsGrille, 'aretes' => $aretes, 'passage_secret' => $passageSecret]
            = $this->construireArbre($n, $suivant, $chancePassageSecret);

        // §2.12 ter EN AMONT (brief coffre, René 2026-09-18) : AVANT que la
        // moindre tuile ne soit peinte, on s'assure que toute salle qu'un
        // passage secret désignera comme salle-au-coffre est assez grande
        // pour porter son `Coffre` — voir `garantirTaillesSallesACoffre()`
        // pour le pourquoi de l'ordre (une fois peintes, les couloirs sont
        // creusés sur la largeur/hauteur de chaque salle : un échange après
        // coup demanderait de tout recreuser).
        $tuiles = $this->garantirTaillesSallesACoffre($tuiles, $aretes);

        // --- Slots uniformes, salles centrées ---------------------------
        $maxLargeurTuile = max(array_map(fn (Tuile $t) => (int) $t->grille['largeur'], $tuiles));
        $maxHauteurTuile = max(array_map(fn (Tuile $t) => (int) $t->grille['hauteur'], $tuiles));
        $slotLargeur = $maxLargeurTuile + self::LONGUEUR_COULOIR + 2;
        $slotHauteur = $maxHauteurTuile + self::LONGUEUR_COULOIR + 2;

        // Normalisation (min à 0) + marge extérieure d'un slot.
        $minGx = min(array_column($positionsGrille, 0));
        $minGy = min(array_column($positionsGrille, 1));
        foreach ($positionsGrille as $i => [$gx, $gy]) {
            $positionsGrille[$i] = [$gx - $minGx + 1, $gy - $minGy + 1];
        }
        $maxGx = max(array_column($positionsGrille, 0));
        $maxGy = max(array_column($positionsGrille, 1));

        $largeur = ($maxGx + 2) * $slotLargeur;
        $hauteur = ($maxGy + 2) * $slotHauteur;

        $cases = array_fill(0, $hauteur, array_fill(0, $largeur, 'm'));
        $salles = [];

        // --- Position de chaque salle : centrée dans son slot ------------
        $poses = [];
        foreach ($tuiles as $i => $tuile) {
            $w = (int) $tuile->grille['largeur'];
            $h = (int) $tuile->grille['hauteur'];
            [$gx, $gy] = $positionsGrille[$i];

            $poses[$i] = [
                'x' => $gx * $slotLargeur + intdiv($slotLargeur - $w, 2),
                'y' => $gy * $slotHauteur + intdiv($slotHauteur - $h, 2),
                'largeur' => $w, 'hauteur' => $h,
            ];
        }

        // Salles MITOYENNES : certaines salles-feuilles sont collées à leur
        // parente, mur contre mur — on ouvre alors une porte directement de
        // l'une à l'autre, sans couloir. C'est le cas de figure du plateau (une
        // annexe, un cabinet, une salle au trésor qui donne sur la grande
        // salle) et ça casse la monotonie du « couloir, salle, couloir ».
        $aretes = $this->accolerSallesMitoyennes($aretes, $poses);

        // --- Pose des salles ---------------------------------------------
        foreach ($tuiles as $i => $tuile) {
            $x = $poses[$i]['x'];
            $y = $poses[$i]['y'];
            $w = $poses[$i]['largeur'];
            $h = $poses[$i]['hauteur'];

            foreach ($tuile->grille['cases'] as $r => $ligne) {
                foreach ($ligne as $c => $case) {
                    // Les portes « possibles » de la tuile sont refermées :
                    // les portes réelles sont percées sur les couloirs.
                    $cases[$y + $r][$x + $c] = $case === 'p' ? 'm' : $case;
                }
            }

            $salles[$i] = [
                'x' => $x, 'y' => $y, 'largeur' => $w, 'hauteur' => $h, 'theme' => $tuile->theme,
                // Centre de la salle (doc « fog » à venir) — cases entières.
                'mediane_x' => $x + intdiv($w, 2), 'mediane_y' => $y + intdiv($h, 2),
            ];
        }

        // --- Couloirs + portes uniques par arête -------------------------
        $portesSpec = (array) data_get($structure, 'portes', []);
        $portes = [];
        $aretesSortie = [];
        $milieuxCouloirs = [];
        // Milieux des couloirs à VOIE UNIQUE (clé « x,y ») : une Chute de blocs
        // y fermerait le passage à jamais, parfois vers la salle objectif
        // (René, 2026-09-27) — `placerPieges()` ne l'y tire donc jamais.
        $milieuxVoieUnique = [];
        // Index (dans `$portes`) de la porte PARENT de chaque arête — celle qui
        // porte la restriction et que `placerLeviers()` peut verrouiller. Une
        // jonction ORDINAIRE pousse 2 entrées (parent puis enfant), une jonction
        // MITOYENNE n'en pousse plus qu'UNE (2026-09-11, cf. `creuserArete()`) :
        // l'ancien calcul `2 * $indexArete` supposait un pas fixe et se
        // déréglait dès la première mitoyenne. On note donc la position RÉELLE
        // au moment de la pousser, plutôt que de la recalculer.
        $indexPorteParentParArete = [];

        foreach ($aretes as $indexArete => $arete) {
            // Une liaison supplémentaire marquée `secrete` l'emporte sur la spec
            // du gabarit : c'est elle qui crée la boucle cachée à découvrir.
            $spec = ! empty($arete['secrete'])
                ? ['etat' => MoteurPortes::ETAT_SECRETE]
                : $this->specPorte($portesSpec, $indexArete);

            // ⚠ Tiré pour CHAQUE arête, mitoyenne comprise (où il ne change
            // rien) : consommer le PRNG dans les deux branches garde la suite
            // des nombres identique quelle que soit la géométrie — même règle
            // que le tirage du passage secret.
            $voieUnique = $suivant() % 100 < self::CHANCE_VOIE_UNIQUE;

            $resultat = $this->creuserArete(
                $cases, $salles, $positionsGrille, $slotLargeur, $slotHauteur,
                $arete, $spec, $voieUnique,
            );

            $indexPorteParentParArete[$indexArete] = count($portes);

            // `jonction` : toutes les portes d'un même passage (jusqu'à 4 quand
            // il est large de 2 cases) partagent cet identifiant, pour que
            // MoteurPortes les ouvre ENSEMBLE — sans quoi un passage à 2 cases
            // s'ouvrirait à moitié et resterait un goulot d'une case. Une
            // jonction MITOYENNE n'a qu'une porte : `porte_enfant` vaut alors
            // `null` (cf. `creuserArete()`) et n'est PAS poussée — la case
            // qu'elle aurait encadrée est déjà du sol sans restriction, rien à
            // déclarer.
            foreach ([$resultat['porte_parent'], $resultat['porte_enfant'], ...$resultat['portes_secondaires']] as $porte) {
                if ($porte === null) {
                    continue;
                }
                $porte['jonction'] = $indexArete;
                // Propage le drapeau de boucle de l'arête à SA porte — voir
                // `liaisonsSupplementaires()` et `marquerPortesDePierre()`.
                $porte['boucle'] = ! empty($arete['boucle']);
                $portes[] = $porte;
            }
            $aretesSortie[] = [
                'a' => $arete['parent'], 'b' => $arete['enfant'],
                'porte_a' => ['x' => $resultat['porte_parent']['x'], 'y' => $resultat['porte_parent']['y']],
                // Mitoyenne : pas de porte_enfant distincte — on republie celles
                // du parent (distance 0), pour que les consommateurs qui lisent
                // porte_a/porte_b comme « les deux bouts du seuil » (cf.
                // CouloirsTest) continuent de trouver un couple valide.
                'porte_b' => $resultat['porte_enfant'] !== null
                    ? ['x' => $resultat['porte_enfant']['x'], 'y' => $resultat['porte_enfant']['y']]
                    : ['x' => $resultat['porte_parent']['x'], 'y' => $resultat['porte_parent']['y']],
            ];
            // Une jonction MITOYENNE n'a pas de couloir : son « milieu » tomberait
            // dans la salle voisine, où un piège de couloir n'a rien à faire.
            if (empty($arete['mitoyenne'])) {
                $milieuxCouloirs[] = $resultat['milieu'];

                if ($voieUnique) {
                    $milieuxVoieUnique["{$resultat['milieu']['x']},{$resultat['milieu']['y']}"] = true;
                }
            }
        }

        $portes = $this->devoilerSecretesEnConflit($portes);

        // PORTE DE PIERRE (Against the Ogre Horde, lot B) : AVANT
        // `placerLeviers()` à dessein, même si les deux ne peuvent jamais se
        // disputer la même porte (un levier ne verrouille qu'une arête de
        // L'ARBRE, une porte de pierre qu'une arête de BOUCLE — voir
        // `marquerPortesDePierre()`) : garder l'ordre des couches lisible de
        // haut en bas.
        $portes = $this->marquerPortesDePierre($portes, $suivant, $bestiaire);

        // Salles à garantir un coffre (2026-09-18, brief coffre) : appelé ICI
        // — $salles/$aretesSortie/$portes ont exactement la forme que porte le
        // carte final pour ces trois clés, et ne bougent plus ensuite (locker
        // une porte, plus bas, ne touche jamais une porte SECRÈTE — seules les
        // `fermee` sont candidates). Sans callback (tous les appelants qui ne
        // connaissent pas la notion de coffre — la quasi-totalité des tests),
        // liste vide : comportement rigoureusement inchangé.
        $sallesCoffreAGarantir = $sallesACoffre !== null
            ? $sallesACoffre(['salles' => $salles, 'aretes' => $aretesSortie, 'portes' => $portes])
            : [];

        // ⚠ AVANT les pièges : placerLeviers() peut verrouiller une porte et
        // pose son levier sur une case de sol — les pièges ne doivent jamais
        // atterrir dessus (même raison que les seuils), et placerPieges() en
        // reçoit donc la liste ci-dessous.
        $leviers = $this->placerLeviers($structure, $cases, $salles, $portes, $n, $indexPorteParentParArete, $suivant);
        $pieges = $this->placerPieges($structure, $milieuxCouloirs, $milieuxVoieUnique, $cases, $salles, $portes, $leviers, $suivant, $bestiaire);

        // LAME BALANÇOIRE (Against the Ogre Horde, lot B) : à PART du tirage
        // générique ci-dessus — c'est le premier piège à PLUSIEURS cases, sa
        // zone doit être validée (axe, bornes de la salle, plancher de cases
        // jouables) avant d'être posée, ce qu'une case tirée au hasard dans
        // `placerPieges()` ne sait pas faire. Reçoit `$pieges` déjà posés pour
        // ne jamais chevaucher une case qu'ils occupent déjà.
        $pieges = [...$pieges, ...$this->placerLameBalanciere($cases, $salles, $portes, $leviers, $pieges, $suivant, $bestiaire)];

        // PIÈGES MAGIQUES DE WIZARDS OF MORCAR (lot B, 2026-10-06) : même
        // raison que la Lame balançoire juste au-dessus — poses DÉDIÉES,
        // exclues du tirage générique de `placerPieges()`. Reçoit `$pieges`
        // déjà posés (Lame balançoire comprise) pour ne jamais chevaucher.
        $pieges = [...$pieges, ...$this->placerPiegesMorcar($cases, $salles, $portes, $leviers, $pieges, $milieuxCouloirs, $suivant, $bestiaire)];

        // ÉLÉMENT-OBJECTIF de la quête FINALE (René, 2026-10-09) : sous le thème
        // d'une boîte qui en déclare un (`MoteurMobilier::ELEMENT_OBJECTIF_FINAL`,
        // le Haut Autel de Wizards of Morcar), la quête qui se joue sur la
        // rencontre finale pose cet élément à COUP SÛR dans la salle du boss.
        $nomElementObjectif = null;
        if ((string) data_get($structure, 'objectif') === 'vaincre_boss_final') {
            foreach (MoteurMobilier::ELEMENT_OBJECTIF_FINAL as $boite => $nom) {
                if ($bestiaire?->contient($boite)) {
                    $nomElementObjectif = $nom;
                    break;
                }
            }
        }

        $mobilier = $this->placerMobilier($cases, $salles, $portes, $leviers, $pieges, $suivant, $sallesCoffreAGarantir, $bestiaire, $nomElementObjectif);

        // ⚠ APRÈS les pièges ET le mobilier, et ce n'est pas un détail d'ordre :
        // une épreuve doit savoir quelles salles contiennent un piège (l'Autel
        // fêlé ne se pose que là), et ne doit pas atterrir sous un meuble, où
        // elle serait invisible et hors d'atteinte.
        $epreuves = $this->placerEpreuves($structure, $cases, $salles, $portes, $leviers, $pieges, $mobilier, $suivant);

        // TERRAIN (doc 18 §4, phase 4a) : cinquième couche, posée APRÈS les
        // épreuves pour la même raison qu'elles sont posées après le mobilier
        // — ne jamais atterrir sous une couche déjà posée, où elle serait soit
        // invisible, soit contradictoire (quel effet gagne ?).
        $terrain = $this->placerTerrains($structure, $cases, $salles, $portes, $leviers, $pieges, $mobilier, $epreuves, $suivant, $bestiaire);

        // ESCALIER D'ENTRÉE (chantier escalier-entrée, 2026-10-05, René : « il
        // faudrait ajouter un escalier ou une porte d'entrée pour chaque
        // quête »). Contrairement aux cinq couches ci-dessus, il vit TOUJOURS
        // dans la salle 0 (départ) — où rien d'autre n'est jamais posé — et ne
        // dépend donc d'aucune d'elles ; sa position est purement géométrique
        // (aucun tirage PRNG consommé).
        $escalier = $this->placerEscalier($cases, $salles[0], $portes);

        return [
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'cases' => $cases,
            'salles' => $salles,
            'portes' => $portes,
            // Liste des arêtes de l'arbre (doc « fog » à venir) : quelle porte
            // relie quelles deux salles — clé additive, ne casse aucun
            // consommateur existant de `salles`/`portes`.
            'aretes' => $aretesSortie,
            // ⚠ Remonté jusqu'à `DemarreurQuete`, qui tient le compteur de
            // pitié du groupe : il ne pourrait PAS le déduire des portes,
            // puisqu'une liaison supplémentaire est `secrete` elle aussi sans
            // rien cacher (elle n'ouvre qu'un raccourci).
            'passage_secret' => $passageSecret,
            // Leviers d'ouverture (doc 14 §3.3, procédural depuis 2026-09-06) :
            // éléments {x, y, levier_id} posés au contact desquels l'action
            // « Actionner le levier » ouvre la porte liée (verrou.levier_id).
            // Sixième couche, même patron que pièges/mobilier/épreuves/terrain —
            // le gabarit dit COMBIEN (structure.leviers.min/max), l'assembleur
            // CHOISIT la porte à verrouiller et la case du levier. Voir
            // placerLeviers() : c'était jusqu'ici la seule couche qui exigeait
            // des coordonnées qu'aucun gabarit ne pouvait connaître à l'avance,
            // si bien qu'aucun levier n'avait jamais été posé en partie réelle.
            'leviers' => $leviers,
            'pieges' => $pieges,
            // Mobilier (doc 17) : troisième couche superposée à la grille, même
            // patron que leviers/pieges ci-dessus — AUCUNE case 'm'/'s' ne change,
            // seul FabriqueGrille lit cette liste pour occuper (bloque_mouvement)
            // et/ou occulter (bloque_vue) les cases correspondantes — deux
            // propriétés INDÉPENDANTES du catalogue (source unique, cf.
            // FabriqueGrille::pour()).
            'mobilier' => $mobilier,
            // ÉPREUVES (2026-08-24) : quatrième couche, même patron que les
            // trois précédentes. Ce sont les ancrages auxquels un héros tente un
            // JET D'ATTRIBUT — la seule façon pour le moteur d'émettre des jets
            // de contexte `savoir` et `social_peur`, qui n'avaient plus aucun
            // producteur depuis la suppression de `MenuChoix` (2026-08-18) et
            // laissaient six talents de la grille sans le moindre déclencheur.
            'epreuves' => $epreuves,
            // TERRAIN (doc 18 §4, The Frozen Horror) : cinquième couche, même
            // patron que les quatre précédentes — AUCUNE case 'm'/'s' ne
            // change, seul FabriqueGrille lit cette liste pour occuper
            // (bloque_mouvement) / occulter (bloque_vue) / coûter
            // (cout_deplacement, PAS encore consommé par la BFS — voir
            // Grille::definirCoutsDeplacement()). Une entrée ne porte PAS son
            // index de salle (`{x, y, terrain_id}`, comme les leviers) : une
            // case de glace en COULOIR n'a pas d'index à porter, le brouillard
            // dérive la visibilité des coordonnées, pas d'un index.
            'terrain' => $terrain,
            // ESCALIER D'ENTRÉE (2026-10-05) : le repère du plateau d'origine
            // — la quête COMMENCE et FINIT à l'escalier. `null` seulement en
            // repli défensif (salle 0 sans bloc 2×2 valide, voir
            // `placerEscalier()`) ; les lecteurs (`Carte::casesEscalier()`,
            // `MenuMoteur`, `Quete::captifLibereEtVivant()`) traitent ce cas
            // EXACTEMENT comme une carte assemblée avant ce chantier.
            'escalier' => $escalier,
            'spawn_heros' => array_slice($this->spawnsDepuisEscalier($this->spawnsHeros($cases, $salles[0], $portes), $escalier), 0, self::MAX_SPAWNS_HEROS),
            'spawn_monstres' => $this->spawnsMonstres($cases, $salles, $this->casesDuDecor(
                $salles, $portes, $mobilier, $pieges, $leviers, $epreuves, $terrain,
            ), $suivant),
        ];
    }

    /**
     * PRNG local DÉTERMINISTE amorcé par la graine (dérivée du groupe + de la
     * position de quête côté DemarreurQuete) : deux campagnes différentes —
     * ou deux quêtes — obtiennent des cartes différentes, tout en restant
     * reproductible pour une même quête, SANS toucher à la file de dés du jeu
     * (map snapshottée). Graine 0 (défaut) = tirage fixe, pour des tests
     * déterministes. Un SEUL PRNG irrigue tout l'algorithme (choix des
     * tuiles, puis construction de l'arbre) : l'ordre des appels doit rester
     * stable pour que la reproductibilité tienne.
     */
    private function creerPRNG(int $graine): \Closure
    {
        // Délègue à PrngLineaire (mêmes constantes, donc cartes inchangées) :
        // le deck de fouille a besoin du même générateur.
        $prng = new PrngLineaire($graine);

        return fn (): int => $prng->suivant();
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return list<Tuile>
     */
    private function choisirTuiles(array $structure, \Closure $suivant): array
    {
        // Nombre de salles TIRÉ dans [min, max] du gabarit (au lieu du min figé).
        $min = max(self::NB_SALLES_MIN, (int) data_get($structure, 'salles.min', 3));
        $max = max($min, (int) data_get($structure, 'salles.max', $min));
        $nbSalles = $min + ($max > $min ? $suivant() % ($max - $min + 1) : 0);

        $generiques = Tuile::query()
            ->where('type', 'salle')
            ->where('theme', 'generique')
            ->orderBy('id')
            ->get()
            ->all();

        if ($generiques === []) {
            throw new RuntimeException('Aucune tuile « salle » en base — seeder les tuiles avant d\'assembler une carte.');
        }

        // Mélange déterministe (Fisher-Yates avec le PRNG local) → l'ordre et le
        // choix des salles varient d'une quête à l'autre.
        for ($i = count($generiques) - 1; $i > 0; $i--) {
            $j = $suivant() % ($i + 1);
            [$generiques[$i], $generiques[$j]] = [$generiques[$j], $generiques[$i]];
        }

        $tuiles = [];
        for ($i = 0; $i < $nbSalles; $i++) {
            $tuiles[] = $generiques[$i % count($generiques)];
        }

        // Rencontre finale (sous-boss / boss) : la DERNIÈRE salle (posée en
        // dernier dans l'arbre → toujours une feuille, cf. construireArbre)
        // est l'antre.
        if (isset($structure['rencontre_finale'])) {
            $boss = Tuile::query()
                ->where('type', 'salle')
                ->where('theme', 'boss')
                ->orderBy('id')
                ->first();

            if ($boss !== null) {
                $tuiles[$nbSalles - 1] = $boss;
            }
        }

        return $tuiles;
    }

    /**
     * Construit l'arbre de salles sur une grille 2D infinie : la salle 0 est
     * la racine ; chaque salle i≥1 choisit (via le PRNG, parmi TOUTES les
     * combinaisons salle-déjà-posée × direction-libre encore disponibles) un
     * parent et une direction, et occupe la case de grille ainsi libérée.
     * Une salle peut ainsi recevoir jusqu'à 4 enfants (vraie branche) — et
     * comme les salles sont posées dans l'ORDRE 0..n-1, la DERNIÈRE (l'antre
     * du boss, cf. choisirTuiles) ne peut jamais devenir parent : elle reste
     * une feuille, sans qu'aucun cas particulier ne soit nécessaire.
     *
     * @return array{grille: list<array{0: int, 1: int}>, aretes: list<array{parent: int, enfant: int, direction: string}>, passage_secret: bool}
     */
    private function construireArbre(int $n, \Closure $suivant, int $chancePassageSecret = self::CHANCE_PASSAGE_SECRET): array
    {
        $directions = ['E' => [1, 0], 'W' => [-1, 0], 'S' => [0, 1], 'N' => [0, -1]];
        $positions = [0 => [0, 0]];
        $occupees = ['0,0' => 0];
        $aretes = [];

        for ($i = 1; $i < $n; $i++) {
            $candidats = [];

            for ($r = 0; $r < $i; $r++) {
                [$gx, $gy] = $positions[$r];

                foreach ($directions as $nom => [$dx, $dy]) {
                    $tx = $gx + $dx;
                    $ty = $gy + $dy;

                    if (! isset($occupees["{$tx},{$ty}"])) {
                        $candidats[] = ['parent' => $r, 'direction' => $nom, 'x' => $tx, 'y' => $ty];
                    }
                }
            }

            if ($candidats === []) {
                // Ne devrait jamais arriver (la grille est infinie et chaque
                // salle posée libère jusqu'à 3 nouvelles directions) — garde
                // défensive plutôt qu'un tableau invalide silencieux.
                throw new RuntimeException("Assemblage de carte : aucune case de grille libre pour poser la salle {$i}.");
            }

            // Placement COMPACT plutôt que purement aléatoire : on privilégie
            // les cases qui touchent le plus de salles déjà posées, puis les
            // plus proches du centre de gravité. Un arbre étalé au hasard ne se
            // replie presque jamais sur lui-même, donc n'offre aucune paire de
            // salles voisines-mais-non-reliées — et sans elles, aucune boucle ni
            // aucune porte secrète n'est possible (mesuré : 0 % à 3 salles,
            // 16 % à 4). Compacter suffit à en garantir.
            $choix = $this->candidatLePlusCompact($candidats, $positions, $occupees, $directions, $suivant);
            $positions[$i] = [$choix['x'], $choix['y']];
            $occupees["{$choix['x']},{$choix['y']}"] = $i;
            $aretes[] = ['parent' => $choix['parent'], 'enfant' => $i, 'direction' => $choix['direction']];
        }

        $supplementaires = $this->liaisonsSupplementaires($positions, $occupees, $aretes, $directions, $suivant);
        [$aretes, $supplementaires, $passageSecret] = $this->secretiserUneAreteDArbre(
            $aretes, $supplementaires, $suivant, $chancePassageSecret,
        );

        return [
            'grille' => $positions,
            'aretes' => [...$aretes, ...$supplementaires],
            // Remonté jusqu'à l'appelant : c'est lui qui tient le compteur de
            // pitié ; il pourrait le déduire des arêtes secrètes, mais le
            // booléen dit la DÉCISION plutôt que ses ingrédients.
            'passage_secret' => $passageSecret,
        ];
    }

    /**
     * Rend ORDINAIRE toute porte secrète accessible depuis une case qui donne
     * aussi sur une porte normale (règle de René).
     *
     * Deux portes d'états différents sur le même mur, c'est incompréhensible
     * pour le joueur — et c'était surtout un piège moteur : la recherche de
     * « porte close adjacente » rendait la PREMIÈRE trouvée, si bien qu'une
     * secrète pouvait masquer une porte parfaitement ouvrable et priver le
     * héros de son option « Ouvrir la porte » devant une porte qu'il voyait.
     *
     * On dévoile plutôt que de supprimer la liaison : la boucle reste, elle
     * cesse simplement d'être cachée. La connectivité n'en dépend jamais (une
     * salle n'est jamais tributaire d'une porte secrète).
     *
     * @param  list<array<string, mixed>>  $portes
     * @return list<array<string, mixed>>
     */
    private function devoilerSecretesEnConflit(array $portes): array
    {
        // Cases donnant sur au moins une porte NON secrète.
        $casesNormales = [];
        foreach ($portes as $porte) {
            if (($porte['etat'] ?? '') === MoteurPortes::ETAT_SECRETE) {
                continue;
            }
            foreach (Grille::casesPorte($porte) as $case) {
                $casesNormales[$case['x'].','.$case['y']] = true;
            }
        }

        foreach ($portes as $i => $porte) {
            if (($porte['etat'] ?? '') !== MoteurPortes::ETAT_SECRETE) {
                continue;
            }

            foreach (Grille::casesPorte($porte) as $case) {
                if (isset($casesNormales[$case['x'].','.$case['y']])) {
                    $portes[$i]['etat'] = MoteurPortes::ETAT_FERMEE;
                    unset($portes[$i]['revele']);
                    break;
                }
            }
        }

        return $portes;
    }

    /**
     * PORTE DE PIERRE (Against the Ogre Horde, livret F9528 p. 4, lot B) :
     * « Stone doorways are large slabs of rock that must be pushed out of the
     * way using brute force […]. To open one of these doors, a hero rolls
     * their base Attack dice. If the roll result includes two skulls, the
     * heavy stone door swings open. Once […] opened, it remains open for the
     * remainder of the quest. […] the wizard rolls 1 Attack die, and
     * therefore cannot open a stone doorway. »
     *
     * Un ÉTAT DE PORTE de plus, pas une couche neuve : `verrou.type = 'pierre'`
     * sur une entrée `portes[]` ordinaire (même modèle que `cle`/`levier`/
     * `monstres_vaincus`), lu par `MoteurPortes` et résolu par
     * `ResolveurTour::resoudreForcerPortePierre()`. « Reste ouverte jusqu'à la
     * fin de la quête » retombe GRATUITEMENT sur l'état persistant existant
     * (`MoteurPortes::ouvrir()`), comme toute autre porte.
     *
     * ⚠ **Placement — jamais le seul chemin vers l'objectif** (brief lot B,
     * `docs/regles/carte-donjon.md` §2.12 ter « connected is not playable »,
     * étendu ici à « forçable »). Un héros dont la base est sous 2 dés (le
     * magicien, 1 dé) ne peut JAMAIS forcer une porte de pierre : si on en
     * posait une sur une arête de l'ARBRE COUVRANT, un groupe réduit à ce
     * seul magicien resterait bloqué à jamais devant l'unique chemin. La
     * règle retenue l'exclut PAR CONSTRUCTION plutôt que par un calcul de
     * connexité a posteriori : une porte de pierre ne se pose QUE sur une
     * arête de BOUCLE (`liaisonsSupplementaires()`, drapeau `boucle` propagé
     * jusqu'à `portes[]` dans `assembler()`) — par définition, les deux
     * salles qu'elle relie sont DÉJÀ connectées par l'arbre couvrant, donc la
     * bloquer ne peut jamais couper la seule route. Au plus UNE porte de
     * pierre par carte (fréquence non sourcée par le livret — décision de
     * portage, volontairement conservatrice).
     *
     * @param  list<array<string, mixed>>  $portes
     * @return list<array<string, mixed>>
     */
    private function marquerPortesDePierre(array $portes, \Closure $suivant, ?BestiaireGroupe $bestiaire): array
    {
        $prng = new PrngLineaire($suivant());

        // Boîte : composant de la boîte Against the Ogre Horde, jamais posé
        // hors de ce thème (même lecture que `Terrain::boite` ci-dessus).
        if (! ($bestiaire?->contient('horde_ogre') ?? false)) {
            return $portes;
        }

        // Candidates : toutes les portes d'une arête de BOUCLE, ordinaires
        // (fermées, sans verrou déjà posé) — jamais une secrète (les boucles
        // ne le sont jamais, voir `liaisonsSupplementaires()`), groupées par
        // jonction.
        //
        // ⚠ UN SEUL bout du passage devient de pierre (René, 2026-10-03 : « On
        // va garder 1 porte en pierre seulement et non les deux »). On
        // marquait les deux portes de la jonction : il fallait pousser deux
        // dalles pour un même passage, là où le livret ne parle que d'UNE
        // porte.
        $jonctions = [];
        foreach ($portes as $idx => $porte) {
            if (empty($porte['boucle'])
                || ($porte['etat'] ?? MoteurPortes::ETAT_OUVERTE) !== MoteurPortes::ETAT_FERMEE
                || ($porte['verrou']['type'] ?? null) !== null) {
                continue;
            }
            $jonctions[(int) ($porte['jonction'] ?? -1)][] = $idx;
        }

        if ($jonctions === []) {
            return $portes; // aucune boucle disponible sur cette carte : aucune porte de pierre
        }

        $cles = array_keys($jonctions);
        $choisie = $cles[$prng->suivant() % count($cles)];

        $bouts = $jonctions[$choisie];
        $idx = $bouts[$prng->suivant() % count($bouts)];
        $portes[$idx]['verrou'] = ['type' => MoteurPortes::VERROU_PIERRE];

        return $portes;
    }

    /**
     * Accole les salles-FEUILLES à leur parente PAR DÉFAUT (René, 2026-09-11 :
     * « tu mets toujours une case pour relier 2 salles, mais dans le jeu
     * original il n'y en a pas ») : leurs murs coïncident, et le perçage
     * produit alors un couloir de longueur 1 — c'est-à-dire un simple SEUIL,
     * une porte directe d'une salle à l'autre. Les vrais couloirs
     * SUBSISTENT : pour les salles non-feuilles (voir plus bas) et pour toute
     * feuille que `chevaucheUneSalle()` refuse — le boyau court et artificiel
     * disparaît, la notion de couloir reste pour les salles réellement
     * éloignées.
     *
     * ⚠ Avant cette date, l'accolement n'était tenté qu'une feuille sur deux
     * (tirage `$suivant() % 2`) : une surprise plutôt qu'une norme. Le tirage
     * est retiré — CHAQUE feuille éligible est désormais accolée, et seuls
     * `chevaucheUneSalle()` et les deux gardes ci-dessous y renoncent encore.
     * Le PRNG perd donc un cran de consommation ici ; c'est voulu (l'algorithme
     * change de comportement), pas une régression de reproductibilité — une
     * même graine reste déterministe, juste sur une séquence différente.
     *
     * **Pourquoi seulement des feuilles.** Décaler une salle le long de l'axe
     * de son arête déplace sa médiane perpendiculaire. Si elle avait d'autres
     * arêtes, leurs couloirs cesseraient d'être droits — toute la géométrie des
     * slots uniformes repose sur l'alignement des médianes. Une feuille n'a
     * qu'une arête : la déplacer ne casse rien. GARDE-FOU CONSERVÉ : lever
     * cette restriction demanderait de redresser TOUTES les arêtes d'une
     * salle repositionnée, pas seulement celle qu'on accole — hors périmètre
     * de cette correction.
     *
     * **Pourquoi jamais la salle 0.** GARDE-FOU CONSERVÉ, tel quel : elle
     * accueille tout le groupe et sert de repère — le déplacement d'une
     * scène déjà fixée dans l'esprit des joueurs n'apporte rien, et rien
     * n'exige de la coller à sa voisine.
     *
     * **Pourquoi les arêtes SECRÈTES sont désormais ACCOLÉES aussi**
     * (garde-fou LEVÉ, décision explicite). L'ancienne exclusion datait d'avant
     * le correctif du 2026-09-10 (« et avant d'être trouvé, c'est un MUR — pas
     * un trou », `EtatGroupe::portes()` déguise toute porte secrète non
     * révélée en case de roche). Une porte secrète mitoyenne n'a donc plus
     * besoin d'un couloir pour se cacher : le seuil mitoyen, tant qu'il n'est
     * pas trouvé, se peint exactement comme le mur qui l'entoure — c'est même
     * *plus* fidèle au plateau, où un passage dérobé est typiquement un pan de
     * mur entre deux pièces voisines, pas la porte d'un cul-de-sac au bout
     * d'un couloir. Rien ne change côté connectivité : `secretiserUneAreteDArbre()`
     * a déjà retiré toute boucle qui desservirait la salle cachée AVANT que
     * cette méthode ne s'exécute.
     *
     * Le décalage est ANNULÉ s'il ferait chevaucher une autre salle (GARDE-FOU
     * CONSERVÉ, `chevaucheUneSalle()`) : mieux vaut un couloir de plus qu'un
     * donjon malformé — on RENONCE, jamais on ne force.
     *
     * @param  list<array{parent: int, enfant: int, direction: string, secrete?: bool}>  $aretes
     * @param  array<int, array{x: int, y: int, largeur: int, hauteur: int}>  $poses
     * @return list<array{parent: int, enfant: int, direction: string, secrete?: bool, mitoyenne?: bool}>
     */
    private function accolerSallesMitoyennes(array $aretes, array &$poses): array
    {
        // Degré de chaque salle, liaisons supplémentaires comprises.
        $degre = [];
        foreach ($aretes as $a) {
            $degre[$a['parent']] = ($degre[$a['parent']] ?? 0) + 1;
            $degre[$a['enfant']] = ($degre[$a['enfant']] ?? 0) + 1;
        }

        foreach ($aretes as $k => $arete) {
            $enfant = $arete['enfant'];

            // Feuille uniquement, et jamais la salle de départ (elle accueille
            // tout le groupe et sert de repère) — les deux SEULS gardes tenus
            // en amont de la géométrie ; voir le docblock pour le sort de
            // l'exclusion des arêtes secrètes (levée) et du tirage (retiré).
            if ($enfant === 0 || ($degre[$enfant] ?? 0) !== 1) {
                continue;
            }

            $p = $poses[$arete['parent']];
            $e = $poses[$enfant];
            $vise = $e;

            // Mur de l'enfant collé au mur de la parente (colonne/ligne partagée).
            switch ($arete['direction']) {
                case 'E': $vise['x'] = $p['x'] + $p['largeur'] - 1;
                    break;
                case 'W': $vise['x'] = $p['x'] - $e['largeur'] + 1;
                    break;
                case 'S': $vise['y'] = $p['y'] + $p['hauteur'] - 1;
                    break;
                case 'N': $vise['y'] = $p['y'] - $e['hauteur'] + 1;
                    break;
                default: continue 2;
            }

            if ($this->chevaucheUneSalle($vise, $poses, $enfant)) {
                continue;
            }

            $poses[$enfant] = $vise;
            $aretes[$k]['mitoyenne'] = true;
        }

        return $aretes;
    }

    /**
     * La salle visée empiéterait-elle sur une autre (marge d'une case) ?
     *
     * @param  array{x: int, y: int, largeur: int, hauteur: int}  $vise
     * @param  array<int, array{x: int, y: int, largeur: int, hauteur: int}>  $poses
     */
    private function chevaucheUneSalle(array $vise, array $poses, int $sauf): bool
    {
        foreach ($poses as $i => $autre) {
            if ($i === $sauf) {
                continue;
            }

            // Les murs mitoyens se SUPERPOSENT d'une case : on tolère donc un
            // recouvrement d'exactement une colonne/ligne, mais pas davantage.
            $chevauchementX = min($vise['x'] + $vise['largeur'], $autre['x'] + $autre['largeur'])
                - max($vise['x'], $autre['x']);
            $chevauchementY = min($vise['y'] + $vise['hauteur'], $autre['y'] + $autre['hauteur'])
                - max($vise['y'], $autre['y']);

            if ($chevauchementX > 1 && $chevauchementY > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case la plus « compacte » parmi les candidates : d'abord celle qui touche
     * le plus de salles déjà posées (chaque contact en trop est une boucle
     * potentielle), à égalité la plus proche du centre de gravité.
     *
     * @param  list<array{parent: int, direction: string, x: int, y: int}>  $candidats
     * @param  array<int, array{0: int, 1: int}>  $positions
     * @param  array<string, int>  $occupees
     * @param  array<string, array{0: int, 1: int}>  $directions
     * @return array{parent: int, direction: string, x: int, y: int}
     */
    private function candidatLePlusCompact(
        array $candidats,
        array $positions,
        array $occupees,
        array $directions,
        \Closure $suivant,
    ): array {
        $cx = array_sum(array_column($positions, 0)) / count($positions);
        $cy = array_sum(array_column($positions, 1)) / count($positions);

        $meilleur = null;
        $meilleurScore = null;

        foreach ($candidats as $c) {
            $voisins = 0;
            foreach ($directions as [$dx, $dy]) {
                if (isset($occupees[($c['x'] + $dx).','.($c['y'] + $dy)])) {
                    $voisins++;
                }
            }

            // Contacts d'abord (×100 : ils priment sur la distance), puis
            // proximité du centre. Le PRNG départage à score strictement égal,
            // pour que deux quêtes ne produisent pas la même forme.
            $score = $voisins * 100 - (abs($c['x'] - $cx) + abs($c['y'] - $cy)) + ($suivant() % 3) / 10;

            if ($meilleurScore === null || $score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleur = $c;
            }
        }

        return $meilleur ?? $candidats[0];
    }

    /**
     * Rend UNE OU DEUX salles réellement tributaires d'une porte secrète
     * (René, 2026-08-24 ; deux depuis le 2026-09-27).
     *
     * Jusqu'ici les portes secrètes ne vivaient que sur les liaisons
     * SUPPLÉMENTAIRES — des boucles ajoutées par-dessus l'arbre couvrant —, si
     * bien que toute salle restait atteignable sans en trouver aucune. Découvrir
     * un passage n'achetait qu'un raccourci ; explorer n'était jamais un enjeu.
     * `CouloirsTest` constatait cette propriété (« ne rend JAMAIS une salle
     * tributaire d'une porte secrète »), et sa justification d'époque était
     * juste : « une porte secrète bloquerait un groupe qui rate son jet ».
     *
     * ⚠ Deux faits l'ont périmée, et tous deux sont POSTÉRIEURS à la règle :
     *  - « Fouiller la zone » est offerte à CHAQUE TOUR, sans limite ni par
     *    salle ni par héros (contrairement à « Fouiller — trésor ») : un jet
     *    raté ne fige personne, il coûte un tour ;
     *  - `battre_en_retraite` (2026-08-21) n'a AUCUNE condition : un groupe qui
     *    renonce peut toujours sortir.
     *
     * Une quête peut donc désormais se perdre faute d'avoir cherché — choix
     * assumé de René, salle-objectif et coffre d'artefact compris.
     *
     * ⚠ Trois garde-fous, et chacun a sa raison :
     *  - **au plus deux** arêtes d'arbre secrètes (René, 2026-09-27 : « de 0 à
     *    2 passages secrets par quête […] un aléatoire entre 1 et 2 », puis
     *    « je ne tiens pas à ce que le 2e passage soit une boucle ») — c'était
     *    UNE jusque-là. Jamais en chaîne : ce sont des FEUILLES (garde-fou
     *    suivant), qui n'ont pas d'enfant, donc aucune salle cachée n'est
     *    jamais derrière une autre ;
     *  - **jamais une arête sortant de la salle 0** : la partie commencerait par
     *    une fouille, avant d'avoir rien montré ;
     *  - **une FEUILLE de l'arbre seulement** : on cache une salle, pas une
     *    moitié de donjon.
     *
     * ⚠ La méthode reçoit les liaisons supplémentaires et **retire celles qui
     * desserviraient la salle cachée**. Sans ça, la fonctionnalité ne se
     * déclenchait presque jamais : le placement compact crée beaucoup
     * d'adjacences, `liaisonsSupplementaires()` en relie une bonne part, et la
     * feuille se retrouvait desservie par une boucle — donc pas cachée du tout.
     * Mesuré avant correction : **5 donjons sur 40**. On sacrifie donc
     * les boucles qui la desservent, pas davantage.
     *
     * ⚠ **UN DONJON SUR DEUX**, et c'est un dosage, pas un hasard subi (René,
     * 2026-08-27). Sans tirage, une feuille éligible existant toujours, la
     * salle cachée tombait dans **40 donjons sur 40** : « il y a toujours un
     * passage caché » devenait une règle que les joueurs auraient apprise, et
     * le ratissage systématique avec. Une fois sur deux, la découverte reste
     * une surprise — et « Fouiller la zone » garde de toute façon les pièges à
     * révéler dans l'autre moitié.
     *
     * ⚠ La chance n'est pas fixe : elle vient du COMPTEUR DE PITIÉ du groupe
     * (`groupes.chance_passage_secret`), qui monte de 10 points par carte sans
     * passage et retombe à 50 dès qu'on en pose un. Un tirage à 50 % pur peut
     * laisser une campagne entière sans le moindre passage, et une telle série
     * ne se lit pas comme du hasard : le groupe conclut que la fonctionnalité
     * n'existe pas et cesse de fouiller.
     *
     * @param  list<array{parent: int, enfant: int, direction: string, secrete?: bool}>  $aretes  arbre couvrant
     * @param  list<array{parent: int, enfant: int, direction: string, secrete?: bool}>  $supplementaires  boucles
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: bool}
     */
    private function secretiserUneAreteDArbre(
        array $aretes,
        array $supplementaires,
        \Closure $suivant,
        int $chance = self::CHANCE_PASSAGE_SECRET,
    ): array {
        // Degré dans l'ARBRE seul : une feuille est une salle en bout de branche.
        $degre = [];
        foreach ($aretes as $a) {
            $degre[$a['parent']] = ($degre[$a['parent']] ?? 0) + 1;
            $degre[$a['enfant']] = ($degre[$a['enfant']] ?? 0) + 1;
        }

        $eligibles = [];
        foreach ($aretes as $k => $arete) {
            if ($arete['parent'] === 0) {
                continue;
            }

            if (($degre[$arete['enfant']] ?? 0) === 1) {
                $eligibles[] = $k;
            }
        }

        if ($eligibles === []) {
            // Donjon trop linéaire : on ne force rien. ⚠ Et on rend `false`,
            // donc le compteur de pitié MONTE — c'est bien une carte sans
            // passage, quelle qu'en soit la raison.
            return [$aretes, $supplementaires, false];
        }

        // ⚠ Le tirage passe AVANT le choix de l'arête, et consomme un cran du
        // PRNG dans les deux cas : le tirer seulement quand on secrétise ferait
        // diverger la suite des nombres selon la branche, et deux donjons de
        // même graine cesseraient d'être identiques.
        if ($suivant() % 100 >= max(0, min(100, $chance))) {
            return [$aretes, $supplementaires, false];
        }

        // UN ou DEUX passages (René, 2026-09-27), sur des feuilles DISTINCTES —
        // un seul quand le donjon n'en offre qu'une.
        $nombre = 1 + $suivant() % 2;
        $choisies = array_slice((new PrngLineaire($suivant()))->melanger($eligibles), 0, $nombre);

        $cachees = [];
        foreach ($choisies as $choisie) {
            $aretes[$choisie]['secrete'] = true;
            $cachees[] = $aretes[$choisie]['enfant'];
        }

        // Chaque salle doit rester SEULE derrière son passage : toute boucle
        // qui la rejoindrait annulerait le secret sans que rien ne le signale.
        $supplementaires = array_values(array_filter(
            $supplementaires,
            fn (array $l) => ! in_array($l['parent'], $cachees, true) && ! in_array($l['enfant'], $cachees, true),
        ));

        return [$aretes, $supplementaires, true];
    }

    /**
     * Liaisons SUPPLÉMENTAIRES entre deux salles déjà voisines sur la grille
     * mais non reliées par l'arbre — elles créent des BOUCLES.
     *
     * Un arbre pur n'a qu'un chemin entre deux salles : le donjon se parcourt
     * en aller-retour, et surtout les **portes secrètes n'avaient rien à
     * ouvrir**. « Fouiller la zone » révèle les portes secrètes ; sans liaison
     * cachée à trouver, l'action ne servait qu'aux pièges.
     *
     * Elles sont toutes ORDINAIRES (René, 2026-09-27) : une boucle sur deux
     * était secrète, la première toujours — d'où « au moins une porte secrète
     * par carte », jamais zéro. Les passages secrets ne mènent plus qu'à des
     * salles cachées (`secretiserUneAreteDArbre()`, 0 à 2 par quête).
     *
     * @param  array<int, array{0: int, 1: int}>  $positions
     * @param  array<string, int>  $occupees
     * @param  list<array{parent: int, enfant: int, direction: string}>  $aretes
     * @param  array<string, array{0: int, 1: int}>  $directions
     * @return list<array{parent: int, enfant: int, direction: string, secrete?: bool}>
     */
    private function liaisonsSupplementaires(
        array $positions,
        array $occupees,
        array $aretes,
        array $directions,
        \Closure $suivant,
    ): array {
        // Paires déjà reliées par l'arbre (ordre normalisé).
        $reliees = [];
        foreach ($aretes as $a) {
            $reliees[min($a['parent'], $a['enfant']).':'.max($a['parent'], $a['enfant'])] = true;
        }

        // Candidates : salles orthogonalement voisines sur la grille de slots,
        // sans liaison. On ne garde que E et S pour ne compter chaque paire
        // qu'une fois.
        $candidates = [];
        foreach ($positions as $i => [$gx, $gy]) {
            foreach (['E' => $directions['E'], 'S' => $directions['S']] as $nom => [$dx, $dy]) {
                $voisin = $occupees[($gx + $dx).','.($gy + $dy)] ?? null;
                if ($voisin === null) {
                    continue;
                }
                $cle = min($i, $voisin).':'.max($i, $voisin);
                if (isset($reliees[$cle])) {
                    continue;
                }
                $reliees[$cle] = true;
                // `boucle: true` — ce drapeau suit l'arête jusque dans
                // `portes[]` (voir `assembler()`) : c'est lui qui permet à
                // `marquerPortesDePierre()` de ne JAMAIS poser une porte de
                // pierre sur une arête de l'ARBRE COUVRANT. Une liaison
                // supplémentaire relie deux salles déjà connectées PAR
                // l'arbre — la bloquer ne peut donc jamais couper le seul
                // chemin vers l'objectif, quel que soit le groupe (même un
                // magicien seul, qui ne peut jamais forcer une porte de
                // pierre). C'est la garantie « never the only way forward »
                // obtenue PAR CONSTRUCTION plutôt que par un calcul de
                // connexité a posteriori.
                $candidates[] = ['parent' => $i, 'enfant' => $voisin, 'direction' => $nom, 'boucle' => true];
            }
        }

        if ($candidates === []) {
            return [];
        }

        // Au plus une liaison pour deux salles, et jamais zéro quand il en
        // existe : assez pour ouvrir une boucle, trop peu pour transformer le
        // donjon en grille ouverte où l'exploration n'a plus d'enjeu.
        $quota = max(1, min(count($candidates), intdiv(count($positions), 2)));

        $candidates = (new PrngLineaire($suivant()))->melanger($candidates);
        $retenues = array_slice($candidates, 0, $quota);

        return array_values($retenues);
    }

    /**
     * Creuse le couloir (1 ou 2 voies) et perce UNE porte de chaque côté pour
     * une arête (parent, enfant) de l'arbre. Les deux salles étant centrées dans
     * des slots uniformes, leur ligne (E/W) ou colonne (N/S) médiane de slot
     * coïncide : c'est elle qui porte les deux portes et la voie « rapide » du
     * couloir ; la voie parallèle (juste avant) reste un cul-de-sac SANS
     * porte contre le mur de chaque salle — jamais deux portes adjacentes.
     *
     * ⚠ **Arête MITOYENNE (René, 2026-09-11) : UNE SEULE porte, pas deux.**
     * `accolerSallesMitoyennes()` a rapproché les deux salles jusqu'à faire
     * coïncider leur mur commun ; géométriquement `xPorteGauche === xPorteDroite`
     * (resp. `yPorteHaut === yPorteBas`) — la « voie rapide » se réduit à UNE
     * case, l'ancienne case de mur des deux salles à la fois, et la voie
     * parallèle est vide. Percer $porteGauche ET $porteDroite comme au cas
     * général posait alors DEUX arêtes-portes encadrant cette unique case
     * (sortie de la salle gauche, puis entrée de la salle droite un cran plus
     * loin) — deux battants sur un seuil qui n'en montre qu'un au plateau.
     * On ne garde donc que `porte_parent` (celle qui porte historiquement la
     * restriction de `$spec` — verrou/secrète) ; `porte_enfant` devient `null`,
     * et l'appelant (`assembler()`) ne la pousse pas dans `portes[]`. L'arête
     * qui aurait séparé cette case de l'intérieur du parent (ex-`porteDroite`)
     * n'est simplement JAMAIS enregistrée : deux cases de sol sans entrée dans
     * `portes[]` sont déjà, par construction, franchissables sans restriction
     * (`Grille::porteBloqueEntre()`) — inutile de la poser « ouverte », l'absence
     * suffit.
     *
     * ⚠ **`$voieUnique` (René, 2026-09-27) : la voie parallèle n'est pas
     * creusée.** Le couloir se réduit à la voie rapide, celle qui porte les
     * portes et le `milieu` où tombe un piège : plus de contournement, il faut
     * le sauter, le désamorcer ou le subir.
     *
     * @param  list<list<string>>  $cases
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int, theme: string}>  $salles
     * @param  list<array{0: int, 1: int}>  $positionsGrille
     * @param  array{parent: int, enfant: int, direction: string, mitoyenne?: bool}  $arete
     * @param  array{etat: string, verrou?: array<string, mixed>}|null  $spec
     * @return array{porte_parent: array<string, mixed>, porte_enfant: ?array<string, mixed>, milieu: array{x: int, y: int}}
     */
    private function creuserArete(
        array &$cases,
        array $salles,
        array $positionsGrille,
        int $slotLargeur,
        int $slotHauteur,
        array $arete,
        ?array $spec,
        bool $voieUnique = false,
    ): array {
        $parent = $arete['parent'];
        $enfant = $arete['enfant'];
        $direction = $arete['direction'];
        $mitoyenne = ! empty($arete['mitoyenne']);
        // Un SEUIL FAIT UNE CASE (décision de René, 2026-08-08) : plus de
        // seconde paire de portes. Conservé vide pour ne pas changer le contrat
        // de retour de creuserArete(), lu par l'appelant.
        $secondaires = [];

        if ($direction === 'E' || $direction === 'W') {
            [$gauche, $droite] = $direction === 'E' ? [$parent, $enfant] : [$enfant, $parent];
            $salleGauche = $salles[$gauche];
            $salleDroite = $salles[$droite];

            // Ligne médiane du SLOT (commune : même gy des deux côtés).
            [, $gy] = $positionsGrille[$gauche];
            $r = $gy * $slotHauteur + intdiv($slotHauteur, 2);

            $xPorteGauche = $salleGauche['x'] + $salleGauche['largeur'] - 1;
            $xPorteDroite = $salleDroite['x'];

            // Voie rapide (r) creusée sur toute la longueur du couloir.
            for ($cx = $xPorteGauche; $cx <= $xPorteDroite; $cx++) {
                $cases[$r][$cx] = 's';
            }
            // Voie parallèle (r-1) : elle élargit le COULOIR, jamais le seuil —
            // elle s'arrête donc avant les murs des salles (cf. SEUIL_UNE_CASE).
            for ($cx = $xPorteGauche + 1; ! $voieUnique && $cx <= $xPorteDroite - 1; $cx++) {
                $cases[$r - 1][$cx] = 's';
            }

            // Portes = arêtes (aucune case 'p') : sortie EST de la salle gauche
            // (arête xPorteGauche|xPorteGauche+1) et entrée EST de la salle droite
            // (arête xPorteDroite-1|xPorteDroite). Les cases restent du sol.
            $porteGauche = $this->construirePorte($xPorteGauche, $r, 'e', $gauche === $parent ? $spec : null);
            $porteDroite = $this->construirePorte($xPorteDroite - 1, $r, 'e', $droite === $parent ? $spec : null);

            $porteParent = $gauche === $parent ? $porteGauche : $porteDroite;
            $porteEnfant = $mitoyenne ? null : ($gauche === $parent ? $porteDroite : $porteGauche);

            $milieu = ['x' => $xPorteGauche + intdiv(self::LONGUEUR_COULOIR, 2) + 1, 'y' => $r];
        } else {
            [$haut, $bas] = $direction === 'S' ? [$parent, $enfant] : [$enfant, $parent];
            $salleHaut = $salles[$haut];
            $salleBas = $salles[$bas];

            // Colonne médiane du SLOT (commune : même gx des deux côtés).
            [$gx] = $positionsGrille[$haut];
            $c = $gx * $slotLargeur + intdiv($slotLargeur, 2);

            $yPorteHaut = $salleHaut['y'] + $salleHaut['hauteur'] - 1;
            $yPorteBas = $salleBas['y'];

            for ($cy = $yPorteHaut; $cy <= $yPorteBas; $cy++) {
                $cases[$cy][$c] = 's';
            }
            // Voie parallèle : élargit le COULOIR, jamais le seuil.
            for ($cy = $yPorteHaut + 1; ! $voieUnique && $cy <= $yPorteBas - 1; $cy++) {
                $cases[$cy][$c - 1] = 's';
            }

            // Portes = arêtes SUD : sortie de la salle haute (arête yPorteHaut|+1)
            // et entrée de la salle basse (arête yPorteBas-1|yPorteBas).
            $porteHaut = $this->construirePorte($c, $yPorteHaut, 's', $haut === $parent ? $spec : null);
            $porteBas = $this->construirePorte($c, $yPorteBas - 1, 's', $bas === $parent ? $spec : null);

            $porteParent = $haut === $parent ? $porteHaut : $porteBas;
            $porteEnfant = $mitoyenne ? null : ($haut === $parent ? $porteBas : $porteHaut);

            $milieu = ['x' => $c, 'y' => $yPorteHaut + intdiv(self::LONGUEUR_COULOIR, 2) + 1];
        }

        return [
            'porte_parent' => $porteParent,
            'porte_enfant' => $porteEnfant,
            'portes_secondaires' => $secondaires,
            'milieu' => $milieu,
        ];
    }

    /**
     * Construit l'entrée de porte à (x, y), à l'état `fermee` par défaut, ou
     * selon la spec du gabarit (verrouillee / secrete — doc 14 §3.3) quand
     * elle s'applique à ce côté de l'arête (comportement conservé de l'ancien
     * algorithme : c'est la porte côté salle PARENT — celle qui quitte la
     * salle déjà explorée vers la suivante — qui porte la restriction).
     *
     * @param  array{etat: string, verrou?: array<string, mixed>}|null  $spec
     * @return array{x: int, y: int, etat: string, verrou?: array<string, mixed>, revele?: bool}
     */
    private function construirePorte(int $x, int $y, string $cote, ?array $spec): array
    {
        // Porte = ARÊTE (ne prend pas de case) : elle sépare la case (x,y) de sa
        // voisine EST (cote 'e') ou SUD (cote 's'), activable des deux côtés.
        $porte = ['x' => $x, 'y' => $y, 'cote' => $cote, 'etat' => MoteurPortes::ETAT_FERMEE];

        if ($spec !== null) {
            $porte['etat'] = (string) $spec['etat'];
            if (isset($spec['verrou'])) {
                $porte['verrou'] = $spec['verrou'];
            }
            if ($porte['etat'] === 'secrete') {
                $porte['revele'] = false;
            }
        }

        return $porte;
    }

    /**
     * Spécification de porte spéciale pour l'arête n°$index (doc 14 §3.3) :
     * première entrée de structure.portes ciblant cette arête avec un `etat`
     * (clé `couloir`, conservée telle quelle — une entrée par arête, dans
     * l'ordre où les salles 1..n-1 sont posées).
     *
     * @param  list<array{couloir?: int, etat?: string, verrou?: array<string, mixed>}>  $specs
     * @return array{etat: string, verrou?: array<string, mixed>}|null
     */
    private function specPorte(array $specs, int $index): ?array
    {
        foreach ($specs as $spec) {
            if ((int) ($spec['couloir'] ?? -1) === $index && isset($spec['etat'])) {
                /** @var array{etat: string, verrou?: array<string, mixed>} $spec */
                return $spec;
            }
        }

        return null;
    }

    /**
     * Leviers PROCÉDURAUX (2026-09-06) — sixième couche, même patron que
     * `placerPieges()`/`placerMobilier()`/`placerEpreuves()`/`placerTerrains()` :
     * le gabarit dit COMBIEN (`structure.leviers.min/max`), l'assembleur
     * CHOISIT la porte à verrouiller et la case du levier.
     *
     * `placerLeviers(array $structure)` exigeait jusqu'ici des positions
     * explicites (`structure.leviers[] = {x, y, levier_id}`) — des
     * coordonnées que la carte, générée à l'EXÉCUTION, ne peut pas connaître
     * à l'avance. Aucun gabarit n'en a donc jamais déclaré, si bien que
     * `actionner_levier` — pourtant câblé de bout en bout côté
     * `MoteurPortes`/`MenuMoteur`/`ResolveurTour` — n'était jamais atteint en
     * partie réelle.
     *
     * ⚠ **Les leviers ne sont PAS thématiques** (René, 2026-09-06 :
     * « les leviers devraient être disponibles pour toute quête, alors que
     * les éléments de froid ne devraient être présents que si la thématique
     * est liée »). Contrairement à `placerTerrains()`, aucun filtre `boite`,
     * aucun paramètre `$theme` : un levier a sa place dans n'importe quel
     * donjon, glace ou non.
     *
     * Cycle complet : parmi les portes de l'ARBRE COUVRANT (jamais une
     * liaison SUPPLÉMENTAIRE — verrouiller une boucle ne gênerait personne,
     * la salle restant joignable par l'autre route, exactement le défaut
     * d'une couche jamais alimentée un cran plus haut) qui sont closes et
     * ORDINAIRES (jamais déjà secrètes — un passage caché et un verrou sont
     * deux mécaniques distinctes, les mélanger serait incompréhensible pour
     * le joueur), on en choisit une, on la fait passer à `verrouillee` avec
     * `verrou: {type: levier, levier_id}`, puis on pose le levier
     * CORRESPONDANT sur une case de sol déjà atteignable sans cette porte.
     *
     * La porte côté PARENT de chaque arête est celle qui porte historiquement
     * la restriction (`construirePorte()` : « c'est la porte côté salle
     * PARENT […] qui porte la restriction ») : verrouiller cette porte scelle
     * tout le sous-arbre au-delà, exactement ce qu'un verrou de jeu doit
     * faire. ⚠ Son index dans `$portes` n'est PLUS `2 * $indexArete`
     * (2026-09-11) : une jonction MITOYENNE (`accolerSallesMitoyennes()`, « un
     * mur, une porte ») ne pousse plus qu'UNE entrée au lieu de deux, ce qui
     * décale tout ce qui suit dès la première rencontrée. `assembler()` note
     * donc la position RÉELLE de chaque porte-parent au moment où il la
     * pousse (`$indexPorteParentParArete`) et la transmet ici toute faite,
     * plutôt que de la recalculer par arithmétique.
     *
     * ⚠ INVARIANT DUR (le seul vrai piège de la fonctionnalité, René) : le
     * levier ne doit JAMAIS se trouver derrière la porte qu'il verrouille —
     * sinon il faut le levier pour aller au levier. Vérifié par un PARCOURS
     * RÉEL (`Grille::casesAtteignables()`, sur une grille de test où TOUTES
     * les portes sont ouvertes SAUF celle qu'on verrouille) depuis la salle
     * de départ, exactement comme `terrainCasseraitConnexite()` vérifie déjà
     * la joignabilité pour le terrain — jamais déduit de la structure de
     * l'arbre, qui ne dit rien des liaisons supplémentaires ni des salles
     * mitoyennes. Aucune case atteignable ? On RENONCE au verrou plutôt que
     * de le forcer : la porte reste simplement fermée, ouvrable à la main
     * (`MoteurPortes::ouvrableAMain()` gère déjà ce cas par défaut).
     *
     * ⚠ Les verrous déjà ACCEPTÉS plus tôt dans cette même carte sont eux
     * aussi traités comme bloquants pendant la vérification suivante : sans
     * ça, deux leviers pourraient se verrouiller l'un l'autre en chaîne (le
     * levier A cadenassé derrière la porte B, le levier B derrière la porte
     * A) — une impasse qu'une vérification porte par porte, isolée, ne
     * verrait jamais, chacune prise seule semblant franchissable.
     *
     * ⚠ En revanche — c'est déjà arbitré et volontaire — une salle PEUT
     * dépendre d'un SEUL levier, sans seconde route : forcer un levier
     * demande un jet de Body (`ResolveurTour::resoudreActionnerLevier()`) et
     * il est réessayable SANS LIMITE, ce qui est exactement ce qui permet à
     * une salle de tenir à ce seul levier sans jamais se refermer.
     *
     * Format de sortie INCHANGÉ : `{x, y, levier_id}`, SANS index de salle —
     * un levier de couloir n'a pas de salle à porter, et `EtatGroupe` dérive
     * déjà la visibilité des coordonnées, pas d'un index (même format que
     * `terrain`). `levier_id` est une simple chaîne unique qui apparie le
     * levier à sa porte ; il n'y a pas de table catalogue pour les leviers,
     * et il n'en faut pas.
     *
     * @param  array<string, mixed>  $structure
     * @param  list<list<string>>  $cases
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     * @param  list<array<string, mixed>>  $portes  MUTÉ EN PLACE : la porte choisie passe à `verrouillee`
     * @param  int  $nombreSalles  nombre de salles de la carte — l'arbre couvrant
     *                             compte exactement `$nombreSalles - 1` arêtes
     * @param  array<int, int>  $indexPorteParentParArete  index RÉEL, dans `$portes`,
     *                             de la porte côté parent de chaque arête de
     *                             l'arbre (clé = indice d'arête, cf. `assembler()`)
     * @return list<array{x: int, y: int, levier_id: string}>
     */
    private function placerLeviers(
        array $structure,
        array $cases,
        array $salles,
        array &$portes,
        int $nombreSalles,
        array $indexPorteParentParArete,
        \Closure $suivant,
    ): array {
        $min = (int) data_get($structure, 'leviers.min', 0);
        $max = max($min, (int) data_get($structure, 'leviers.max', $min));

        if ($max <= 0 || ! isset($salles[0])) {
            return [];
        }

        $depart = $this->interieur($cases, $salles[0])[0] ?? null;
        if ($depart === null) {
            return [];
        }

        $prng = new PrngLineaire($suivant());
        $voulus = $prng->entre($min, $max);

        if ($voulus <= 0) {
            return [];
        }

        // Portes côté PARENT de chaque arête de L'ARBRE COUVRANT (les
        // `$nombreSalles - 1` premières arêtes construites par `assembler()`,
        // AVANT les liaisons supplémentaires) — position réelle fournie par
        // l'appelant, cf. docblock ci-dessus.
        $indicesArbre = $nombreSalles >= 2 ? range(0, $nombreSalles - 2) : [];
        $candidatesIndex = [];
        foreach ($indicesArbre as $indexArete) {
            $idxPorte = $indexPorteParentParArete[$indexArete] ?? null;
            if ($idxPorte !== null && isset($portes[$idxPorte]) && ($portes[$idxPorte]['etat'] ?? null) === MoteurPortes::ETAT_FERMEE) {
                $candidatesIndex[] = $idxPorte;
            }
        }

        if ($candidatesIndex === []) {
            return [];
        }

        $candidatesIndex = $prng->melanger($candidatesIndex);

        // Seuils de TOUTE porte de la carte (verrouillée ou non) : jamais une
        // case de levier dessus — même raison que le mobilier, à la fois
        // invisible (même calque de rendu) et indéclenchable.
        $seuils = [];
        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $case) {
                $seuils["{$case['x']},{$case['y']}"] = true;
            }
        }

        // Plafond de pas large mais SÛR pour couvrir toute la carte, quelle
        // que soit la sinuosité du chemin réel (un plus court chemin ne
        // revisite jamais une case, donc ne dépasse jamais le nombre total
        // de cases de la grille).
        $porteeMax = count($cases) * count($cases[0] ?? []);

        $leviers = [];
        $prises = [];             // cases déjà données à un AUTRE levier de cette carte
        $aretesVerrouillees = []; // arêtes déjà verrouillées CE tour-ci (chaîne interdite)

        foreach ($candidatesIndex as $idxPorte) {
            if (count($leviers) >= $voulus) {
                break;
            }

            $cleArete = $this->cleAreteDePorte($portes[$idxPorte]);

            // Grille de TEST : toutes les portes OUVERTES (franchissables),
            // SAUF celle qu'on est en train de verrouiller et celles déjà
            // verrouillées plus tôt sur cette carte — la question posée n'est
            // pas « ce chemin est-il ouvert maintenant ? » mais « existe-t-il,
            // sans passer par LA porte qu'on verrouille (ni par un verrou déjà
            // accepté) ? ».
            $grilleTest = new Grille($cases);
            $grilleTest->definirPortes(array_map(function (array $p) use ($cleArete, $aretesVerrouillees) {
                $bloquee = $this->cleAreteDePorte($p) === $cleArete
                    || isset($aretesVerrouillees[$this->cleAreteDePorte($p)]);
                $p['etat'] = $bloquee ? MoteurPortes::ETAT_FERMEE : MoteurPortes::ETAT_OUVERTE;

                return $p;
            }, $portes));

            $atteignables = $grilleTest->casesAtteignables($depart['x'], $depart['y'], $porteeMax);

            $candidatsCase = [];
            foreach (array_keys($atteignables) as $cle) {
                if (isset($seuils[$cle]) || isset($prises[$cle])) {
                    continue;
                }
                [$cx, $cy] = array_map('intval', explode(',', $cle));
                if ($cx >= $salles[0]['x'] && $cx < $salles[0]['x'] + $salles[0]['largeur']
                    && $cy >= $salles[0]['y'] && $cy < $salles[0]['y'] + $salles[0]['hauteur']) {
                    continue; // jamais en salle de départ : subi au tour 1, pas joué
                }
                $candidatsCase[] = ['x' => $cx, 'y' => $cy];
            }

            if ($candidatsCase === []) {
                continue; // RENONCE à ce verrou : la porte reste fermée, ouvrable à la main
            }

            $position = $prng->melanger($candidatsCase)[0];
            $levierId = 'levier-'.(count($leviers) + 1);

            $portes[$idxPorte]['etat'] = MoteurPortes::ETAT_VERROUILLEE;
            $portes[$idxPorte]['verrou'] = ['type' => 'levier', 'levier_id' => $levierId];

            $aretesVerrouillees[$cleArete] = true;
            $prises["{$position['x']},{$position['y']}"] = true;

            $leviers[] = ['x' => $position['x'], 'y' => $position['y'], 'levier_id' => $levierId];
        }

        return $leviers;
    }

    /** Clé d'arête (`Grille::cleArete()`) de la porte — l'arête qu'elle verrouille. */
    private function cleAreteDePorte(array $porte): string
    {
        [$a, $b] = Grille::casesPorte($porte);

        return Grille::cleArete($a['x'], $a['y'], $b['x'], $b['y']);
    }

    /**
     * Pièges du gabarit (structure.pieges.min), un par couloir (arête), posé
     * au milieu de son creusement — le TYPE de chaque piège est tiré au
     * hasard parmi les pièges DE SOL du catalogue par le PRNG déterministe de
     * l'assemblage (l'IA n'habille que le nom/la description, jamais l'effet).
     *
     * Cycle de vie (doc 10 §2) : chaque piège démarre `cache`, puis passe à
     * `detecte` (fouille / Œil du mineur), `desarme`, ou `declenche` — l'état
     * vit ici, dans la grille JSON de la carte de la quête (MoteurPieges).
     *
     * Les pièges se posent au milieu des couloirs ET **dans les salles** : s'en
     * tenir aux couloirs les rendait prévisibles (« un couloir = un piège ») et
     * laissait les salles totalement sûres, alors que c'est là qu'on s'arrête,
     * qu'on fouille et qu'on se bat. La salle de DÉPART en est exclue — un piège
     * sous les pieds du groupe au tour 1 serait subi, pas joué.
     *
     * Seuls les HÉROS les déclenchent : `MoteurPieges::declencher()` n'accepte
     * qu'un `Personnage`, un monstre ne peut pas y être passé. Une créature
     * postée sur un piège est donc une embuscade, pas un bug.
     *
     * ⚠ `$leviers` (posés juste avant, voir `assembler()`) est exclu des
     * candidats — un piège dessous serait à la fois invisible (même calque de
     * rendu) et à jamais indéclenchable pour le levier, même raison que les
     * exclusions déjà faites pour le mobilier/les épreuves/le terrain.
     *
     * @param  array<string, mixed>  $structure
     * ⚠ `$milieuxVoieUnique` (René, 2026-09-27) : dans un couloir d'UNE case,
     * une Chute de blocs tombée ferme le passage à jamais — et sur une arête
     * sans boucle, les salles au-delà, objectif compris. Le tirage y écarte
     * donc la Chute de blocs (`bloc_permanent`) : Fosse ou Piège à lances
     * seulement. En salle et en couloir double, rien ne change.
     *
     * @param  list<array{x: int, y: int}>  $milieuxCouloirs
     * ⚠ **Jamais sur une case de PORTE** (2026-09-27, trouvé par le test
     * « jamais de Chute de blocs en voie unique ») : `interieur()` prend pour
     * du sol de salle la case de mur que le couloir a PERCÉE — l'embrasure. Un
     * piège s'y posait donc, sous une porte close (inoccupable, invisible), et
     * une Chute de blocs y murait le seuil pour de bon, quelle que soit la
     * largeur du couloir. Les deux cases de chaque porte sont écartées, comme
     * le mobilier écarte déjà les seuils (`seuilsDeSalle()`).
     *
     * @param  array<string, true>  $milieuxVoieUnique  clés « x,y »
     * @param  list<list<string>>  $cases
     * @param  list<array<string, mixed>>  $portes
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     * @param  list<array<string, mixed>>  $portes
     * @param  list<array{x: int, y: int, levier_id: string}>  $leviers
     * @return list<array{x: int, y: int, piege_id: int|null, etat: string}>
     */
    private function placerPieges(
        array $structure,
        array $milieuxCouloirs,
        array $milieuxVoieUnique,
        array $cases,
        array $salles,
        array $portes,
        array $leviers,
        \Closure $suivant,
        ?BestiaireGroupe $bestiaire = null,
    ): array {
        // Cases de salle éligibles : tout l'intérieur SAUF la salle de départ.
        $enSalle = [];
        foreach ($salles as $i => $salle) {
            if ($i === 0) {
                continue;
            }
            foreach ($this->interieur($cases, $salle) as $position) {
                $enSalle[] = $position;
            }
        }

        $prng = new PrngLineaire($suivant());

        // Mélange de l'UNION : garder les milieux de couloir en tête revenait à
        // n'en poser qu'en couloir (58 sur 61 mesurés), donc à garder les salles
        // parfaitement sûres — l'inverse de ce qu'on veut.
        $candidats = $prng->melanger([...$milieuxCouloirs, ...$enSalle]);

        // Garde-fou finaL : jamais dans la salle de départ, quelle que soit
        // l'origine du candidat. Un milieu de couloir peut y tomber quand une
        // salle mitoyenne a été rapprochée et a absorbé le couloir voisin.
        // Ni sur la case d'un levier déjà posé, ni sur une case de porte.
        $depart = $salles[0] ?? null;
        $interdites = [];
        foreach ($leviers as $levier) {
            $interdites["{$levier['x']},{$levier['y']}"] = true;
        }
        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $case) {
                $interdites["{$case['x']},{$case['y']}"] = true;
            }
        }
        $candidats = array_values(array_filter($candidats, function (array $c) use ($depart, $interdites) {
            if (isset($interdites["{$c['x']},{$c['y']}"])) {
                return false;
            }

            return $depart === null || ! (
                $c['x'] >= $depart['x'] && $c['x'] < $depart['x'] + $depart['largeur']
                && $c['y'] >= $depart['y'] && $c['y'] < $depart['y'] + $depart['hauteur']
            );
        }));

        $min = (int) data_get($structure, 'pieges.min', 0);
        $max = max($min, (int) data_get($structure, 'pieges.max', $min));
        $nbPieges = min($min + ($max > $min ? $prng->suivant() % ($max - $min + 1) : 0), count($candidats));

        // ⚠ TIRAGE, pas un id fixe (René, 2026-09-24 : « je semble toujours
        // avoir des trous ») — `Piege::orderBy('id')->value('id')` posait LE
        // PREMIER piège du catalogue pour chaque case tirée, soit la Fosse :
        // mesuré sur toutes les cartes en base, 6 pièges posés, 6 fosses. La
        // Chute de blocs et le Piège à lances n'étaient donc JAMAIS placés,
        // quel que soit le gabarit de quête.
        //
        // Pièges DE SOL uniquement — jamais un piège de coffre/meuble
        // (`effet.declencheur === 'ouverture_tresor'`, doc 10 §5) : celui-là
        // se tire à la fouille du trésor (`MoteurPieges::declencherEphemere()`),
        // pas à l'assemblage de la carte. ⚠ Ni un piège à ZONE
        // (`effet.zone_lames`, Lame balançoire) — il a besoin d'une validation
        // géométrique (axe, bornes de la salle, plancher de cases jouables)
        // que ce tirage par case isolée ne sait pas faire ; voir
        // `placerLameBalanciere()`, appelé séparément par `assembler()`. Et
        // boîte : un piège `boite` n'entre dans ce vivier QUE si le thème de
        // bestiaire du groupe l'inclut (même lecture que `Terrain::boite`).
        $piegesSol = Piege::query()
            ->orderBy('id')
            ->get()
            ->reject(fn (Piege $p) => data_get($p->effet, 'declencheur') === 'ouverture_tresor')
            ->reject(fn (Piege $p) => data_get($p->effet, 'zone_lames') !== null)
            // Wizards of Morcar (lot B, 2026-10-06) : les trois pièges
            // magiques ont chacun une pose DÉDIÉE (`placerPiegesMorcar()`,
            // juste après `placerLameBalanciere()`) — le Téléporteur exige
            // une PAIRE de cases plutôt qu'une seule, l'Ouragan un COULOIR
            // précisément, l'Embrasement n'a besoin de rien de spécial mais
            // rejoint quand même la pose dédiée par cohérence du lot. Tirés
            // ici, ils se seraient retrouvés parfois en salle (le Téléporteur,
            // l'Embrasement) parfois tout court sans garantie de paire.
            ->reject(fn (Piege $p) => (bool) data_get($p->effet, 'teleportation', false))
            ->reject(fn (Piege $p) => in_array(data_get($p->effet, 'declencheur'), ['hurricane', 'fireburst_differe'], true))
            ->filter(fn (Piege $p) => $p->boite === null || ($bestiaire?->contient($p->boite) ?? false))
            ->values();

        if ($piegesSol->isEmpty()) {
            return [];
        }

        $sansBloc = $piegesSol
            ->reject(fn (Piege $p) => (bool) data_get($p->effet, 'bloc_permanent', false))
            ->values();

        $pieges = [];
        for ($i = 0; $i < $nbPieges; $i++) {
            $vivier = isset($milieuxVoieUnique["{$candidats[$i]['x']},{$candidats[$i]['y']}"]) && $sansBloc->isNotEmpty()
                ? $sansBloc
                : $piegesSol;
            $piege = $vivier[$prng->suivant() % $vivier->count()];

            $pieges[] = [
                'x' => $candidats[$i]['x'],
                'y' => $candidats[$i]['y'],
                'piege_id' => $piege->id,
                'etat' => 'cache',
            ];
        }

        return $pieges;
    }

    /**
     * LAME BALANÇOIRE (Against the Ogre Horde, livret F9528 p. 4-5, lot B) —
     * voir `MoteurPieges::declencherZone()` pour la résolution. Posée à PART
     * de `placerPieges()` : c'est le premier piège DU JEU à occuper plusieurs
     * cases (`effet.zone_lames`), et sa zone doit être validée géométriquement
     * — jamais un simple tirage de case isolée.
     *
     * ⚠ **PORTAGE** : le livret ne donne le gabarit exact de la zone de lame
     * que sur le plan imprimé d'une quête précise (un dessin, pas un texte) —
     * nos donjons sont générés, pas imprimés. On retient une ligne de 3
     * cases centrée sur la case dorée de déclenchement (`effet.zone_lames`
     * du catalogue, vertical par défaut), posée sur l'axe — horizontal OU
     * vertical — qui tient dans LA MÊME salle au moment du tirage. C'est une
     * décision de jeu, pas une valeur sourcée ; ce que le livret source (le
     * nombre de dés et le fonctionnement en zone) vit dans `PiegeSeeder`.
     *
     * Jamais en salle de départ, jamais sur un seuil/levier/piège déjà posé,
     * et jamais si la pose ferait tomber la salle sous
     * `CASES_JOUABLES_MINIMUM` cases libres (§2.12 ter, « connected is not
     * playable » étendu à « une zone de lame ne doit jamais couvrir le
     * plancher de cases jouables d'une salle, ni a fortiori l'unique passage
     * d'un couloir — elle n'est donc JAMAIS posée en couloir du tout, seule
     * une salle a la place de l'accueillir correctement »). Si aucune salle
     * n'offre de pose valide, on renonce PUREMENT ET SIMPLEMENT (comme toute
     * autre couche) : au plus UNE lame balançoire par carte.
     *
     * @param  list<list<string>>  $cases
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     * @param  list<array<string, mixed>>  $portes
     * @param  list<array{x: int, y: int, levier_id: string}>  $leviers
     * @param  list<array{x: int, y: int, piege_id: int, etat: string}>  $pieges  déjà posés par placerPieges()
     * @return list<array{x: int, y: int, piege_id: int, etat: string, zone: list<array{x: int, y: int}>}>
     */
    private function placerLameBalanciere(
        array $cases,
        array $salles,
        array $portes,
        array $leviers,
        array $pieges,
        \Closure $suivant,
        ?BestiaireGroupe $bestiaire,
    ): array {
        $prng = new PrngLineaire($suivant());

        // Boîte : jamais hors du thème Against the Ogre Horde.
        if (! ($bestiaire?->contient('horde_ogre') ?? false)) {
            return [];
        }

        $catalogue = Piege::query()->get()
            ->first(fn (Piege $p) => is_array($p->effet) && isset($p->effet['zone_lames'])
                && ($p->boite === null || $bestiaire->contient($p->boite)));

        if ($catalogue === null) {
            return [];
        }

        $formeVerticale = (array) $catalogue->effet['zone_lames'];

        // Cases interdites : seuils, leviers, pièges déjà posés (+ leur zone,
        // par prudence si cette méthode était un jour appelée deux fois).
        $interdites = [];
        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $case) {
                $interdites["{$case['x']},{$case['y']}"] = true;
            }
        }
        foreach ($leviers as $levier) {
            $interdites["{$levier['x']},{$levier['y']}"] = true;
        }
        foreach ($pieges as $piege) {
            $interdites["{$piege['x']},{$piege['y']}"] = true;
            foreach ((array) ($piege['zone'] ?? []) as $z) {
                $interdites[((int) $z['x']).','.((int) $z['y'])] = true;
            }
        }

        // Pool global (salle, case) mélangé — jamais la salle 0, même raison
        // que tout le reste : subi au tour 1, pas joué.
        $pool = [];
        $interieurParSalle = [];
        foreach ($salles as $i => $salle) {
            if ($i === 0) {
                continue;
            }
            $interieurParSalle[$i] = $this->interieur($cases, $salle);
            foreach ($interieurParSalle[$i] as $position) {
                if (isset($interdites["{$position['x']},{$position['y']}"])) {
                    continue;
                }
                $pool[] = [...$position, 'salle' => $i];
            }
        }

        if ($pool === []) {
            return [];
        }

        $pool = $prng->melanger($pool);

        foreach ($pool as $candidat) {
            $i = (int) $candidat['salle'];
            $libresAvant = count(array_filter(
                $interieurParSalle[$i],
                fn (array $p) => ! isset($interdites["{$p['x']},{$p['y']}"]),
            ));

            // Deux orientations tentées pour CE candidat (vertical, puis
            // horizontal) — un seul tirage PRNG choisit l'ordre, pas chacune
            // des deux tentatives : la suite reste indépendante du nombre de
            // candidats essayés avant de réussir.
            $ordres = $prng->suivant() % 2 === 0
                ? [$formeVerticale, $this->transposerForme($formeVerticale)]
                : [$this->transposerForme($formeVerticale), $formeVerticale];

            foreach ($ordres as $forme) {
                $zone = array_map(fn (array $offset) => [
                    'x' => $candidat['x'] + $offset[0], 'y' => $candidat['y'] + $offset[1],
                ], $forme);

                $valide = $libresAvant - count($zone) >= self::CASES_JOUABLES_MINIMUM;

                foreach ($zone as $z) {
                    $cle = "{$z['x']},{$z['y']}";
                    if (isset($interdites[$cle])
                        || ! in_array($z, $interieurParSalle[$i], true)) {
                        $valide = false;
                        break;
                    }
                }

                if ($valide) {
                    return [[
                        'x' => $candidat['x'], 'y' => $candidat['y'],
                        'piege_id' => $catalogue->id, 'etat' => 'cache',
                        'zone' => array_values($zone),
                    ]];
                }
            }
        }

        // Aucune salle n'offre de pose valide : on renonce, comme toute autre
        // couche — jamais de lame balançoire forcée.
        return [];
    }

    /** Transpose une forme relative (échange dx/dy) : vertical ↔ horizontal. */
    private function transposerForme(array $forme): array
    {
        return array_map(fn (array $offset) => [$offset[1], $offset[0]], $forme);
    }

    /**
     * PIÈGES MAGIQUES DE WIZARDS OF MORCAR (lot B, 2026-10-06) — poses
     * DÉDIÉES, à PART du tirage générique de `placerPieges()`, même raison
     * que `placerLameBalanciere()` juste au-dessus : chacun a une exigence
     * géométrique qu'un tirage de case isolée ne sait pas honorer.
     *
     * - **Piège de téléportation** : une PAIRE de cases de SALLE (jamais la
     *   0), même patron que les Tunnels de glace (`placerTerrains()`).
     * - **Piège d'embrasement** : une case de SALLE — « the room » (doc 18) ;
     *   posé ici plutôt que dans le tirage mixte salle+couloir générique
     *   pour que sa zone d'explosion reste toujours une VRAIE salle.
     * - **Piège de l'ouragan** : une case de COULOIR (`$milieuxCouloirs`,
     *   le même vivier que `placerPieges()`) — « repousse tous les
     *   personnages DU COULOIR », n'a aucun sens en salle.
     *
     * Le THÈME dit LESQUELS (`$bestiaire?->contient('wizards_of_morcar')`,
     * même lecture que `Terrain::boite`) ; aucun gabarit ne dit COMBIEN — au
     * plus UN de chaque, hardcodé plutôt qu'un `structure.pieges_magiques`
     * qu'aucun gabarit ne déclare encore (la leçon des leviers : une couche
     * qui marche mais qu'aucun gabarit n'alimente équivaut à une couche
     * absente). Si la carte n'offre pas assez de cases, on RENONCE — jamais
     * de pose forcée, même garde que toutes les couches de ce fichier.
     *
     * @param  list<list<string>>  $cases
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     * @param  list<array{x: int, y: int, cote?: string}>  $portes
     * @param  list<array{x: int, y: int, levier_id: string}>  $leviers
     * @param  list<array{x: int, y: int}>  $pieges  déjà posés (générique + Lame balançoire)
     * @param  list<array{x: int, y: int}>  $milieuxCouloirs
     * @return list<array{x: int, y: int, piege_id: int, etat: string, paire_id?: string}>
     */
    private function placerPiegesMorcar(
        array $cases,
        array $salles,
        array $portes,
        array $leviers,
        array $pieges,
        array $milieuxCouloirs,
        \Closure $suivant,
        ?BestiaireGroupe $bestiaire = null,
    ): array {
        if (! ($bestiaire?->contient('wizards_of_morcar') ?? false)) {
            return [];
        }

        $catalogue = Piege::query()->where('boite', 'wizards_of_morcar')->get()->keyBy('nom');
        $teleport = $catalogue->get('Piège de téléportation');
        $embrasement = $catalogue->get("Piège d'embrasement");
        $ouragan = $catalogue->get("Piège de l'ouragan");

        if ($teleport === null && $embrasement === null && $ouragan === null) {
            return [];
        }

        $prng = new PrngLineaire($suivant());

        $interdites = [];
        foreach ($pieges as $p) {
            $interdites["{$p['x']},{$p['y']}"] = true;
        }
        foreach ($leviers as $l) {
            $interdites["{$l['x']},{$l['y']}"] = true;
        }
        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $case) {
                $interdites["{$case['x']},{$case['y']}"] = true;
            }
        }

        // Pool des cases de SALLE libres (jamais la 0) : Téléporteur (paire)
        // et Embrasement (une case) y puisent tous les deux.
        $candidatsSalle = [];
        foreach ($salles as $i => $salle) {
            if ($i === 0) {
                continue;
            }
            foreach ($this->interieur($cases, $salle) as $position) {
                if (! isset($interdites["{$position['x']},{$position['y']}"])) {
                    $candidatsSalle[] = $position;
                }
            }
        }
        $candidatsSalle = $prng->melanger($candidatsSalle);

        $nouveaux = [];

        if ($teleport !== null && count($candidatsSalle) >= 2) {
            $a = array_shift($candidatsSalle);
            $b = array_shift($candidatsSalle);
            $paireId = 'teleport-morcar';
            $nouveaux[] = ['x' => $a['x'], 'y' => $a['y'], 'piege_id' => (int) $teleport->id, 'etat' => 'cache', 'paire_id' => $paireId];
            $nouveaux[] = ['x' => $b['x'], 'y' => $b['y'], 'piege_id' => (int) $teleport->id, 'etat' => 'cache', 'paire_id' => $paireId];
            $interdites["{$a['x']},{$a['y']}"] = true;
            $interdites["{$b['x']},{$b['y']}"] = true;
        }

        if ($embrasement !== null && $candidatsSalle !== []) {
            $c = array_shift($candidatsSalle);
            $nouveaux[] = ['x' => $c['x'], 'y' => $c['y'], 'piege_id' => (int) $embrasement->id, 'etat' => 'cache'];
            $interdites["{$c['x']},{$c['y']}"] = true;
        }

        if ($ouragan !== null) {
            $candidatsCouloir = $prng->melanger(array_values(array_filter(
                $milieuxCouloirs,
                fn (array $m) => ! isset($interdites["{$m['x']},{$m['y']}"]),
            )));

            if ($candidatsCouloir !== []) {
                $c = $candidatsCouloir[0];
                $nouveaux[] = ['x' => $c['x'], 'y' => $c['y'], 'piege_id' => (int) $ouragan->id, 'etat' => 'cache'];
            }
        }

        return $nouveaux;
    }

    /**
     * ÉPREUVES posées sur la carte (2026-08-24) — quatrième couche, au même
     * niveau que `leviers`/`pieges`/`mobilier`, et sans toucher une seule case
     * `m`/`s` : c'est `MoteurEpreuves` qui la lit.
     *
     * Une épreuve est un ancrage auquel un héros au contact tente un JET
     * D'ATTRIBUT. Elle existe pour une raison précise : depuis la suppression de
     * `MenuChoix` (2026-08-18), le moteur n'émettait plus qu'UN SEUL type de jet
     * — « Fouiller la zone », Mind, contexte `perception`. Les contextes
     * `savoir` et `social_peur` n'avaient donc aucun producteur, et six talents
     * de la grille (*Intimidation*, *Érudition*, *Prestance*, *Beau parleur*,
     * *Méditation*, *Cartographe*) ne se déclenchaient jamais en partie.
     *
     * ⚠ **Aucune coordonnée n'est déclarée par le gabarit**, seulement un
     * comptage — c'est la leçon des leviers, dont `placerLeviers()` exige un x/y
     * que le placement procédural ne peut pas connaître, si bien qu'aucun
     * gabarit n'en a jamais déclaré et que l'action « Actionner le levier »
     * n'était jamais atteinte.
     *
     * ⚠ `exige_placement` est une **précondition de POSE**, distincte de l'effet :
     * l'Autel fêlé désarme les pièges de sa salle, et ne se pose donc que dans
     * une salle qui en contient un — récompenser un joueur en désarmant le vide
     * lui ferait dépenser son action sans qu'il puisse le savoir. Si aucune
     * salle n'a de piège (ils tombent aussi dans les couloirs), le type est
     * simplement écarté du vivier : on ne pose pas plutôt que de poser mal, même
     * refus que le meuble mural qui ne trouve pas de mur.
     *
     * @param  array<string, mixed>  $structure
     * @param  list<list<string>>  $cases
     * @param  array<int, array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     * @return list<array{x: int, y: int, epreuve_id: int, salle: int, tentee_par: list<int>}>
     */
    private function placerEpreuves(
        array $structure,
        array $cases,
        array $salles,
        array $portes,
        array $leviers,
        array $pieges,
        array $mobilier,
        \Closure $suivant,
    ): array {
        $catalogue = Epreuve::query()->orderBy('id')->get();

        if ($catalogue->isEmpty()) {
            return [];
        }

        $min = (int) data_get($structure, 'epreuves.min', 1);
        $max = max($min, (int) data_get($structure, 'epreuves.max', $min + 1));

        if ($max <= 0) {
            return [];
        }

        // Cases interdites : mêmes exclusions que le mobilier, plus le mobilier
        // lui-même. Une épreuve sous une bibliothèque serait injouable.
        $interdites = [];
        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $case) {
                $interdites["{$case['x']},{$case['y']}"] = true;
            }
        }
        foreach ($leviers as $levier) {
            $interdites["{$levier['x']},{$levier['y']}"] = true;
        }
        foreach ($pieges as $piege) {
            $interdites["{$piege['x']},{$piege['y']}"] = true;

            foreach ((array) ($piege['zone'] ?? []) as $z) {
                $interdites[((int) $z['x']).','.((int) $z['y'])] = true;
            }
        }
        foreach ($mobilier as $meuble) {
            for ($dx = 0; $dx < (int) ($meuble['l'] ?? 1); $dx++) {
                for ($dy = 0; $dy < (int) ($meuble['h'] ?? 1); $dy++) {
                    $interdites[((int) $meuble['x'] + $dx).','.((int) $meuble['y'] + $dy)] = true;
                }
            }
        }

        // Salles qui contiennent un piège — la précondition de l'Autel fêlé.
        $sallesPiegees = [];
        foreach ($pieges as $piege) {
            $salle = Salles::indexDe($salles, (int) $piege['x'], (int) $piege['y']);
            if ($salle !== null) {
                $sallesPiegees[$salle] = true;
            }
        }

        // Candidates : l'intérieur de toutes les salles SAUF celle du départ —
        // même principe que les pièges et le mobilier, une épreuve posée sous
        // les pieds du groupe au premier tour est subie, pas jouée.
        $candidates = [];
        foreach ($salles as $i => $salle) {
            if ($i === 0) {
                continue;
            }
            foreach ($this->interieur($cases, $salle) as $position) {
                if (isset($interdites["{$position['x']},{$position['y']}"])) {
                    continue;
                }
                $candidates[] = [...$position, 'salle' => $i];
            }
        }

        if ($candidates === []) {
            return [];
        }

        $prng = new PrngLineaire($suivant());
        $candidates = $prng->melanger($candidates);

        $voulues = $min + ($max > $min ? $prng->suivant() % ($max - $min + 1) : 0);
        $epreuves = [];
        $prises = [];

        foreach ($candidates as $candidate) {
            if (count($epreuves) >= $voulues) {
                break;
            }

            // Une seule épreuve par salle : elles se disputeraient l'attention
            // du groupe, et une salle en offrant trois n'est plus une salle.
            if (isset($prises[$candidate['salle']])) {
                continue;
            }

            $vivier = $catalogue->filter(fn (Epreuve $e) => $e->exige_placement === null
                || ($e->exige_placement === 'piege_dans_la_salle' && isset($sallesPiegees[$candidate['salle']])))
                ->values();

            if ($vivier->isEmpty()) {
                continue;
            }

            $type = $vivier[$prng->suivant() % $vivier->count()];

            $epreuves[] = [
                'x' => (int) $candidate['x'],
                'y' => (int) $candidate['y'],
                'epreuve_id' => (int) $type->id,
                'salle' => (int) $candidate['salle'],
                // Une tentative par héros : la liste s'empile comme `fouille_par`.
                'tentee_par' => [],
            ];
            $prises[$candidate['salle']] = true;
        }

        return $epreuves;
    }

    /** Index de la salle contenant cette case, ou null (couloir). */
    /**
     * Mobilier de salle (doc 17) : table, coffre, trône, établi d'alchimiste,
     * tombeau, bibliothèque, râtelier d'armes, armoire — les 8 types dont
     * l'emprise a été mesurée (MobilierSeeder). Jamais en couloir (le tirage
     * ne porte que sur `interieur($salle)`), jamais en salle de départ (même
     * principe que les pièges : subi, pas joué), jamais sur un seuil de porte
     * ni sur une case de levier (`$interdites`, communes à toute la carte).
     *
     * Densité 0 à 3 par salle, tirée indépendamment pour chacune : « des
     * salles vides existent au plateau » (doc 17 §1, aucune quête consultée
     * n'en meuble toutes les pièces).
     *
     * Invariant dur, réellement vérifié (pas seulement évité par construction
     * comme pour les portes) : un meuble ne doit JAMAIS isoler une case par
     * ailleurs atteignable — `salleResteConnexe()` relance une BFS après
     * CHAQUE pose tentée, meuble compris, et la pose est abandonnée
     * (silencieusement — la salle reste juste moins meublée) si une case, un
     * autre seuil compris, cesse d'être atteignable. C'est la même classe de
     * bug que le brouillard qui figeait le groupe (§2.16) : un placement
     * procédural qui ne revérifie jamais son propre résultat finit tôt ou
     * tard par condamner une case.
     *
     * @param  list<list<string>>  $cases
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int, theme: string}>  $salles
     * @param  list<array{x: int, y: int, cote?: string}>  $portes
     * @param  list<array{x: int, y: int, levier_id: string}>  $leviers
     * @param  list<array{x: int, y: int}>  $pieges
     * @param  list<int>  $sallesAGarantirUnCoffre  salles (`DeckFouille::sallesACoffre()`,
     *                                             via la fermeture `assembler()`)
     *                                             qui DOIVENT recevoir un
     *                                             `Coffre` — la narration
     *                                             décrivait un coffre qu'aucune
     *                                             carte ne portait (René,
     *                                             2026-09-18). Posé EN PREMIER,
     *                                             avant le mobilier ordinaire
     *                                             ci-dessous — sinon un tirage
     *                                             0..3 malchanceux pouvait
     *                                             consommer à sa place le
     *                                             plancher de cases jouables
     *                                             d'une salle par ailleurs assez
     *                                             grande. Toujours LA MÊME
     *                                             logique de pose que le
     *                                             mobilier ordinaire (jamais
     *                                             une seconde copie du plancher
     *                                             §2.12 ter) : si la salle est
     *                                             trop exiguë pour l'accueillir
     *                                             sans devenir injouable, on
     *                                             RENONCE — voir le commentaire
     *                                             au point d'appel, plus bas.
     *                                             `garantirTaillesSallesACoffre()`
     *                                             écarte déjà la plupart des
     *                                             salles trop petites en amont,
     *                                             AVANT même que cette méthode
     *                                             ne soit appelée.
     * @return list<array{mobilier_id: int, x: int, y: int, l: int, h: int, salle: int}>
     */
    private function placerMobilier(array $cases, array $salles, array $portes, array $leviers, array $pieges, \Closure $suivant, array $sallesAGarantirUnCoffre = [], ?BestiaireGroupe $bestiaire = null, ?string $nomElementObjectif = null): array
    {
        // Boîte : une pièce de mobilier `boite` (ex. la Caisse de
        // ravitaillement, `horde_ogre`) n'entre dans le catalogue QUE si le
        // thème de bestiaire du groupe l'inclut — même lecture que
        // `Terrain::boite` / `Piege::boite` ci-dessus.
        $disponible = Mobilier::query()->orderBy('id')->get()
            ->filter(fn (Mobilier $m) => $m->boite === null || ($bestiaire?->contient($m->boite) ?? false));

        // L'élément-objectif d'une quête finale n'est PLUS du décor aléatoire
        // (René, 2026-10-09) : il se pose à coup sûr dans la salle du boss, ou
        // nulle part — le Haut Autel qui traînait dans n'importe quelle salle
        // d'une quête ordinaire du thème n'ouvrait sur rien.
        $element = $nomElementObjectif === null ? null : $disponible->firstWhere('nom', $nomElementObjectif);

        $catalogue = $disponible
            ->reject(fn (Mobilier $m) => in_array($m->nom, self::MOBILIER_POSE_EN_QUETE, true)
                || in_array($m->nom, MoteurMobilier::ELEMENT_OBJECTIF_FINAL, true))
            ->values();

        if ($catalogue->isEmpty() && $element === null) {
            return [];
        }

        // ⚠ Peut être `null` si le catalogue n'a jamais été seedé avec un
        // « Coffre » (ex. certains tests unitaires posent leur propre
        // mobilier factice) : on ne peut garantir un type que le catalogue ne
        // connaît pas — `registre testé dans les deux sens`, la garantie
        // n'invente rien.
        $coffre = $catalogue->firstWhere('nom', 'Coffre');

        $prng = new PrngLineaire($suivant());

        // Cases interdites à TOUT meuble, indépendamment de la salle : le seuil
        // (case côté salle de l'arête de porte — jamais la case de la porte
        // elle-même, qui n'existe pas, une porte étant une arête), la case
        // d'un levier, et la case d'un piège déjà posé — un meuble PLEIN par-
        // dessus le rendrait à la fois invisible (même calque de rendu) et à
        // jamais indéclenchable (plus aucun héros ne peut y marcher).
        $interdites = [];
        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $case) {
                $interdites["{$case['x']},{$case['y']}"] = true;
            }
        }
        foreach ($leviers as $levier) {
            $interdites["{$levier['x']},{$levier['y']}"] = true;
        }
        foreach ($pieges as $piege) {
            $interdites["{$piege['x']},{$piege['y']}"] = true;

            // Lame balançoire (Against the Ogre Horde) : sa ZONE entière,
            // pas seulement sa case de déclenchement — un meuble posé sur une
            // case de lame la rendrait invisible et indéclenchable, même
            // raison que la case du piège lui-même.
            foreach ((array) ($piege['zone'] ?? []) as $z) {
                $interdites[((int) $z['x']).','.((int) $z['y'])] = true;
            }
        }

        $mobilier = [];

        foreach ($salles as $i => $salle) {
            if ($i === 0) {
                continue; // salle de départ : le groupe y démarre empilé
            }

            $seuils = $this->seuilsDeSalle($salle, $portes);
            if ($seuils === []) {
                continue; // pas de porte identifiée pour cette salle (ne devrait pas arriver)
            }

            $interieur = $this->interieur($cases, $salle);
            $occupeesSalle = []; // cases déjà prises par un meuble déjà posé DANS cette salle

            // ÉLÉMENT-OBJECTIF (2026-10-09), posé AVANT tout autre meuble de la
            // salle du boss — la dernière, celle où `spawnsMonstres()` fait
            // atterrir le boss (même convention que `DeckFouille::salleDuBoss()`).
            // Même pose que le Coffre (`tenterPoseMobilier()` : emprise dans la
            // salle, jamais sur un seuil, salle connexe) et même plancher de
            // cases jouables (§2.12 ter) ; la pose tire au sort, on la retente
            // donc quelques fois — « à coup sûr » veut dire qu'on ne renonce
            // pas au premier tirage malheureux. Désigné par `objectif: true` :
            // c'est ce que lit `MoteurMobilier::elementObjectif()`.
            if ($element !== null && $i === count($salles) - 1) {
                for ($essai = 0; $essai < 8; $essai++) {
                    $place = $this->tenterPoseMobilier(
                        $catalogue->isEmpty() ? collect([$element]) : $catalogue,
                        $cases, $salle, $interieur, $seuils, $interdites, $occupeesSalle, $prng, $element,
                    );

                    if ($place === null
                        || count($interieur) - count($occupeesSalle) - count($place['cellules']) < self::CASES_JOUABLES_MINIMUM) {
                        continue;
                    }

                    foreach ($place['cellules'] as $cellule) {
                        $occupeesSalle["{$cellule['x']},{$cellule['y']}"] = true;
                    }

                    $mobilier[] = [
                        'mobilier_id' => $place['mobilier_id'],
                        'x' => $place['x'], 'y' => $place['y'], 'l' => $place['l'], 'h' => $place['h'],
                        'salle' => $i,
                        'objectif' => true,
                    ];
                    break;
                }
            }

            // GARANTIE de coffre (René, 2026-09-18), posée EN PREMIER — avant
            // le mobilier ordinaire tiré juste après, pas après lui. Posée en
            // second, la pose forcée pouvait échouer alors même que la salle
            // était assez grande : le tirage 0..3 ci-dessous consommait le
            // plancher de cases jouables à sa place (mesuré : une partie des
            // 11 renoncements sur 123 salles-au-coffre tenait à CET ordre, pas
            // à la taille de la salle — `garantirTaillesSallesACoffre()`
            // règle l'AUTRE partie, en amont de l'assemblage). Réserver la
            // case du Coffre D'ABORD, dans `$occupeesSalle`, revient au même
            // que réserver n'importe quel autre meuble déjà posé : le tirage
            // ordinaire qui suit respecte le MÊME plancher §2.12 ter sans rien
            // savoir de la raison de cette réservation — toujours les MÊMES
            // helpers (`tenterPoseMobilier()` + le plancher), jamais une
            // seconde copie de la règle.
            if ($coffre !== null && in_array($i, $sallesAGarantirUnCoffre, true)) {
                $place = $this->tenterPoseMobilier(
                    $catalogue, $cases, $salle, $interieur, $seuils, $interdites, $occupeesSalle, $prng, $coffre,
                );

                if ($place !== null
                    && count($interieur) - count($occupeesSalle) - count($place['cellules'])
                        >= self::CASES_JOUABLES_MINIMUM) {
                    foreach ($place['cellules'] as $cellule) {
                        $occupeesSalle["{$cellule['x']},{$cellule['y']}"] = true;
                    }

                    $mobilier[] = [
                        'mobilier_id' => $place['mobilier_id'],
                        'x' => $place['x'], 'y' => $place['y'], 'l' => $place['l'], 'h' => $place['h'],
                        'salle' => $i,
                    ];
                }
                // ⚠ Repli EXPLICITE, jamais silencieux : même en tête, la pose
                // peut échouer (pas de position valide, ou salle qui ne
                // repasserait pas le plancher) — cas désormais rarissime grâce
                // à `garantirTaillesSallesACoffre()`, mais toujours possible
                // pour un donjon pathologiquement petit. La salle reste sans
                // Coffre VISUEL plutôt que de casser la jouabilité ; la
                // récompense elle-même reste due (`DeckFouille::carteCoffre()`
                // ne regarde jamais si un meuble existe sur la carte).
            }

            // ⚠ Le tirage est TOUJOURS consommé, même si le plafond ci-dessous
            // le ramène à 0 : sauter le `suivant()` ferait diverger la suite
            // PRNG selon la taille de la salle, et deux donjons de même graine
            // cesseraient d'être identiques (même précaution que le tirage du
            // passage secret).
            // Catalogue vidé par l'écart de l'élément-objectif (base de test
            // minimale) : plus rien à tirer, et `% 0` serait une erreur.
            if ($catalogue->isEmpty()) {
                continue;
            }

            $cible = $prng->suivant() % 4; // 0..3, salles vides comprises

            for ($pose = 0; $pose < $cible; $pose++) {
                $place = $this->tenterPoseMobilier(
                    $catalogue, $cases, $salle, $interieur, $seuils, $interdites, $occupeesSalle, $prng,
                );

                if ($place === null) {
                    continue; // aucune position valide trouvée : la salle reste moins meublée
                }

                // §2.12 ter — la salle doit rester JOUABLE, pas seulement
                // connexe (René, 2026-09-12, après une partie à 4 : « les
                // salles étaient trop petites ou trop encombrées pour que tous
                // les joueurs puissent agir »). `salleResteConnexe()` ne dit
                // que « on peut encore circuler » : un boyau d'une case de
                // large le satisfait, et quatre héros n'y tiennent pas. La
                // densité, elle, était tirée SANS regarder l'aire — une salle
                // de 5 cases pouvait recevoir trois meubles.
                //
                // Le même arbitrage existait déjà pour les monstres depuis
                // §2.12 bis (`RESERVE_CASES_LIBRES`) et n'avait jamais été
                // appliqué aux meubles, qui mangent exactement les mêmes cases.
                if (count($interieur) - count($occupeesSalle) - count($place['cellules'])
                    < self::CASES_JOUABLES_MINIMUM) {
                    continue; // ce meuble-là rendrait la salle injouable : on y renonce
                }

                foreach ($place['cellules'] as $cellule) {
                    $occupeesSalle["{$cellule['x']},{$cellule['y']}"] = true;
                }

                $mobilier[] = [
                    'mobilier_id' => $place['mobilier_id'],
                    'x' => $place['x'], 'y' => $place['y'], 'l' => $place['l'], 'h' => $place['h'],
                    'salle' => $i,
                ];
            }
        }

        return $mobilier;
    }

    /**
     * Cases-seuils d'une salle : pour chaque porte de la carte, la case parmi
     * ses deux `casesPorte()` qui tombe dans le rectangle de cette salle. Sert
     * à la fois de garde-fou de placement (jamais un meuble dessus) et de
     * source pour la BFS de connexité (`salleResteConnexe()`).
     *
     * @param  array{x: int, y: int, largeur: int, hauteur: int}  $salle
     * @param  list<array{x: int, y: int, cote?: string}>  $portes
     * @return list<array{x: int, y: int}>
     */
    private function seuilsDeSalle(array $salle, array $portes): array
    {
        $seuils = [];

        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $case) {
                if ($case['x'] >= $salle['x'] && $case['x'] < $salle['x'] + $salle['largeur']
                    && $case['y'] >= $salle['y'] && $case['y'] < $salle['y'] + $salle['hauteur']) {
                    $seuils[] = $case;
                }
            }
        }

        return $seuils;
    }

    /**
     * Tente de poser UN meuble dans `$salle` : type + orientation + ancre
     * tirés au sort, un nombre borné de fois (une salle exiguë doit pouvoir
     * renoncer plutôt que boucler indéfiniment). Une tentative est retenue
     * seulement si son emprise tient entière dans la salle (jamais un pas
     * dans le couloir ni dans une salle voisine mitoyenne), ne chevauche ni
     * une case interdite ni un meuble déjà posé, ET si la salle reste
     * entièrement connexe une fois la case posée (`salleResteConnexe()`).
     *
     * @param  Collection<int, Mobilier>  $catalogue
     * @param  list<list<string>>  $cases
     * @param  array{x: int, y: int, largeur: int, hauteur: int}  $salle
     * @param  list<array{x: int, y: int}>  $interieur
     * @param  list<array{x: int, y: int}>  $seuils
     * @param  array<string, true>  $interdites
     * @param  array<string, true>  $occupeesSalle
     * @param  ?Mobilier  $typeImpose  quand fourni (garantie de coffre), le
     *                                type n'est plus tiré au sort — seuls
     *                                l'orientation et l'ancre restent
     *                                aléatoires. La géométrie, les
     *                                interdictions et `salleResteConnexe()`
     *                                restent EXACTEMENT les mêmes : c'est tout
     *                                l'intérêt de réutiliser cette méthode
     *                                plutôt que d'écrire une seconde pose.
     * @return array{mobilier_id: int, x: int, y: int, l: int, h: int, cellules: list<array{x: int, y: int}>}|null
     */
    private function tenterPoseMobilier(
        Collection $catalogue,
        array $cases,
        array $salle,
        array $interieur,
        array $seuils,
        array $interdites,
        array $occupeesSalle,
        PrngLineaire $prng,
        ?Mobilier $typeImpose = null,
    ): ?array {
        if ($interieur === []) {
            return null;
        }

        $grilleGeom = new Grille($cases); // cellulesEmprise() est une pure fonction de géométrie

        for ($tentative = 0; $tentative < 12; $tentative++) {
            // Type imposé (garantie de coffre) : aucun tirage à consommer ici,
            // le catalogue n'a rien à départager.
            $type = $typeImpose ?? $catalogue[$prng->suivant() % $catalogue->count()];
            // Orientation : une pièce 1×2 tient aussi bien couchée que debout —
            // aucune des deux sources (§1) ne fixe un sens canonique.
            [$l, $h] = $prng->suivant() % 2 === 0
                ? [(int) $type->largeur, (int) $type->hauteur]
                : [(int) $type->hauteur, (int) $type->largeur];

            $ancre = $interieur[$prng->suivant() % count($interieur)];
            $cellules = $grilleGeom->cellulesEmprise($ancre['x'], $ancre['y'], $l, $h);

            $tient = true;
            foreach ($cellules as $cellule) {
                $dansLaSalle = $cellule['x'] >= $salle['x'] && $cellule['x'] < $salle['x'] + $salle['largeur']
                    && $cellule['y'] >= $salle['y'] && $cellule['y'] < $salle['y'] + $salle['hauteur'];
                $cle = "{$cellule['x']},{$cellule['y']}";

                if (! $dansLaSalle
                    || ($cases[$cellule['y']][$cellule['x']] ?? 'm') !== 's'
                    || isset($interdites[$cle])
                    || isset($occupeesSalle[$cle])
                ) {
                    $tient = false;
                    break;
                }
            }

            if (! $tient) {
                continue;
            }

            if ($type->adosse_au_mur && ! $this->adosseAuMur($cases, $cellules, $l, $h)) {
                continue; // meuble mural planté au milieu de la pièce : renoncer.
            }

            $occupeesApres = $occupeesSalle;
            foreach ($cellules as $cellule) {
                $occupeesApres["{$cellule['x']},{$cellule['y']}"] = true;
            }

            if (! $this->salleResteConnexe($interieur, $seuils, $occupeesApres)) {
                continue; // ce placement isolerait une case : renoncer, PAS redimensionner
            }

            return [
                'mobilier_id' => (int) $type->id,
                'x' => $ancre['x'], 'y' => $ancre['y'], 'l' => $l, 'h' => $h,
                'cellules' => $cellules,
            ];
        }

        return null;
    }

    /**
     * Ce meuble est-il ADOSSÉ — dos au mur, grand axe LE LONG du mur ?
     *
     * Le placement tirait jusqu'ici une case au hasard et une orientation à
     * pile ou face, sans jamais regarder les murs. Ça tombait souvent juste par
     * accident (les salles sont petites, la plupart des cases touchent un mur),
     * mais mesuré sur douze donjons réels : une bibliothèque suivait le mur
     * QUATRE FOIS SUR DIX, une armoire une fois sur six. Le reste du temps elle
     * dépassait perpendiculairement, comme une étagère plantée au milieu de la
     * pièce, ou flottait franchement (question de René, 2026-08-21).
     *
     * ⚠ La règle se lit dans `adosse_au_mur`, et SURTOUT PAS dans `bloque_vue`.
     * Les trois meubles hauts du catalogue sont justement les trois à adosser,
     * et « c'est haut donc ça occulte ET ça s'appuie » se tient — mais c'est
     * faux en général, et René l'a signalé avant que ça ne morde : un PILIER
     * bloque la ligne de vue et se dresse au MILIEU d'une salle. Déduire l'un
     * de l'autre l'aurait collé au mur, ou pire, aurait refusé de le poser là
     * où il a du sens. Même histoire que `bloque_mouvement`/`bloque_vue`,
     * scindés pour cette raison exacte : on ne conflate pas deux faits
     * indépendants dans une colonne.
     *
     * Un meuble allongé exige un mur le long de son GRAND axe : un mur au bout
     * d'une bibliothèque ne l'adosse pas, il la coince. Un meuble carré se
     * contente de n'importe quel mur adjacent.
     *
     * @param  list<list<string>>  $cases
     * @param  list<array{x: int, y: int}>  $cellules
     */
    private function adosseAuMur(array $cases, array $cellules, int $l, int $h): bool
    {
        // Côtés à tester : ceux qui longent le grand axe. Une pièce couchée
        // (l > h) s'adosse en haut ou en bas ; debout (h > l), à gauche ou à
        // droite ; carrée, n'importe où.
        $cotes = match (true) {
            $l > $h => [[0, -1], [0, 1]],
            $h > $l => [[-1, 0], [1, 0]],
            default => [[0, -1], [0, 1], [-1, 0], [1, 0]],
        };

        foreach ($cellules as $cellule) {
            foreach ($cotes as [$ox, $oy]) {
                if (($cases[$cellule['y'] + $oy][$cellule['x'] + $ox] ?? 'm') === 'm') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * La salle reste-t-elle entièrement parcourable une fois `$occupees`
     * retirées de son intérieur ? BFS depuis UN SEUL seuil : toute case de
     * sol non occupée — AUTRES SEUILS COMPRIS — doit être atteinte.
     *
     * Piège évité ici (trouvé en probant le placement sur ~300 graines,
     * jamais sur simple relecture) : une BFS **multi-sources** partie de tous
     * les seuils à la fois marque chaque seuil « atteint » du seul fait
     * d'être sa propre source — y compris un seuil qu'un meuble venait
     * d'enfermer dans une poche de 2 cases sans aucune autre issue. Deux
     * héros pouvaient alors se tenir sur ce seuil, s'y voir mutuellement, et
     * ne plus jamais rejoindre le reste de la salle : le mobilier avait
     * NEUTRALISÉ une porte qu'il ne recouvrait pourtant jamais. Repartir d'un
     * seul point force la BFS à PROUVER qu'elle atteint chaque autre seuil en
     * traversant de la vraie surface au sol, au lieu de le supposer.
     *
     * @param  list<array{x: int, y: int}>  $interieur
     * @param  list<array{x: int, y: int}>  $seuils
     * @param  array<string, true>  $occupees
     */
    private function salleResteConnexe(array $interieur, array $seuils, array $occupees): bool
    {
        $libres = [];
        foreach ($interieur as $case) {
            $cle = "{$case['x']},{$case['y']}";
            if (! isset($occupees[$cle])) {
                $libres[$cle] = $case;
            }
        }

        if ($libres === []) {
            return true;
        }

        // Un seuil est JAMAIS occupé (protégé par `$interdites` en amont) :
        // le premier de la liste est donc toujours un départ valide.
        $depart = $seuils[0] ?? null;
        if ($depart === null || ! isset($libres["{$depart['x']},{$depart['y']}"])) {
            return false;
        }

        $vus = ["{$depart['x']},{$depart['y']}" => true];
        $file = [$depart];

        while ($file !== []) {
            $case = array_pop($file);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $case['x'] + $dx;
                $ny = $case['y'] + $dy;
                $cle = "{$nx},{$ny}";
                if (! isset($libres[$cle]) || isset($vus[$cle])) {
                    continue;
                }
                $vus[$cle] = true;
                $file[] = ['x' => $nx, 'y' => $ny];
            }
        }

        return count($vus) === count($libres);
    }

    /**
     * TERRAIN (doc 18 §4, The Frozen Horror, phase 4a) — cinquième couche, même
     * patron que `placerMobilier()`/`placerEpreuves()` : AUCUNE case 'm'/'s' ne
     * change, seul `FabriqueGrille` lit cette liste. Une case de terrain
     * répond à « que COÛTE cette case, et que se passe-t-il quand on la
     * traverse ou qu'on y reste ? » — la question qu'aucune des quatre couches
     * précédentes ne pose.
     *
     * ⚠ Jamais dans la salle 0 (même raison que pièges/mobilier/épreuves : une
     * case dangereuse sous le groupe au tour 1 se subit, elle ne se joue pas),
     * jamais sur un seuil, un levier, un piège, un meuble ou une épreuve déjà
     * posés — une case qui porterait deux couches à la fois serait, au choix,
     * invisible ou incohérente (quel effet gagne ?).
     *
     * ⚠ INVARIANT DUR, comme le mobilier : une case qui BLOQUE LE MOUVEMENT ne
     * doit JAMAIS isoler une autre case par ailleurs atteignable. Même BFS
     * depuis un seul seuil que `salleResteConnexe()` (voir
     * `terrainCasseraitConnexite()` juste en dessous), la pose ABANDONNÉE
     * plutôt que forcée — et la vérification tient compte du mobilier
     * BLOQUANT déjà posé dans la même salle : la combinaison des deux couches
     * pourrait isoler une case qu'aucune des deux, seule, n'aurait isolée.
     * Aucun des 7 terrains sourcés à ce jour ne bloque le mouvement (ce sont
     * des dangers de sol, pas des murs), mais le mécanisme doit tenir pour le
     * prochain qui le fera (le Mur de Glace, sort du boss — hors périmètre de
     * cette phase) : c'est tout l'intérêt de le VÉRIFIER plutôt que de le
     * supposer, exactement la leçon de `salleResteConnexe()`.
     *
     * ⚠ Les TUNNELS DE GLACE se posent PAR PAIRES (téléportation) : les deux
     * extrémités ou aucune — un tunnel à sens unique n'en serait pas un. Les
     * deux moitiés d'une paire partagent un `paire_id` propre à cette carte.
     *
     * ⚠ Comptage gouverné par `structure.terrains` (même format que
     * `structure.pieges`/`structure.epreuves` : `min`/`max`) pour les terrains
     * ORDINAIRES, et `structure.terrains.tunnels` (`min`/`max` de PAIRES)
     * séparément pour les tunnels. Un gabarit qui ne déclare NI L'UN NI
     * L'AUTRE ne pose AUCUN terrain (défaut 0 partout) — la leçon des
     * leviers : une couche qui fonctionne mais qu'aucun gabarit n'alimente
     * encore équivaut à une couche absente, à la différence près qu'elle
     * n'abîme rien en silence. `GabaritQueteSeeder` déclare désormais
     * `structure.terrains` sur les trois gabarits (2026-09-06, phase 6a).
     *
     * ⚠ Le COMPTAGE (le gabarit) et le CHOIX (le thème, `$theme`) sont deux
     * questions séparées, exactement comme pour le mobilier vs les épreuves :
     * le gabarit dit COMBIEN, le thème dit LESQUELS. Les 7 terrains sourcés
     * sont tous `boite = horreur_des_glaces`, une boîte volontairement absente
     * de `DemarreurQuete::BOITES_THEMATIQUES` (règles du Yéti/Gremlin/3 sorts
     * incomplètes) : la couche reste donc INERTE en jeu réel — `min`/`max` a
     * beau demander des cases, le filtre par thème n'en laisse jamais passer
     * une seule — jusqu'à ce qu'une phase ultérieure rallume la boîte. C'est
     * assumé, pas un bug : le même sort que le boss et les créatures de la
     * boîte de glace, déjà seedés et déjà inertes pour la même raison.
     *
     * @param  array<string, mixed>  $structure
     * @param  list<list<string>>  $cases
     * @param  array<int, array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     * @param  list<array{x: int, y: int, cote?: string}>  $portes
     * @param  list<array{x: int, y: int, levier_id: string}>  $leviers
     * @param  list<array{x: int, y: int}>  $pieges
     * @param  list<array{mobilier_id: int, x: int, y: int, l: int, h: int, salle: int}>  $mobilier
     * @param  list<array{x: int, y: int, epreuve_id: int, salle: int, tentee_par: list<int>}>  $epreuves
     * @param  ?BestiaireGroupe  $bestiaire  bestiaire du groupe (`BestiaireGroupe`,
     *                          auto ou manuel — 2026-09-28 ; avant : la boîte
     *                          FIGÉE `groupes.theme_bestiaire` seule)
     *                          — filtre le catalogue AVANT tout tirage :
     *                          seuls les terrains dont `boite` vaut `null`
     *                          (« convient à tout thème », même lecture que
     *                          `monstres.boite`) OU un thème de la campagne restent
     *                          candidats. `null` ici (thème inconnu) ne garde
     *                          que les terrains `boite = null` — fail open,
     *                          jamais d'erreur. C'est ce qui empêche une
     *                          Rivière gelée d'apparaître dans une quête de
     *                          jungle : les 7 terrains sourcés sont TOUS
     *                          `boite = horreur_des_glaces`, si bien qu'à ce
     *                          jour — cette boîte n'étant pas dans
     *                          `DemarreurQuete::BOITES_THEMATIQUES` — aucun ne
     *                          se pose jamais en jeu réel. Assumé : la couche
     *                          reste inerte tant que la boîte de glace n'est
     *                          pas rallumée, exactement comme son boss.
     * @return list<array{x: int, y: int, terrain_id: int, paire_id?: string}>
     */
    private function placerTerrains(
        array $structure,
        array $cases,
        array $salles,
        array $portes,
        array $leviers,
        array $pieges,
        array $mobilier,
        array $epreuves,
        \Closure $suivant,
        ?BestiaireGroupe $bestiaire = null,
    ): array {
        // Un terrain « boîté » ne se pose que si sa boîte est un THÈME de la
        // campagne (tirée en auto, cochée en manuel) ; `null` partout.
        $catalogue = Terrain::query()->orderBy('id')->get()
            ->filter(fn (Terrain $t) => $t->boite === null || ($bestiaire?->contient($t->boite) ?? false))
            ->values();

        if ($catalogue->isEmpty()) {
            return [];
        }

        $prng = new PrngLineaire($suivant());

        // Cases interdites à TOUT terrain : seuils, leviers, pièges, mobilier,
        // épreuves — même liste que celle bâtie pour les épreuves, une couche
        // plus loin.
        $interdites = [];
        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $case) {
                $interdites["{$case['x']},{$case['y']}"] = true;
            }
        }
        foreach ($leviers as $levier) {
            $interdites["{$levier['x']},{$levier['y']}"] = true;
        }
        foreach ($pieges as $piege) {
            $interdites["{$piege['x']},{$piege['y']}"] = true;

            foreach ((array) ($piege['zone'] ?? []) as $z) {
                $interdites[((int) $z['x']).','.((int) $z['y'])] = true;
            }
        }
        foreach ($mobilier as $meuble) {
            for ($dx = 0; $dx < (int) ($meuble['l'] ?? 1); $dx++) {
                for ($dy = 0; $dy < (int) ($meuble['h'] ?? 1); $dy++) {
                    $interdites[((int) $meuble['x'] + $dx).','.((int) $meuble['y'] + $dy)] = true;
                }
            }
        }
        foreach ($epreuves as $epreuve) {
            $interdites["{$epreuve['x']},{$epreuve['y']}"] = true;
        }

        // Par salle (jamais la 0) : l'INTÉRIEUR COMPLET (pour la BFS de
        // connexité, comme `placerMobilier()`), les cases LIBRES (candidates
        // réelles, interdictions retirées) et les SEUILS (départs de BFS).
        $parSalle = [];
        foreach ($salles as $i => $salle) {
            if ($i === 0) {
                continue; // salle de départ : jamais de terrain sous le groupe au tour 1
            }

            $interieurSalle = $this->interieur($cases, $salle);
            $libres = array_values(array_filter(
                $interieurSalle,
                fn (array $p) => ! isset($interdites["{$p['x']},{$p['y']}"]),
            ));

            if ($libres === []) {
                continue;
            }

            $parSalle[$i] = [
                'interieur' => $interieurSalle,
                'libres' => $libres,
                'seuils' => $this->seuilsDeSalle($salle, $portes),
            ];
        }

        if ($parSalle === []) {
            return [];
        }

        // Mobilier BLOQUANT déjà posé, PAR SALLE : point de départ de
        // `$bloquantesParSalle` ci-dessous — la connexité doit tenir compte de
        // la couche précédente, pas seulement du terrain qu'on est en train
        // de poser. Deux couches chacune inoffensive prises seule pourraient
        // isoler une case ENSEMBLE.
        $idsMobilierBloquant = Mobilier::query()->where('bloque_mouvement', true)->pluck('id')->all();
        $bloquantesParSalle = [];
        foreach ($mobilier as $meuble) {
            if (! in_array($meuble['mobilier_id'] ?? null, $idsMobilierBloquant, true)) {
                continue;
            }
            $i = (int) ($meuble['salle'] ?? -1);
            for ($dx = 0; $dx < (int) ($meuble['l'] ?? 1); $dx++) {
                for ($dy = 0; $dy < (int) ($meuble['h'] ?? 1); $dy++) {
                    $bloquantesParSalle[$i][((int) $meuble['x'] + $dx).','.((int) $meuble['y'] + $dy)] = true;
                }
            }
        }

        $terrains = [];
        $occupeesGlobal = []; // toute case déjà donnée à un terrain (bloquant ou non), toutes salles confondues

        $tunnel = $catalogue->firstWhere('nom', self::NOM_TERRAIN_TUNNEL);
        $ordinaires = $catalogue->reject(fn (Terrain $t) => $tunnel !== null && $t->is($tunnel))->values();

        $min = (int) data_get($structure, 'terrains.min', 0);
        $max = max($min, (int) data_get($structure, 'terrains.max', $min));
        $voulues = $prng->entre($min, $max);

        if ($voulues > 0 && $ordinaires->isNotEmpty()) {
            // Pool global mélangé de tous les couples (salle, case) — même
            // logique que `placerPieges()` : garder les salles en tête
            // biaiserait la distribution vers les premières salles de l'arbre.
            $pool = [];
            foreach ($parSalle as $i => $entree) {
                foreach ($entree['libres'] as $position) {
                    $pool[] = [...$position, 'salle' => $i];
                }
            }
            $pool = $prng->melanger($pool);

            foreach ($pool as $candidate) {
                if (count($terrains) >= $voulues) {
                    break;
                }

                $cle = "{$candidate['x']},{$candidate['y']}";
                if (isset($occupeesGlobal[$cle])) {
                    continue;
                }

                $type = $ordinaires[$prng->suivant() % $ordinaires->count()];
                $i = $candidate['salle'];

                if ($this->terrainCasseraitConnexite($type, $parSalle[$i], $cle, $bloquantesParSalle[$i] ?? [])) {
                    continue; // cette pose isolerait une case : abandon, PAS de repli
                }

                if ($type->bloque_mouvement) {
                    $bloquantesParSalle[$i][$cle] = true;
                }

                $terrains[] = ['x' => $candidate['x'], 'y' => $candidate['y'], 'terrain_id' => (int) $type->id];
                $occupeesGlobal[$cle] = true;
            }
        }

        // Tunnels de glace : posés PAR PAIRES, jamais un seul — une paire peut
        // relier deux salles différentes (c'est même l'intérêt du tunnel).
        if ($tunnel !== null) {
            $minPaires = (int) data_get($structure, 'terrains.tunnels.min', 0);
            $maxPaires = max($minPaires, (int) data_get($structure, 'terrains.tunnels.max', $minPaires));
            $vouluesPaires = $prng->entre($minPaires, $maxPaires);

            $poolTunnel = [];
            foreach ($parSalle as $i => $entree) {
                foreach ($entree['libres'] as $position) {
                    $cle = "{$position['x']},{$position['y']}";
                    if (! isset($occupeesGlobal[$cle])) {
                        $poolTunnel[] = [...$position, 'salle' => $i];
                    }
                }
            }
            $poolTunnel = $prng->melanger($poolTunnel);

            for ($p = 0; $p < $vouluesPaires && count($poolTunnel) >= 2; $p++) {
                $a = array_shift($poolTunnel);
                $b = array_shift($poolTunnel);
                $cleA = "{$a['x']},{$a['y']}";
                $cleB = "{$b['x']},{$b['y']}";

                // Le tunnel ne bloque ni mouvement ni vue (catalogue) : rien à
                // revérifier ici, exactement comme les terrains non bloquants
                // ci-dessus — le mécanisme de connexité reste néanmoins
                // disponible pour le jour où ça changerait (aucun aujourd'hui).
                if ($this->terrainCasseraitConnexite($tunnel, $parSalle[$a['salle']], $cleA, $bloquantesParSalle[$a['salle']] ?? [])
                    || $this->terrainCasseraitConnexite($tunnel, $parSalle[$b['salle']], $cleB, $bloquantesParSalle[$b['salle']] ?? [])
                ) {
                    continue; // l'une des deux extrémités casserait la connexité : ni l'une ni l'autre
                }

                $paireId = 'tunnel-'.($p + 1);
                $terrains[] = ['x' => $a['x'], 'y' => $a['y'], 'terrain_id' => (int) $tunnel->id, 'paire_id' => $paireId];
                $terrains[] = ['x' => $b['x'], 'y' => $b['y'], 'terrain_id' => (int) $tunnel->id, 'paire_id' => $paireId];
                $occupeesGlobal[$cleA] = true;
                $occupeesGlobal[$cleB] = true;
            }
        }

        return $terrains;
    }

    /**
     * Poser `$type` sur `$cle` (déjà "x,y") casserait-il la connexité de la
     * salle ? Réutilise `salleResteConnexe()` — même BFS depuis un seul seuil
     * que le mobilier, jamais multi-sources (cf. son commentaire : un seuil
     * enfermé dans une poche se compterait « atteint » du seul fait d'être sa
     * propre source).
     *
     * Un terrain qui ne bloque pas le mouvement (`bloque_mouvement: false`) ne
     * peut par construction rien isoler — retour immédiat, pas de BFS pour
     * rien. Sans seuil identifié pour cette salle, refuse par prudence : on ne
     * peut pas PROUVER la connexité sans point de départ.
     *
     * @param  array{interieur: list<array{x: int, y: int}>, libres: list<array{x: int, y: int}>, seuils: list<array{x: int, y: int}>}  $entreeSalle
     * @param  array<string, true>  $bloquantesAvant  cases déjà bloquantes dans CETTE salle (terrain déjà posé + mobilier bloquant)
     */
    private function terrainCasseraitConnexite(Terrain $type, array $entreeSalle, string $cle, array $bloquantesAvant): bool
    {
        if (! $type->bloque_mouvement) {
            return false;
        }

        if ($entreeSalle['seuils'] === []) {
            return true;
        }

        $occupeesApres = $bloquantesAvant;
        $occupeesApres[$cle] = true;

        return ! $this->salleResteConnexe($entreeSalle['interieur'], $entreeSalle['seuils'], $occupeesApres);
    }

    /**
     * Cases de sol intérieures d'une salle (ordre ligne par ligne).
     *
     * @param  list<list<string>>  $cases
     * @param  array{x: int, y: int, largeur: int, hauteur: int}  $salle
     * @return list<array{x: int, y: int}>
     */
    private function interieur(array $cases, array $salle): array
    {
        $positions = [];

        for ($r = 0; $r < $salle['hauteur']; $r++) {
            for ($c = 0; $c < $salle['largeur']; $c++) {
                if ($cases[$salle['y'] + $r][$salle['x'] + $c] === 's') {
                    $positions[] = ['x' => $salle['x'] + $c, 'y' => $salle['y'] + $r];
                }
            }
        }

        return $positions;
    }

    /**
     * Remonte en première position la tuile au plus grand intérieur — celle qui
     * deviendra la salle de départ (§2.12). Tri stable : à surface égale,
     * l'ordre tiré au sort est conservé, donc la carte reste déterministe.
     *
     * @param  list<Tuile>  $tuiles
     * @return list<Tuile>
     */
    private function plusGrandeTuileEnTete(array $tuiles): array
    {
        if (count($tuiles) < 2) {
            return $tuiles;
        }

        $surface = fn (Tuile $t): int => ((int) $t->grille['largeur'] - 2) * ((int) $t->grille['hauteur'] - 2);

        // La DERNIÈRE tuile est la rencontre finale (salle « boss ») : elle doit
        // le rester, `spawn_monstres[0]` y étant posé côté DemarreurQuete. On ne
        // cherche donc la plus grande que parmi les autres.
        $dernier = count($tuiles) - 1;

        $meilleure = 0;
        for ($i = 1; $i < $dernier; $i++) {
            if ($surface($tuiles[$i]) > $surface($tuiles[$meilleure])) {
                $meilleure = $i;
            }
        }

        if ($meilleure !== 0) {
            [$tuiles[0], $tuiles[$meilleure]] = [$tuiles[$meilleure], $tuiles[0]];
        }

        return $tuiles;
    }

    /**
     * ESCALIER D'ENTRÉE (chantier escalier-entrée, 2026-10-05, René : « il
     * faudrait ajouter un escalier ou une porte d'entrée pour chaque quête et
     * ça clarifierait la réussite de la mission d'extraction »). Le repère du
     * plateau d'origine : la quête COMMENCE et FINIT à l'escalier.
     *
     * **2×2 comme sur le plateau**, mais TRAVERSABLE — on s'y tient, donc il
     * ne retire AUCUNE case libre à la salle (« connecté n'est pas jouable »
     * tenu PAR CONSTRUCTION : aucune case n'est perdue, contrairement au
     * mobilier ou au terrain). Toujours dans la salle de DÉPART (salle 0),
     * toujours visible (elle est tenue pour découverte dès le départ,
     * `Quete::sallesDecouvertes()`).
     *
     * Choisi comme un bloc 2×2 de sol intérieur, hors case de porte (même
     * filtre que `spawnsHeros()` ci-dessous), le plus PROCHE du centre de la
     * salle (`mediane_x`/`mediane_y`) — à égalité, l'ordre de balayage
     * décide, donc déterministe SANS consommer le PRNG : contrairement au
     * passage secret ou au terrain, sa position ne dépend d'aucun tirage,
     * seulement de la géométrie de la salle (qui ne change jamais pour une
     * même tuile).
     *
     * `null` si la salle ne contient aucun bloc 2×2 valide — garde défensive,
     * jamais atteinte avec le plancher actuel des tuiles (2×3 minimum depuis
     * le 2026-09-12), qui en contient toujours un. Les lecteurs
     * (`Carte::casesEscalier()`, `MenuMoteur`, `Quete::captifLibereEtVivant()`)
     * traitent `null` EXACTEMENT comme une carte assemblée avant ce
     * chantier : repli sur le comportement d'avant (sortie possible n'importe
     * où, mission « secourir » accomplie dès la libération) — jamais une
     * quête en cours rendue impossible à terminer.
     *
     * @param  list<list<string>>  $cases
     * @param  array{x: int, y: int, largeur: int, hauteur: int, mediane_x: int, mediane_y: int}  $salle
     * @param  list<array{x: int, y: int, cote?: string}>  $portes
     * @return array{x: int, y: int, l: int, h: int}|null
     */
    private function placerEscalier(array $cases, array $salle, array $portes): ?array
    {
        $interieur = $this->interieur($cases, $salle);

        $casesPorte = [];
        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $c) {
                $casesPorte["{$c['x']},{$c['y']}"] = true;
            }
        }

        $libres = [];
        foreach ($interieur as $p) {
            if (! isset($casesPorte["{$p['x']},{$p['y']}"])) {
                $libres["{$p['x']},{$p['y']}"] = true;
            }
        }

        $meilleur = null;
        $meilleureDistance = null;

        foreach ($interieur as $p) {
            $x = (int) $p['x'];
            $y = (int) $p['y'];

            if (! isset($libres["{$x},{$y}"], $libres[($x + 1).",{$y}"], $libres["{$x},".($y + 1)], $libres[($x + 1).','.($y + 1)])) {
                continue;
            }

            $dx = $x - (int) $salle['mediane_x'];
            $dy = $y - (int) $salle['mediane_y'];
            $distance = $dx * $dx + $dy * $dy;

            if ($meilleureDistance === null || $distance < $meilleureDistance) {
                $meilleur = ['x' => $x, 'y' => $y, 'l' => 2, 'h' => 2];
                $meilleureDistance = $distance;
            }
        }

        return $meilleur;
    }

    /**
     * Le groupe DÉMARRE sur l'escalier (René, 2026-10-05 : « faire commencer les
     * joueurs sur l'escalier, ou adjacent si plus que 4 joueurs/alliés »).
     *
     * Les places sont attribuées dans l'ordre de la liste (héros, puis alliés —
     * `DemarreurQuete`) : on met donc les quatre cases de l'escalier en tête, puis
     * le reste des spawns de la salle, du plus PROCHE de l'escalier au plus loin
     * (distance de Tchebychev — une case en diagonale du bloc est « adjacente »).
     * Tri stable : à distance égale, l'ordre de `spawnsHeros()` (cases les mieux
     * connectées d'abord) départage.
     *
     * ⚠ Cela annule l'ESPACEMENT de `spawnsHeros()` pour les quatre premiers :
     * il évitait qu'un héros soit encerclé par ses compagnons au tour 1 (verdict
     * §2.12). Ce risque a disparu depuis qu'un héros TRAVERSE la case d'un autre
     * (« on peut traverser la case d'un autre héros », `FabriqueGrille::pour()`,
     * `franchitAllies`) : il lui faut une case d'arrivée libre, plus un couloir.
     *
     * Sans escalier (repli défensif de `placerEscalier()`), la liste est rendue
     * telle quelle.
     *
     * @param  list<array{x: int, y: int}>  $spawns
     * @param  array{x: int, y: int, l: int, h: int}|null  $escalier
     * @return list<array{x: int, y: int}>
     */
    private function spawnsDepuisEscalier(array $spawns, ?array $escalier): array
    {
        if ($escalier === null) {
            return $spawns;
        }

        $marches = [];
        for ($dy = 0; $dy < $escalier['h']; $dy++) {
            for ($dx = 0; $dx < $escalier['l']; $dx++) {
                $marches[] = ['x' => $escalier['x'] + $dx, 'y' => $escalier['y'] + $dy];
            }
        }

        $surMarche = fn (array $p) => $p['x'] >= $escalier['x'] && $p['x'] < $escalier['x'] + $escalier['l']
            && $p['y'] >= $escalier['y'] && $p['y'] < $escalier['y'] + $escalier['h'];

        $distance = fn (array $p) => max(
            max(0, $escalier['x'] - $p['x'], $p['x'] - ($escalier['x'] + $escalier['l'] - 1)),
            max(0, $escalier['y'] - $p['y'], $p['y'] - ($escalier['y'] + $escalier['h'] - 1)),
        );

        // Les marches dans l'ordre de `spawnsHeros()` — la mieux connectée
        // d'abord, pour que le PREMIER héros garde le plus d'issues (même
        // raison que §2.12) ; toute marche absente des spawns (case de porte,
        // impossible par construction de `placerEscalier()`) ferme la liste.
        $tete = [];
        $reste = [];
        foreach (array_values($spawns) as $i => $p) {
            $p = ['x' => (int) $p['x'], 'y' => (int) $p['y']];

            if ($surMarche($p)) {
                $tete[] = $p;
            } else {
                $reste[] = ['p' => $p, 'd' => $distance($p), 'i' => $i];
            }
        }

        foreach ($marches as $m) {
            if (! in_array($m, $tete, true)) {
                $tete[] = $m;
            }
        }

        usort($reste, fn (array $a, array $b) => [$a['d'], $a['i']] <=> [$b['d'], $b['i']]);

        return [...$tete, ...array_column($reste, 'p')];
    }

    /**
     * Spawns des HÉROS dans la salle de départ, corrigés d'après le verdict §2.12.
     *
     * `interieur()` rend les cases en balayage ligne par ligne : les héros se
     * retrouvaient donc ALIGNÉS sur la rangée du haut, et `spawn_heros[0]` — un
     * COIN, dont les deux seuls voisins intérieurs sont `spawn[1]` et `spawn[3]` —
     * se retrouvait enfermé. Les places étant attribuées dans l'ordre
     * d'initiative, le PREMIER joueur à jouer perdait tout son déplacement du
     * tour 1, systématiquement, dès que la salle de départ était petite
     * (rappel : une salle « 5×5 » n'a que 3×3 = 9 cases utiles, son contour
     * étant du mur).
     *
     * On classe donc les cases de la plus connectée à la moins connectée : le
     * premier héros occupe le centre, qui garde toujours une issue.
     *
     * On exclut par ailleurs les cases de PORTE : un héros y démarrait dans
     * l'encadrement, bouchant la ligne de vue de tout le groupe.
     *
     * @param  list<list<string>>  $cases
     * @param  array{x: int, y: int, largeur: int, hauteur: int}  $salle
     * @param  list<array{x: int, y: int, cote?: string}>  $portes
     * @return list<array{x: int, y: int}>
     */
    private function spawnsHeros(array $cases, array $salle, array $portes): array
    {
        $interieur = $this->interieur($cases, $salle);

        $casesPorte = [];
        foreach ($portes as $porte) {
            foreach (Grille::casesPorte($porte) as $c) {
                $casesPorte["{$c['x']},{$c['y']}"] = true;
            }
        }

        $libres = array_values(array_filter(
            $interieur,
            fn (array $p) => ! isset($casesPorte["{$p['x']},{$p['y']}"]),
        ));

        // Sécurité : si tout l'intérieur est constitué de cases de porte
        // (salle minuscule), on retombe sur l'intérieur brut plutôt que de
        // rendre une liste vide — un groupe sans place où apparaître serait pire.
        if ($libres === []) {
            $libres = $interieur;
        }

        $dansSalle = [];
        foreach ($libres as $p) {
            $dansSalle["{$p['x']},{$p['y']}"] = true;
        }

        // Tri stable : plus grand nombre de voisins orthogonaux d'abord.
        $voisins = function (array $p) use ($dansSalle): int {
            $n = 0;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                if (isset($dansSalle[($p['x'] + $dx).','.($p['y'] + $dy)])) {
                    $n++;
                }
            }

            return $n;
        };

        $indexe = [];
        foreach ($libres as $i => $p) {
            $indexe[] = ['p' => $p, 'i' => $i, 'v' => $voisins($p)];
        }

        // Les mieux connectées d'abord (à égalité : ordre de balayage, donc
        // déterminisme conservé).
        usort($indexe, fn (array $a, array $b) => [$b['v'], $a['i']] <=> [$a['v'], $b['i']]);

        // Puis on ESPACE : premier passage sur les cases qui ne touchent aucune
        // case déjà retenue. Deux héros n'étant jamais côte à côte, aucun ne
        // peut se retrouver encerclé par ses propres alliés — c'est précisément
        // ce qui immobilisait le premier joueur au tour 1. Le second passage
        // ajoute le reste (groupes nombreux / salles exiguës).
        $pris = [];
        $espaces = [];
        $reste = [];

        foreach ($indexe as $e) {
            $p = $e['p'];
            $colle = false;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                if (isset($pris[($p['x'] + $dx).','.($p['y'] + $dy)])) {
                    $colle = true;
                    break;
                }
            }

            if ($colle) {
                $reste[] = $e;

                continue;
            }

            $pris["{$p['x']},{$p['y']}"] = true;
            $espaces[] = $e;
        }

        return array_map(fn (array $e) => $e['p'], [...$espaces, ...$reste]);
    }

    /**
     * Spawns de monstres : ROUND-ROBIN sur les salles 1..n-1, en commençant
     * par la DERNIÈRE (la rencontre finale, posée en dernier dans l'arbre —
     * donc toujours une feuille) pour que `spawn_monstres[0]` (le boss côté
     * DemarreurQuete) y atterrisse ; les suivantes en rotation entre les
     * autres salles, pour RÉPARTIR les monstres (fini « tous dans la
     * dernière pièce »). Jamais dans la salle des héros (salle 0).
     *
     * @param  list<list<string>>  $cases
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int, theme: string}>  $salles
     * @return list<array{x: int, y: int}>
     */
    /**
     * Cases laissées LIBRES dans chaque salle peuplée (§2.12 bis) : de quoi
     * faire entrer le groupe et le déployer, au lieu d'un mur de monstres.
     */
    private const RESERVE_CASES_LIBRES = 4;

    /**
     * Cases de sol qu'une salle doit conserver LIBRES de tout meuble : les
     * quatre du groupe au complet (`RESERVE_CASES_LIBRES`) plus deux pour ce
     * qu'il vient y combattre. En dessous, la salle est « connexe » et
     * pourtant injouable — la moitié du groupe reste sur le seuil sans pouvoir
     * agir, ce qui s'est vu en partie réelle le 2026-09-12.
     *
     * ⚠ Ce n'est PAS un plafond sur le nombre de meubles : c'est un plancher
     * sur les cases restantes, parce qu'un meuble occupe 1 ou 2 cases selon
     * son emprise — compter les meubles laisserait passer deux emprises de 2
     * là où trois emprises de 1 seraient refusées.
     */
    private const CASES_JOUABLES_MINIMUM = self::RESERVE_CASES_LIBRES + 2;

    /**
     * Mobilier POSÉ PAR UN SORT EN COURS DE QUÊTE
     * (`MoteurMobilier::poserMurMagique()`), jamais par ce générateur — Mur de
     * Pierre (Wall of Stone, sort de héros, 2026-10-06), et demain Mur de
     * Glace/Mur de Feu (sorts de Sorcier du Dread, vague 2). Sans cette
     * exclusion, le tirage uniforme de `placerMobilier()` les dresserait
     * comme n'importe quel coffre ou table dès que le thème
     * `wizards_of_morcar` est actif — un mur magique SANS lanceur, planté là
     * depuis la génération, ce que ni la carte ni le livret ne décrivent.
     */
    private const MOBILIER_POSE_EN_QUETE = MoteurMobilier::MURS_MAGIQUES;

    /**
     * Cases de sol qu'une salle doit offrir pour pouvoir porter un `Coffre`
     * RÉEL (1×1, `MobilierSeeder`) sans repasser sous `CASES_JOUABLES_MINIMUM`
     * une fois posé (René, 2026-09-18 : « pour les salles coffres, on
     * pourrait limiter les grosseurs de salle possible » — mesuré avant ce
     * seuil : 11 renoncements sur 123 salles-au-coffre, la salle désignée
     * étant trop exiguë pour porter son meuble). PUBLIC : lu à la fois ici
     * (`garantirTaillesSallesACoffre()`, avant même la peinture des tuiles)
     * et par `DeckFouille::salleLaPlusProfonde()` — UN SEUL repère, pour ne
     * pas ressaisir « 7 » à deux endroits qui divergeraient le jour où
     * `CASES_JOUABLES_MINIMUM` change.
     */
    public const CASES_MINIMUM_SALLE_COFFRE = self::CASES_JOUABLES_MINIMUM + 1;

    /**
     * Cases de SOL qu'un rectangle largeur×hauteur porte à l'intérieur,
     * contour mural déduit — vrai pour toute tuile « salle » seedée
     * (intérieur PLEIN, sans alcôve, cf. docblock de la classe) et donc pour
     * toute salle assemblée, AVANT MÊME que `cases` existe. C'est ce qui
     * permet de juger la taille d'une salle dès le choix des tuiles, avant
     * que le passage secret ou la salle-artefact ne soient désignés.
     *
     * @param  array{largeur: int, hauteur: int}  $rectangle  une salle
     *         assemblée (`carte.salles[i]`) OU `Tuile::$grille`, les deux
     *         portant les mêmes clés `largeur`/`hauteur`.
     */
    public static function interieurSalle(array $rectangle): int
    {
        return max(0, (int) ($rectangle['largeur'] ?? 0) - 2) * max(0, (int) ($rectangle['hauteur'] ?? 0) - 2);
    }

    /**
     * Salles PAYÉES par une porte secrète : pour chaque arête marquée
     * `secrete`, la PLUS PROFONDE de ses deux salles (profondeur = distance
     * depuis la salle 0 sur l'ENSEMBLE des arêtes, arbre et boucles
     * confondus) — jamais les deux, l'autre côté reste la portion déjà
     * explorée du donjon. Pur calcul de TOPOLOGIE, sans aucune notion de
     * récompense : c'est ce qui permet à `DeckFouille::sallesACoffre()` (le
     * câblage des récompenses) ET à `garantirTaillesSallesACoffre()` ci-dessous
     * (la garantie de taille, AVANT peinture) de partager EXACTEMENT le même
     * calcul plutôt que d'en tenir chacun sa copie — le défaut le plus répété
     * de ce projet (`CLAUDE.md` « one rule, one point of passage »).
     *
     * @param  list<array{a: int, b: int, secrete?: bool}>  $aretes  une arête
     *         par jonction (jamais une par porte : une jonction non-mitoyenne
     *         pousse 2 portes mais reste UNE arête).
     * @return list<int>
     */
    public static function sallesDerriereLesPortesSecretes(array $aretes): array
    {
        $voisins = [];
        foreach ($aretes as $arete) {
            $a = (int) $arete['a'];
            $b = (int) $arete['b'];
            $voisins[$a][] = $b;
            $voisins[$b][] = $a;
        }

        $profondeur = [0 => 0];
        $file = [0];
        while ($file !== []) {
            $courant = array_shift($file);
            foreach ($voisins[$courant] ?? [] as $voisin) {
                if (! isset($profondeur[$voisin])) {
                    $profondeur[$voisin] = $profondeur[$courant] + 1;
                    $file[] = $voisin;
                }
            }
        }

        $salles = [];
        foreach ($aretes as $arete) {
            if (empty($arete['secrete'])) {
                continue;
            }
            $a = (int) $arete['a'];
            $b = (int) $arete['b'];
            $salles[] = ($profondeur[$a] ?? 0) >= ($profondeur[$b] ?? 0) ? $a : $b;
        }

        return array_values(array_unique($salles));
    }

    /**
     * Échange, AVANT toute peinture de tuile, la FORME des salles que
     * `sallesDerriereLesPortesSecretes()` désignera plus tard si elle est trop
     * EXIGUË pour porter un `Coffre` réel (René, 2026-09-18) — jamais APRÈS :
     * la géométrie des couloirs (`creuserArete()`) dépend de la
     * largeur/hauteur de chaque salle, un échange une fois peint demanderait
     * de tout recreuser.
     *
     * Ce n'est PAS un tirage supplémentaire : aucun `\Closure $suivant` n'est
     * consommé ici, l'échange est purement déterministe (la plus grande tuile
     * DISPONIBLE), donc n'affecte la reproductibilité d'aucune graine qui n'a
     * pas besoin d'être corrigée.
     *
     * ⚠ La salle 0 (déjà la plus grande, `plusGrandeTuileEnTete()`), la salle
     * du BOSS (thème imposé, `choisirTuiles()`) et TOUTE AUTRE salle-au-coffre
     * de cette même carte (déjà assez grande, ou en cours de correction) ne
     * sont JAMAIS données en échange : une donneuse prise parmi elles la
     * rendrait à son tour trop exiguë, sans que la boucle ne la revérifie.
     *
     * L'échange ne fait que PERMUTER des formes déjà tirées pour cette carte
     * — aucune n'est ajoutée ni retirée du lot — donc la diversité des formes
     * VISIBLES sur la carte est rigoureusement inchangée ; seule la salle qui
     * porte chaque forme peut changer.
     *
     * @param  list<Tuile>  $tuiles
     * @param  list<array{parent: int, enfant: int, secrete?: bool}>  $aretes  arbre
     *         + boucles, TEL QUE rendu par `construireArbre()` (pas encore
     *         normalisé en 'a'/'b' : on le fait ici, localement).
     * @return list<Tuile>
     */
    private function garantirTaillesSallesACoffre(array $tuiles, array $aretes): array
    {
        $dernier = count($tuiles) - 1;
        $salleBoss = $dernier > 0 && ($tuiles[$dernier]->theme ?? null) === 'boss' ? $dernier : null;

        $aretesNormalisees = array_map(fn (array $a) => [
            'a' => (int) $a['parent'], 'b' => (int) $a['enfant'], 'secrete' => ! empty($a['secrete']),
        ], $aretes);

        $sallesACoffre = self::sallesDerriereLesPortesSecretes($aretesNormalisees);

        // ⚠ AUCUNE salle-au-coffre — celle qu'on corrige à ce tour de boucle
        // COMME celles qu'on a déjà jugées assez grandes, ou qu'on corrigera au
        // tour suivant — ne doit jamais servir de DONNEUSE : lui prendre sa
        // forme pour en dépanner une autre la rendrait à son tour trop exiguë,
        // sans que la boucle ne la revérifie ensuite. Bug réellement mesuré en
        // écrivant la mesure de ce brief : sur une carte à DEUX passages
        // secrets, la première salle corrigée pouvait « déshabiller » la
        // seconde (déjà assez grande, ou déjà réparée) pour s'habiller
        // elle-même. La salle 0 et la salle du BOSS restent protégées pour les
        // mêmes raisons qu'avant (déjà les plus grandes / thème imposé).
        $protegees = array_flip([...$sallesACoffre, 0, ...($salleBoss !== null ? [$salleBoss] : [])]);

        foreach ($sallesACoffre as $i) {
            if (self::interieurSalle($tuiles[$i]->grille) >= self::CASES_MINIMUM_SALLE_COFFRE) {
                continue; // déjà assez grande
            }

            $donneuse = null;
            foreach ($tuiles as $j => $t) {
                if (isset($protegees[$j])) {
                    continue;
                }
                if ($donneuse === null || self::interieurSalle($t->grille) > self::interieurSalle($tuiles[$donneuse]->grille)) {
                    $donneuse = $j;
                }
            }

            if ($donneuse !== null && self::interieurSalle($tuiles[$donneuse]->grille) >= self::CASES_MINIMUM_SALLE_COFFRE) {
                [$tuiles[$i], $tuiles[$donneuse]] = [$tuiles[$donneuse], $tuiles[$i]];
            }
            // ⚠ Repli EXPLICITE, jamais silencieux (`CLAUDE.md` « withdrawing
            // content is a written choice ») : aucune tuile du donjon (hors
            // salle 0 et boss) n'atteint le plancher. `placerMobilier()`
            // renoncera alors au Coffre VISUEL pour cette salle — la
            // récompense reste due (`DeckFouille::carteCoffre()` ne regarde
            // jamais si un meuble existe). Non rencontré sur le vivier actuel
            // (6 à 35 cases de sol, seuls DEUX patrons sur dix valent 6), mais
            // pas exclu pour un futur vivier plus petit — on ne prétend pas
            // que le cas n'existe pas, on dit ce qu'il devient.
        }

        return $tuiles;
    }

    /**
     * Cases du décor où un monstre ne doit JAMAIS apparaître (René, 2026-09-11,
     * après avoir joué : « les monstres ne devraient pas apparaître dans les
     * meubles et les portes fermées » puis « spawn seulement dans les cases
     * vides »).
     *
     * ⚠ Le défaut était une ASYMÉTRIE DE SIGNATURE, pas un calcul faux :
     * `spawnsHeros()` recevait `$portes` et évitait donc les embrasures, tandis
     * que `spawnsMonstres()` ne recevait que `$cases` et `$salles` — il ne
     * POUVAIT pas éviter ce qu'on ne lui donnait pas. Et `interieur()` ne teste
     * qu'une chose : « est-ce du sol ». Les spawns sont pourtant calculés APRÈS
     * la pose de toutes les couches : la donnée était là, simplement pas
     * transmise. `DemarreurQuete` pose ensuite la créature sans re-valider.
     *
     * ⚠ Un seul point de passage, appelé une fois : deux recensements du décor
     * divergeraient au premier ajout de couche — et une couche neuve oubliée
     * ici se verrait en partie, sous la forme d'un monstre dans une table.
     *
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int, theme: string}>  $salles
     * @return array<string, true>  clés « x,y »
     */
    private function casesDuDecor(array $salles, array $portes, array $mobilier, array $pieges, array $leviers, array $epreuves, array $terrain): array
    {
        $pris = [];

        // Embrasures : une porte occupe SA case depuis le 2026-09-11, et une
        // porte close y interdit même l'entrée. Un monstre posé là serait
        // enfermé dans le battant.
        foreach ($portes as $porte) {
            $e = Grille::caseEmbrasure($porte, $salles);
            $pris["{$e['x']},{$e['y']}"] = true;
        }

        // Mobilier : son EMPRISE entière (`l`×`h`), pas seulement son ancre —
        // une table 2×1 prend deux cases, et seule la première était visible
        // depuis l'ancre.
        foreach ($mobilier as $m) {
            for ($dy = 0; $dy < max(1, (int) ($m['h'] ?? 1)); $dy++) {
                for ($dx = 0; $dx < max(1, (int) ($m['l'] ?? 1)); $dx++) {
                    $pris[((int) $m['x'] + $dx).','.((int) $m['y'] + $dy)] = true;
                }
            }
        }

        foreach ([$leviers, $epreuves, $terrain] as $couche) {
            foreach ($couche as $e) {
                $pris[((int) $e['x']).','.((int) $e['y'])] = true;
            }
        }

        // Pièges : leur case de déclenchement, ET — Lame balançoire — la
        // ZONE qu'elle balaie (`effet.zone_lames`, voir `placerLameBalanciere()`).
        // Sans cette seconde boucle, un monstre pourrait apparaître (ou un
        // meuble se poser, si cette couche tournait après) SUR une case de
        // lame : §2.12 bis/ter veulent un spawn sur case VIDE, pas sur un
        // piège qu'on ne voit tout simplement pas depuis ce recensement.
        foreach ($pieges as $e) {
            $pris[((int) $e['x']).','.((int) $e['y'])] = true;

            foreach ((array) ($e['zone'] ?? []) as $z) {
                $pris[((int) $z['x']).','.((int) $z['y'])] = true;
            }
        }

        return $pris;
    }

    /**
     * @param  \Closure  $suivant  PRNG du donjon (`creerPRNG()`) — JAMAIS
     *                             `shuffle()`/`random_int()`, sous peine de
     *                             perdre la reproductibilité à graine égale
     *                             que `CouloirsTest` verrouille. Méthode
     *                             `private`, un seul appelant (`assembler()`
     *                             ci-dessus) : rien d'autre à tenir compatible.
     */
    private function spawnsMonstres(array $cases, array $salles, array $decor, \Closure $suivant): array
    {
        $n = count($salles);

        if ($n <= 1) {
            return [];
        }

        // René, 2026-09-11, en partie réelle : « la position des monstres
        // devrait être aléatoire dans la salle ». `interieur()` rend ses cases
        // en ordre de LECTURE (ligne par ligne) et `array_slice()` en gardait
        // toujours le MÊME préfixe : les monstres se massaient donc dans le
        // même coin (haut-gauche) d'une salle à l'autre, et la réserve
        // (§2.12 bis) tombait toujours dans le coin opposé. Un SEUL PRNG local,
        // amorcé par le PRNG du donjon — jamais `shuffle()` — pour que la carte
        // reste reproductible à graine égale.
        $prng = new PrngLineaire($suivant());

        $ordre = array_merge([$n - 1], $n > 2 ? range(1, $n - 2) : []);

        // §2.12 bis — une salle ne doit JAMAIS être remplie à ras bord : il
        // faut y laisser de quoi entrer et combattre. Observé en partie réelle :
        // une salle « 4×4 » (donc 5 cases utiles, son contour étant du mur) a
        // reçu 5 monstres — plus aucune case libre, salle impénétrable, et une
        // SEULE case du donjon adjacente à elle. Le combat s'est joué à un héros
        // contre cinq à travers ce goulot, et a coûté la partie.
        $listes = array_map(function (int $i) use ($cases, $salles, $decor, $prng) {
            // ⚠ « Spawn seulement dans les cases VIDES » (René, 2026-09-11) :
            // on retire tout ce que le décor occupe AVANT de calculer la
            // réserve, sinon on réserverait des cases déjà prises et une salle
            // meublée se remplirait quand même à ras bord.
            $interieur = array_values(array_filter(
                $this->interieur($cases, $salles[$i]),
                fn (array $p) => ! isset($decor["{$p['x']},{$p['y']}"]),
            ));

            // Mélangé AVANT de trancher la réserve : sans ça, le tri par
            // lecture revenait par la porte d'à côté et la réserve, elle
            // aussi, restait toujours au même coin.
            $interieur = $prng->melanger($interieur);

            // On réserve une place d'entrée par héros possible. ⚠ Le plafond
            // était « la moitié de la salle », ce qui faisait RÉTRÉCIR la
            // réserve des héros exactement quand la salle devenait étroite —
            // l'inverse de ce qu'il faut : une salle de 5 cases gardait 2
            // cases pour le groupe et en donnait 3 aux monstres. Quatre héros
            // n'y tenaient pas, et deux d'entre eux restaient sur le seuil
            // sans pouvoir agir (René, 2026-09-12, partie à 4 personnages).
            //
            // Le plafond devient « laisser au moins UNE place de monstre » :
            // c'est la salle qui dicte la taille du combat, pas le combat qui
            // chasse le groupe de la salle. Une petite salle reçoit donc peu
            // de monstres au lieu de peu de héros — et le budget de rencontre
            // reporte le reste ailleurs, ce qu'il sait déjà faire puisqu'il
            // répartit sur `$listes`.
            $reserve = min(self::RESERVE_CASES_LIBRES, max(0, count($interieur) - 1));

            return array_slice($interieur, 0, max(0, count($interieur) - $reserve));
        }, $ordre);

        $positions = [];
        $max = max(array_map('count', $listes));

        for ($round = 0; $round < $max; $round++) {
            foreach ($listes as $liste) {
                if (isset($liste[$round])) {
                    $positions[] = $liste[$round];
                }
            }
        }

        return $positions;
    }
}
