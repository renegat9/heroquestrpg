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
 * @see \App\Partie\ResolveurTour::teleporterSiTunnel()        teleportation
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
            'lecteur' => ['App\Partie\ResolveurTour::tronquerSurGlace()', 'App\Partie\ResolveurTour::saignerParTerrain()'],
            'libelle' => 'jette {valeur} dé(s) de combat',
        ],

        // Table résultat → issue, indexée par FACE de combat obtenue
        // (`bouclier_blanc`/`crane`). Structurelle : elle ne porte pas de
        // texte joueur propre, chaque sous-clé (chute, fin_tour,
        // degats_pv_body) dit la sienne.
        'sur' => [
            'lecteur' => ['App\Partie\ResolveurTour::tronquerSurGlace()', 'App\Partie\ResolveurTour::saignerParTerrain()'],
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

        // Glissière de glace (sur bouclier blanc), Chambre forte de glace (sur
        // crâne), Rivière gelée (sur bouclier blanc, hors périmètre de la
        // truncation mais lu par le même chemin). Passe par
        // `MoteurDegats::infligerAHeros()` avec une source DÉDIÉE, hors
        // `ReactionEffet::SOURCES_REACTIVES` — un danger de décor, pas un coup
        // reçu, même raison que le poison et l'étreinte du Yéti.
        'degats_pv_body' => [
            'lecteur' => ['App\Partie\ResolveurTour::tronquerSurGlace()', 'App\Partie\ResolveurTour::saignerParTerrain()'],
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

        // Tunnel de glace — paires de téléportation (`paire_id`, posé par
        // `AssembleurCarte::placerTerrains()`, hors périmètre de ce fichier).
        // Résolu sur la case d'ARRIVÉE déjà déterminée, jamais dans une salle
        // NON DÉCOUVERTE, jamais sur une sortie occupée par une figure.
        'teleportation' => [
            'lecteur' => 'App\Partie\ResolveurTour::teleporterSiTunnel()',
            'libelle' => 'téléporte vers son autre extrémité',
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
}
