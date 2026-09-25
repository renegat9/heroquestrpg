<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\MotsClesTalent;
use App\Models\Competence;
use App\Models\Personnage;
use LogicException;

/**
 * Collecteur UNIQUE des talents qui viennent de s'activer TOUT SEULS, pendant
 * la résolution d'une action — René, 2026-09-25 : « on devrait afficher un
 * popup quand un pouvoir s'active de manière passive » (docs/contrat-api.md
 * §« Un talent qui s'active tout seul se VOIT »).
 *
 * ⚠ Le défaut qu'il corrige : jusqu'ici la plupart des talents passifs
 * jouaient EN SILENCE — une porte secrète apparaissait, un poison glissait,
 * un dé raté était relancé — sans que le joueur sache que c'était SON talent.
 * Un effet automatique que rien n'annonce est injouable (règle dure du projet).
 *
 * SINGLETON DE REQUÊTE (comme {@see TamponScenes}) : chaque site d'effet — le
 * `lecteur` déclaré par {@see MotsClesTalent} — y dépose une entrée dès que sa
 * mécanique joue ; `ResolveurTour::resoudre()` vide le tampon UNE SEULE FOIS,
 * à la fin de la résolution, dans `talents_declenches`. Cette couture couvre
 * SANS rien de plus l'attaque du héros, la phase des monstres, les pièges, la
 * fouille et le déplacement : tous se résolvent dans le MÊME appel à
 * `resoudre()` (une seule transaction), donc dans la même instance de ce
 * collecteur.
 *
 * ⚠ Aucun site d'effet ne formate sa propre ligne : {@see self::annoncer()}
 * construit la même forme partout, et `App\Partie\JournalCombat` la transforme
 * en la ligne `ton: "talent"` du contrat — point de passage unique, des deux
 * côtés.
 */
final class AnnoncesTalents
{
    /**
     * Liste FERMÉE des mécaniques dont le déclenchement se VOIT — décidée avec
     * René le 2026-09-25, transcrite de `docs/contrat-api.md`
     * §« Un talent qui s'active tout seul se VOIT ». Testée dans les deux sens
     * (`AnnoncesTalentsRegistreTest`) : {@see self::annoncer()} refuse toute
     * mécanique qui n'y figure pas.
     *
     * Les mécaniques du groupe 3 (modificateurs de jet — Frénésie, Tir
     * précis, réduction de dégâts subis…) N'EN FONT PAS PARTIE : elles
     * modifient un jet SANS événement propre et publient `modificateurs`
     * plutôt qu'un popup — un popup à chaque coup serait du bruit.
     *
     * @var list<string>
     */
    public const MECANIQUES = [
        // Événement.
        'detection_pieges_adjacents',
        'detection_portes_secretes',
        'alerte_pieges_adjacents',
        'bonus_des_defense',
        'resistance_condition',
        'garde_sort_qui_tue',
        'resistance_degats_type',
        'inflige_condition_sur_touche',
        'bonus_des_attaque_flanc',
        'ignore_terrain_entravant',
        'bonus_or_tresor',
        'rarete_butin_amelioree',
        // Actifs qui partent seuls.
        'relance_des_attaque_rates',
        'attaque_supplementaire_apres_kill',
        'annuler_effet_magique',
        'repiocher_carte_piege',
    ];

    /**
     * @var list<array{personnage_id: int, heros: string, talent: string, mecanique: string, icone: string, effet: string}>
     */
    private array $annonces = [];

    /**
     * Signale qu'un talent DE CE HÉROS vient de jouer, avec CET effet.
     *
     * `$effet` est la phrase DÉCIDÉE par le serveur (« révèle une porte
     * secrète », « relance 2 dés ratés », « résiste à Empoisonné », « +25
     * pièces d'or ») — jamais reformatée par l'appelant : c'est ce que le
     * contrat appelle « aucun site d'effet ne formate sa propre annonce ».
     */
    public function annoncer(Personnage $personnage, Competence $noeud, string $effet): void
    {
        $mecanique = (string) ($noeud->effet['mecanique'] ?? '');

        // ⚠ Le garde-fou qui rend le registre testable DANS LES DEUX SENS :
        // sans lui, un futur appel `annoncer()` sur une mécanique du groupe 3
        // (ou toute autre) passerait la compilation, et rien ne dirait qu'il
        // sort de la liste fermée décidée avec René.
        if (! in_array($mecanique, self::MECANIQUES, true)) {
            throw new LogicException(
                "AnnoncesTalents::annoncer() : « {$mecanique} » (nœud « {$noeud->nom} ») n'est pas ".
                'dans la liste fermée des talents annoncés (docs/contrat-api.md).',
            );
        }

        $this->annonces[] = [
            'personnage_id' => (int) $personnage->id,
            'heros' => (string) $personnage->nom,
            'talent' => (string) $noeud->nom,
            'mecanique' => $mecanique,
            'icone' => MotsClesTalent::icone((array) $noeud->effet),
            'effet' => $effet,
        ];
    }

    /**
     * Rend tout ce qui a été annoncé depuis le dernier appel, et oublie tout.
     *
     * @return list<array{personnage_id: int, heros: string, talent: string, mecanique: string, icone: string, effet: string}>
     */
    public function vider(): array
    {
        $lot = $this->annonces;
        $this->annonces = [];

        return $lot;
    }
}
