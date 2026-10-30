<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * VOCABULAIRE FERMÉ DU TERRAIN (doc 18 §4, The Frozen Horror) — la cinquième
 * couche de la carte (`terrains.effet`, `cartes.grille['terrain']`).
 *
 * Même garde-fou que `MotsClesTalent`/`MotsClesEpreuve`, et pour la même
 * raison : `TerrainSeeder` a seedé ce vocabulaire SANS classe de registre ni
 * lecteur (phase 4a, 2026-09-06) — « cette phase-ci ne pose que les
 * FONDATIONS, AUCUNE de ces clés n'a de lecteur aujourd'hui ». Ce fichier
 * comble ce trou pour les quatre tuiles portées phase 4b : Glace glissante,
 * Glissière de glace, Chambre forte de glace, Tunnel de glace.
 *
 * Deux vocabulaires cohabitent ici, sur le patron de `MotsClesSort` :
 *
 *  - `VOCABULAIRE` — les clés dont un lecteur RÉEL existe. Chaque entrée porte
 *    `lecteur` (la couture moteur qui l'applique — `TerrainEnJeuTest` vérifie
 *    que la classe et la méthode existent, ET que le fichier du lecteur
 *    contient littéralement la clé, comme `GrilleTalentsTest` le fait pour les
 *    talents) et `libelle` (le texte joueur).
 *  - `NON_IMPLEMENTES` — les clés que le catalogue PORTE mais qu'aucune
 *    couture ne lit encore, sur le patron `MotsClesSort::NON_IMPLEMENTES`.
 *    Trois entrées, et les trois sont des dettes ÉCRITES, jamais des oublis :
 *     - `sens_unique` (Glissière de glace) — le SENS de la glissière n'est
 *       sourcé nulle part (`TerrainSeeder`, doc 18 §4 ne le précise pas) ;
 *       seuls `fin_tour`/`degats_pv_body` sont appliqués, sans redirection.
 *     - `support_sort` (Glace magique) — ancrage pour *Ice Wall* / *Ice Bridge*,
 *       des sorts du boss hors du périmètre de cette phase (plan §Phase 2).
 *     - `decor` (Rebord de crevasse) — lié au *Bottomless Chasm*, HORS
 *       PÉRIMÈTRE : le moteur n'a aucune mort permanente (arbitrage de René,
 *       plan §5.5). Volontairement sans mécanique, pour toujours.
 *
 * ⚠ Les FACES de `App\Engine\Des\FaceDeCombat` (`crane`, `bouclier_blanc`,
 * `bouclier_noir`) qui composent `sur.{face}` ne sont PAS un mot de ce
 * vocabulaire : c'est un registre distinct et déjà fermé, celui des dés de
 * combat. `TerrainEnJeuTest` les exclut explicitement de la confrontation.
 *
 * @see \App\Partie\ResolveurTour::tronquerSurGlace()          jet_des_combat, sur, chute, fin_tour, degats_pv_body
 * @see \App\Partie\ResolveurTour::saignerParTerrain()         recurrent
 * @see \App\Partie\ResolveurTour::saignerSurRiviere()          jet_des_combat, sur, degats_pv_body (SANS arrêt — Rivière gelée)
 * @see \App\Partie\ResolveurTour::teleporterSiTunnel()        teleportation
 * @see \App\Partie\MoteurSables::sauts()                     saut_par_dessus (+ FabriqueGrille::pour())
 * @see \App\Partie\MoteurSables::resoudreSaut()              saut_reussi_sur, enlise
 */
final class MotsClesTerrain
{
    /**
     * @var array<string, array{lecteur: string|list<string>, libelle: string}>
     */
    public const VOCABULAIRE = [
        // Glace glissante, Glissière de glace, Rivière gelée, Chambre forte de
        // glace : « jette N dé(s) de combat » — le nombre de dés à lancer au
        // contact (glissante/glissière) ou à chaque tour passé dedans (chambre
        // forte). Un seul dé sourcé à ce jour (les 4 lignes valent 1).
        'jet_des_combat' => [
            'lecteur' => [
                'App\Partie\ResolveurTour::tronquerSurGlace()',
                'App\Partie\ResolveurTour::saignerParTerrain()',
                'App\Partie\ResolveurTour::saignerSurRiviere()',
            ],
            'libelle' => 'jette {valeur} dé(s) de combat',
        ],

        // Table résultat → issue, indexée par FACE de combat obtenue
        // (`bouclier_blanc`/`crane`). Structurelle : elle ne porte pas de
        // texte joueur propre, chaque sous-clé (chute, fin_tour,
        // degats_pv_body) dit la sienne.
        'sur' => [
            'lecteur' => [
                'App\Partie\ResolveurTour::tronquerSurGlace()',
                'App\Partie\ResolveurTour::saignerParTerrain()',
                'App\Partie\ResolveurTour::saignerSurRiviere()',
            ],
            'libelle' => 'selon le résultat du jet',
        ],

        // Glace glissante — « bouclier blanc = chute ». Purement NARRATIF : la
        // carte ne rend personne inconscient, `tombe` (0 PV, relevable) reste
        // intact. `fin_tour` porte, seul, la conséquence mécanique.
        'chute' => [
            'lecteur' => 'App\Partie\ResolveurTour::tronquerSurGlace()',
            'libelle' => 'le héros glisse et tombe',
        ],

        // Glace glissante (sur jet) et Glissière de glace (INCONDITIONNEL, en
        // tête d'effet — une glissière fait toujours sortir du tour, la carte
        // ne roule le dé que pour la BLESSURE) : le tour s'arrête NET. Forcer
        // le créneau à « tour » rejoue toute la cérémonie de fin de tour
        // (rejetons, poison, roche mortelle, buffs `ce_tour`) plutôt que de
        // poser `a_joue` en douce.
        'fin_tour' => [
            'lecteur' => 'App\Partie\ResolveurTour::tronquerSurGlace()',
            'libelle' => 'finit immédiatement le tour du héros',
        ],

        // Glissière de glace (sur bouclier blanc, ARRÊTE le tour — lu par
        // `tronquerSurGlace()`), Chambre forte de glace (sur crâne, RÉCURRENT
        // — `saignerParTerrain()`), Rivière gelée (sur bouclier blanc, SANS
        // arrêt — `saignerSurRiviere()`, méthode SŒUR distincte : une case de
        // rivière n'empêche jamais d'entrer dans la suivante, contrairement à
        // la Glissière). Passe par `MoteurDegats::infligerAHeros()` avec une
        // source DÉDIÉE, hors `ReactionEffet::SOURCES_REACTIVES` — un danger
        // de décor, pas un coup reçu, même raison que le poison et l'étreinte
        // du Yéti.
        'degats_pv_body' => [
            'lecteur' => [
                'App\Partie\ResolveurTour::tronquerSurGlace()',
                'App\Partie\ResolveurTour::saignerParTerrain()',
                'App\Partie\ResolveurTour::saignerSurRiviere()',
            ],
            'libelle' => '{valeur} PV de Body',
        ],

        // Chambre forte de glace — « par_tour_dans_la_zone » : saigne à CHAQUE
        // fin de tour passé sur la case, pas seulement au contact. Même
        // rythme que le poison (`saignerParConditions()`, appelé juste à côté)
        // mais la source est le TERRAIN, pas une condition portée par le héros.
        'recurrent' => [
            'lecteur' => 'App\Partie\ResolveurTour::saignerParTerrain()',
            'libelle' => 'inflige ses dégâts à chaque tour passé dessus',
        ],

        // Paires de téléportation (`paire_id`, posées par
        // `AssembleurCarte::placerTerrains()`). DEUX valeurs, deux moments :
        //  - `true` — TUNNEL DE GLACE : résolu sur la case d'ARRIVÉE déjà
        //    déterminée (traverser n'est pas s'arrêter) ;
        //  - `'contact'` — TRAPPE DE TÉLÉPORTATION (Mage du Miroir p. 5, Kellar's
        //    Keep q. 8 note C) : la trappe se joue AU CONTACT. Elle est un PORTAIL
        //    (`FabriqueGrille::pour()` le pose, `Grille::parcoursPondere()` ne le
        //    traverse jamais) : on y entre, on arrive sur sa jumelle, à coût nul,
        //    et le chemin s'arrête sur l'entrée.
        // Dans les deux cas : jamais dans une salle NON DÉCOUVERTE, jamais sur
        // une sortie occupée par une figure (`teleporterSiTunnel()`).
        'teleportation' => [
            'lecteur' => 'App\Partie\ResolveurTour::teleporterSiTunnel()',
            'libelle' => 'téléporte vers son autre extrémité',
        ],

        // TRAPPE DE TÉLÉPORTATION — « After a hero moves through it, they roll one
        // combat die. If a skull is rolled, the hero loses 1 Body Point » (F7539
        // p. 15 note A ; F4543 p. 22 note C). La VALEUR est le nombre de PV de
        // Body perdus sur un crâne ; le passage jette UN dé de combat (sourcé).
        // Lu par `ResolveurTour::passageDeTrappe()`, une fois la téléportation
        // acquise. Les monstres, eux, ne jettent rien.
        'degats_au_passage' => [
            'lecteur' => 'App\Partie\ResolveurTour::passageDeTrappe()',
            'libelle' => 'au passage, un dé de combat : un crâne fait perdre {valeur} PV de Body',
        ],

        // Rivière gelée (2026-10-04) — « Monsters suffer neither movement
        // penalties nor damage from the icy river » (Frozen Horror p. 6) : un
        // monstre ne paie pas `cout_deplacement`. Les dégâts, eux, ne sont
        // appelés que dans le déplacement du HÉROS (`saignerSurRiviere()`).
        'ignore_par_monstres' => [
            'lecteur' => 'App\Partie\FabriqueGrille::pour()',
            'libelle' => 'les monstres la traversent sans pénalité',
        ],

        // Glissière de glace (2026-10-04) — « Monsters cannot move onto ice
        // slide squares » (Frozen Horror p. 5) : obstacle dans toute grille
        // bâtie pour un monstre, jamais pour un héros.
        'interdit_aux_monstres' => [
            'lecteur' => 'App\Partie\FabriqueGrille::pour()',
            'libelle' => 'interdite aux monstres',
        ],

        // TERRAIN GÊNANT (« hindering terrain », Jungles of Delthrak p. 4 :
        // sable, toile, jungle — « each single square […] costs heroes and
        // other creatures, including monsters, 2 squares of movement to
        // traverse »). Le COÛT est déjà porté par `cout_deplacement` ; ce mot
        // n'ajoute que le FAIT d'être « gênant », celui que trois sources
        // lèvent — Agile (monstres, p. 48), le talent `ignore_terrain_entravant`
        // et les Bracers of the Wild (p. 50) — sans lever la Rivière gelée, qui
        // coûte 2 elle aussi mais n'est pas dite gênante. Lu à la pose de la
        // grille (`FabriqueGrille::pour()` marque la case) et levé en UN point
        // (`Grille::ignorerTerrainEntravant()`).
        'entravant' => [
            'lecteur' => [
                'App\Partie\FabriqueGrille::pour()',
                'App\Partie\Grille::ignorerTerrainEntravant()',
            ],
            'libelle' => 'terrain gênant : coûte plus cher à traverser',
        ],

        // MARE et BRASIER (Jungles of Delthrak p. 4) : « Creatures may move
        // through [it] but may not end their turn occupying the same space as
        // it. » On PASSE, on ne FINIT pas dessus — pour toute créature, héros
        // comme monstre. Ni obstacle (on traverse), ni figure (« does not block
        // line of sight »). Posé par `FabriqueGrille::pour()`, lu par
        // `Grille::arretInterdit()` — la question de toute destination.
        'interdit_arret' => [
            'lecteur' => [
                'App\Partie\FabriqueGrille::pour()',
                'App\Partie\Grille::arretInterdit()',
            ],
            'libelle' => 'on peut la traverser, pas y finir son mouvement',
        ],

        // MARE (Pool of Water, p. 4) : « If a hero searches for treasure in an
        // area containing a pool of water, they may choose to restore 1 lost Body
        // Point instead of drawing from the treasure deck. » La valeur est le
        // nombre de PV rendus. Lu en UN point (`MoteurTerrain::soinALaFouille()`),
        // que le menu (l'offre) ET le résolveur (le soin) relisent.
        'soin_a_la_fouille' => [
            'lecteur' => 'App\Partie\MoteurTerrain::soinALaFouille()',
            'libelle' => 'rend {valeur} PV de Body au lieu d\'une carte de trésor',
        ],

        // Chambre forte de glace, Rivière gelée (2026-09-10) — NATURE du
        // dégât (`App\Engine\TypeDegat`), lue AVANT d'infliger : sans elle,
        // aucune pièce d'`immunite_degat` (Anneau de Chaleur) ne pourrait
        // jamais intercepter un dégât de TERRAIN, seulement un sort. Même
        // registre fermé que `MotsClesEquipement::IMMUNITE_DEGAT`.
        'type_degat' => [
            'lecteur' => [
                'App\Partie\ResolveurTour::saignerParTerrain()',
                'App\Partie\ResolveurTour::saignerSurRiviere()',
            ],
            'libelle' => 'dégât de nature : %s',
        ],

        // SABLES MOUVANTS (*The Mage of the Mirror* F7539 p. 29, quête 8, note C,
        // 2026-10-15) : « To reach the other side of the quicksand pit, a hero
        // must stand adjacent to it and try to jump over the quicksand. » La
        // VALEUR est la PROFONDEUR de la bande, en cases : le saut n'est offert
        // que depuis une case voisine, au travers d'EXACTEMENT ce nombre de cases
        // de sable (`MoteurSables::sauts()`), jusqu'à une case de réception libre.
        // La case n'est ni un passage ni un arrêt : `FabriqueGrille::pour()` la
        // range avec les cases où l'on ne finit pas son mouvement, en plus de la
        // colonne `bloque_mouvement` qui la ferme à la marche.
        'saut_par_dessus' => [
            'lecteur' => [
                'App\Partie\MoteurSables::sauts()',
                'App\Partie\FabriqueGrille::pour()',
            ],
            'libelle' => 'se franchit d\'un saut depuis la case voisine ({valeur} cases de large) : on ne la traverse pas à pied et on n\'y finit pas son mouvement',
        ],

        // « The hero rolls one combat die in the attempt. If a black shield is
        // rolled, the hero successfully lands on the square directly across…
        // Any other result means the hero lands in the quicksand. » La VALEUR est
        // la FACE de `App\Engine\Des\FaceDeCombat` qui fait réussir (registre
        // distinct, exclu de ce vocabulaire comme `sur.{face}`).
        'saut_reussi_sur' => [
            'lecteur' => 'App\Partie\MoteurSables::resoudreSaut()',
            'libelle' => 'le saut se joue sur un dé de combat : il réussit sur {valeur}',
        ],

        // « To avoid death, tell the hero to immediately discard any two items
        // (weapons, armor, potions, scrolls, etc.). This ends the hero's turn.
        // On the hero's next turn, the hero climbs out… » La VALEUR est le nombre
        // d'objets à abandonner — que le héros CHOISIT (René, 2026-10-15) ; à
        // défaut, il tombe à 0 PV (la mort définitive est refusée dans ce jeu).
        'enlise' => [
            'lecteur' => [
                'App\Partie\MoteurSables::resoudreSaut()',
                'App\Partie\MoteurSables::resoudreDefausse()',
            ],
            'libelle' => 'un saut raté enlise le héros : il abandonne {valeur} objets de son choix (à défaut, il tombe), son tour s\'achève, et il ressort de l\'autre côté à son tour suivant',
        ],

        // SOL MEUBLE (*Spirit Queen's Torment* q. 10 note B, p. 13, lot H des restes, 2026-10-29) : « When a hero moves into or
        // through this room, if the result of the movement dice is odd, they become stuck in the first square of their movement
        // and take 1 Body Point of damage. » La VALEUR est le nombre de PV de Body perdus à l'enlisement. Le DÉ est le d6 de
        // déplacement du tour (`Deplacement::calculer()`, `detail_deplacement_tour['des'][0]`) : nos héros marchent de base + 1d6,
        // le livret en lance deux, mais « the movement dice » qui « are odd » est bien le dé qui décide de la marche.
        // Lu EN DEUX points : `FabriqueGrille::pour()` pose la case d'arrêt (on y entre, on n'en repart pas — la première case de
        // la salle), `ResolveurTour::resoudreDeplacement()` enlise le héros qui s'y arrête. Un dé pair libère (`DeplacementHeros`).
        'arret_sur_impair' => [
            'lecteur' => [
                'App\Partie\FabriqueGrille::pour()',
                'App\Partie\ResolveurTour::resoudreDeplacement()',
            ],
            'libelle' => 'un dé de déplacement IMPAIR arrête le héros sur sa première case : {valeur} PV de Body, et il ne repart qu\'à un dé PAIR',
        ],

        // COURANT PORTEUR (*Spirit Queen's Torment* q. 11 note C, p. 14, lot H des restes, 2026-10-29) : « A hero may move 2 extra
        // squares when they first enter this area. » La VALEUR est le gain de points de déplacement, pris UNE fois par déplacement, à la
        // première entrée depuis l'extérieur (`Grille::statutApres()`). Lu en deux points : `FabriqueGrille::pour()` (les cases, qui
        // ne sont que des cases de sol pour un monstre) et `Grille::gainDuTrajet()` (le budget du héros, le même calcul que le
        // résolveur et l'aperçu). Le coût de la case reste celui du sol : le courant AIDE, il ne coûte pas moins.
        'bonus_entree' => [
            'lecteur' => [
                'App\Partie\FabriqueGrille::pour()',
                'App\Partie\Grille::gainDuTrajet()',
            ],
            'libelle' => 'un héros qui y entre gagne {valeur} cases de déplacement, une seule fois par déplacement',
        ],

        // VENT DE COULOIR (*The Tower of Air* q. 12, p. 15, lot H des restes, 2026-10-29) : bouclier (blanc ou noir) — le HÉROS
        // choisit la direction de sa poussée (`MoteurVents`, liste blanche décidée au dé et revalidée au choix). La VALEUR est le
        // nombre de cases. Lu par `ResolveurTour::ventDeFinDeMouvement()`, qui tire le dé et pose le choix.
        'pousse_heros' => [
            'lecteur' => 'App\Partie\ResolveurTour::ventDeFinDeMouvement()',
            'libelle' => 'tu choisis la direction de ta poussée : {valeur} cases',
        ],

        // VENT DE COULOIR — crâne : ZARGON choisit la direction (`MoteurVents::directionDeZargon()`, le monstre vivant le plus proche,
        // égalité E, O, S, N) et la poussée est immédiate. La VALEUR est le nombre de cases. Même lecteur que `pousse_heros`.
        'pousse_zargon' => [
            'lecteur' => 'App\Partie\ResolveurTour::ventDeFinDeMouvement()',
            'libelle' => 'Zargon te pousse de {valeur} cases, dans la direction qu\'il choisit',
        ],
    ];

    /**
     * Clés déclarées SANS lecteur — une dette ÉCRITE, jamais un oubli. Sur le
     * patron `MotsClesSort::NON_IMPLEMENTES`.
     *
     * @var array<string, string>
     */
    public const NON_IMPLEMENTES = [
        'sens_unique' => 'Le SENS de la glissière n\'est sourcé nulle part (doc 18 §4, TerrainSeeder) — '
            .'seuls fin_tour/degats_pv_body s\'appliquent, sans redirection du héros.',
        'support_sort' => 'Ancrage pour Ice Wall/Ice Bridge (sorts du boss, plan glace Phase 2) — hors périmètre '
            .'de la phase 4b, pur décor tant qu\'aucun sort ne la lit.',
        'decor' => 'Rebord de crevasse, lié au Bottomless Chasm — HORS PÉRIMÈTRE : le moteur n\'a aucune mort '
            .'permanente (arbitrage de René, plan §5.5). Volontairement sans mécanique.',
    ];

    /** Une clé déclarée porte-t-elle un lecteur RÉEL ? */
    public static function connue(?string $cle): bool
    {
        return $cle !== null && isset(self::VOCABULAIRE[$cle]);
    }

    /** Une clé est-elle une dette EXPLICITE (déclarée, sans lecteur, à dessein) ? */
    public static function estNonImplementee(?string $cle): bool
    {
        return $cle !== null && array_key_exists($cle, self::NON_IMPLEMENTES);
    }

    /** Les faces de dé de combat, dites comme le joueur les voit (`sur.{face}`). */
    private const FACES = [
        'crane' => 'un crâne',
        'bouclier_blanc' => 'un bouclier blanc',
        'bouclier_noir' => 'un bouclier noir',
    ];

    /**
     * Ce que la tuile FAIT, en phrases courtes, pour le guide (`GET /api/guide`,
     * `terrains[].avantages`). Le serveur traduit — comme `MotsClesEquipement::avantages()`
     * pour les pièces : le client affiche, il ne retraduit pas la clé.
     *
     * Une clé de `NON_IMPLEMENTES` ne produit AUCUNE phrase : le guide ne promet pas
     * une règle que le moteur n'applique pas. Une tuile qui ne produit rien le dit.
     *
     * @param  array<string, mixed>  $effet
     * @return list<string>
     */
    public static function avantages(array $effet, int $coutDeplacement = 1): array
    {
        $phrases = self::phrases($effet, $coutDeplacement);

        return $phrases !== [] ? $phrases : ['sans effet à ce jour'];
    }

    /**
     * @param  array<string, mixed>  $effet
     * @return list<string>
     */
    private static function phrases(array $effet, int $coutDeplacement = 1): array
    {
        $phrases = [];

        if ($coutDeplacement !== 1) {
            $phrases[] = "coûte {$coutDeplacement} points pour y entrer";
        }

        foreach ($effet as $cle => $valeur) {
            // `sur` : ce qui arrive selon la face du dé — récursif sur le même vocabulaire.
            if ($cle === 'sur' && is_array($valeur)) {
                foreach ($valeur as $face => $sousEffet) {
                    $suite = self::phrases(is_array($sousEffet) ? $sousEffet : []);

                    if ($suite !== []) {
                        $phrases[] = 'sur '.(self::FACES[$face] ?? (string) $face).' : '.implode(' · ', $suite);
                    }
                }

                continue;
            }

            if (! self::connue((string) $cle) || self::estNonImplementee((string) $cle)) {
                continue;
            }

            $texte = is_scalar($valeur) ? (string) $valeur : '';

            // `saut_reussi_sur` porte une FACE de dé : on la dit comme le joueur la voit.
            if ($cle === 'saut_reussi_sur') {
                $texte = self::FACES[$texte] ?? $texte;
            }

            $phrases[] = str_replace(['{valeur}', '%s'], [$texte, $texte], self::VOCABULAIRE[$cle]['libelle']);
        }

        return $phrases;
    }
}
