<?php

namespace Database\Seeders;

use App\Models\Condition;
use Illuminate\Database\Seeder;

/**
 * Catalogue d'états (doc 01 §10).
 * duree_defaut en tours ; 0 = jusqu'à une condition de fin (résistance, relève, réveil…).
 * type mental → les monstres Mind 0 y sont immunisés (logique moteur).
 */
class ConditionSeeder extends Seeder
{
    public function run(): void
    {
        $conditions = [
            ['nom' => 'Empoisonné', 'type' => 'physique', 'duree_defaut' => 3,
                'effet' => ['degats_pv_body_par_tour' => 1, 'resistance_possible' => 'Sang robuste']],
            ['nom' => 'Étourdi', 'type' => 'physique', 'duree_defaut' => 1,
                'effet' => ['perd_prochain_tour' => true]],
            ['nom' => 'Apeuré', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['malus_des_attaque' => 1, 'interdit_avancer_vers_menace' => true, 'fin' => 'jet_mind_reussi']],
            ['nom' => 'Endormi', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['hors_combat' => true, 'fin' => 'reveil_ou_attaque']],
            ['nom' => 'Commandé', 'type' => 'mental', 'duree_defaut' => 1,
                'effet' => ['controle_par_ennemi' => true]],
            ['nom' => 'Ralenti', 'type' => 'physique', 'duree_defaut' => 3,
                'effet' => ['malus_deplacement' => 2]],
            ['nom' => 'Immobilisé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['deplacement_interdit' => true, 'fin' => 'liberation']],
            // ⚠ « Caché » n'a plus de producteur depuis le 2026-09-02 : la carte
            // de *Voile de Brume* décrit un mode de DÉPLACEMENT (traverser les
            // cases occupées), pas une invisibilité. La ligne reste au catalogue
            // — `inattaquable` garde son lecteur et son autre porteur
            // (« Évanescent ») — mais aucun sort ne la pose plus.
            ['nom' => 'Caché', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['inattaquable' => true, 'fin' => 'prochain_tour']],
            // Voile de Brume : comme « Intangible » pour Traverser la Pierre,
            // c'est un MODE DE DÉPLACEMENT, et il faut que le joueur le lise
            // comme tel — « tu passes à travers les monstres », pas « on ne te
            // voit plus ».
            ['nom' => 'Vaporeux', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['franchit_figures' => true, 'fin' => 'fin_du_tour']],
            // ⚠ Deux conditions de plus le 2026-09-03, pour la même raison qui a
            // fait scinder « Renforcé » en trois : la Lame Fantôme et la Longue
            // épée de Fortune posaient toutes deux « Renforcé », dont l'effet
            // catalogue est `bonus_des: attaque`. Le joueur lisait donc « +dés
            // d'attaque » alors qu'il avait reçu une annulation de défense ou une
            // relance. Une condition doit dire ce qu'elle fait.
            ['nom' => 'Perce-armure', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['ignore_defense' => true, 'fin' => 'prochaine_attaque']],
            ['nom' => 'Main sûre', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['relance_attaque' => true, 'fin' => 'prochaine_attaque']],
            // Potion de vision (Elfe) : voir les pièges et les portes secrètes
            // en ligne de vue, « until the Elf suffers at least 1 Body Point of
            // damage ». La fin est portée par la `duree` de la potion
            // (`premier_degat_subi`), pas par un compteur de tours — d'où 0.
            ['nom' => 'Clairvoyance', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['revele_pieges_et_portes_en_vue' => true, 'fin' => 'premier_degat_subi']],
            // ⚠ Trois conditions là où il n'y en avait qu'UNE. « Renforcé »
            // couvrait aussi bien un bonus d'attaque qu'un bonus de défense
            // qu'un mode de déplacement — son propre effet l'avouait :
            // `attaque_ou_defense_selon_source`. Un joueur voyant « Renforcé »
            // sur sa fiche ne pouvait pas savoir s'il frappait plus fort ou
            // s'il encaissait mieux, ni quand ça expirait (les durées diffèrent
            // : prochaine attaque vs premier dégât subi). Remonté par un joueur
            // en campagne réelle, 2026-08-20.
            ['nom' => 'Renforcé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['bonus_des' => 'attaque', 'fin' => 'duree_du_sort']],
            ['nom' => 'Protégé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['bonus_des' => 'defense', 'fin' => 'duree_du_sort']],
            // Traverser la Pierre : ce n'est pas un renforcement mais un MODE
            // DE DÉPLACEMENT (la roche et les portes closes cessent de barrer
            // le chemin). L'appeler « Renforcé » ne décrivait rien.
            ['nom' => 'Intangible', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['franchit_mur' => true, 'fin' => 'fin_du_tour']],
            ['nom' => 'Tombé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['hors_combat' => true, 'occupe_sa_case' => true, 'relevable' => true, 'fin' => 'releve_ou_fin_de_combat', 'mort_si_non_releve' => true]],
            // Venin (Jungles of Delthrak, p. 48) : « dégât = paralysie, jet de
            // 1 dé rouge pour résister sur 5-6, sinon jeton venin jusqu'à la
            // fin du tour suivant ». `deplacement_interdit` est CÂBLÉ depuis le
            // 2026-08-10 — MenuMoteur retire « Se déplacer », ResolveurTour
            // refuse le mouvement —, ce qui rend du même coup `Immobilisé`
            // réellement immobilisant.
            ['nom' => 'Envenimé', 'type' => 'physique', 'duree_defaut' => 1,
                'effet' => ['deplacement_interdit' => true, 'fin' => 'prochain_tour']],

            // Flamme hypnotique (répertoire elfique, © 2023) : « paralyzed for
            // 3 turns — unable to move, attack, or defend ». Les trois d'un
            // coup, et `defense_nulle` est une SUPPRESSION, pas un malus : la
            // figure ne lance aucun dé.
            ['nom' => 'Paralysé', 'type' => 'mental', 'duree_defaut' => 3,
                'effet' => ['deplacement_interdit' => true, 'action_interdite' => true,
                    'defense_nulle' => true, 'fin' => 'duree']],

            // Évanescence (répertoire elfique) : « The hero can only move and
            // open doors. They cannot attack, search, disarm, cast spells,
            // spring traps, or be affected by attacks or spells. »
            // ⚠ Le contraire de Paralysé sur le déplacement : il marche, mais
            // ne peut RIEN faire d'autre — et rien ne peut le toucher.
            ['nom' => 'Évanescent', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['action_interdite' => true, 'inattaquable' => true,
                    'ignore_pieges' => true, 'fin' => 'jet_de_deplacement_eleve']],
        ];

        foreach ($conditions as $condition) {
            Condition::updateOrCreate(['nom' => $condition['nom']], $condition);
        }
    }
}
