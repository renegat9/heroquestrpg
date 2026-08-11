<?php

namespace Database\Seeders;

use App\Models\ClasseHeros;
use Illuminate\Database\Seeder;

/**
 * Les 4 héros — valeurs de départ du doc 01 §4 (PV canon HeroQuest, attributs de jet proposés).
 */
class ClasseHerosSeeder extends Seeder
{
    public function run(): void
    {
        // `tags_equipement` = maîtrises accessibles SANS aucun nœud (doc 01 §7,
        // profil « canon HeroQuest »). Les nœuds `acces_equipement` en ajoutent :
        // Maîtrise lourde (barbare) ouvre arme_deux_mains + armure_lourde, et les
        // deux nœuds du magicien lèvent ses limites propres.
        //
        // Le NAIN porte l'armure lourde SANS nœud : c'est le forgeron robuste du
        // groupe (Garde tenace, Forge, Solides épaules), et au plateau seul le
        // magicien est réellement bridé. Les armes à deux mains lui restent
        // derrière « Poigne de forgeron ».
        //
        // SYMÉTRIE des deux costauds : chacun a sa spécialité gratuite et paie
        // l'autre. Le BARBARE manie les armes à deux mains de naissance et
        // achète l'armure lourde (Maîtrise lourde) ; le NAIN porte l'armure
        // lourde de naissance et achète les armes à deux mains.
        $classes = [
            // nom, pv_body, pv_mind, attr_body, attr_mind, attaque, defense, dépl., bonus_sac
            //
            // `des_attaque` = attaque À MAINS NUES, soit 1 dé pour tous (règle
            // HeroQuest, doc 03 §8 : « la valeur d'Attaque vient de l'arme
            // équipée »). Les 3/2/2/1 du doc 01 §4 sont les valeurs AVEC l'arme
            // de départ — c'est l'équipement initial qui les produit désormais,
            // au lieu d'être codé en dur dans la classe puis CUMULÉ avec l'arme
            // achetée (un barbare avec une épée large montait à 6 dés).
            // Les quatre tags d'ARMURERIE (`arme_arc_long`, `arme_arc_court`,
            // `arme_erudit`, `armure_magicien`) portent les restrictions que les
            // cartes énoncent classe par classe et que les 7 tags précédents ne
            // savaient pas dire : l'arc long est refusé au nain, l'arc court au
            // barbare, la canne aux deux costauds, et brassards et cape sont
            // RÉSERVÉS au magicien (reference/16 §2.2).
            //
            // Les quatre tags de TALISMAN font de même pour les bijoux
            // d'artefact, un par classe et réservé à elle : Amulette du Nord
            // (barbare), Brassards elfiques (elfe), Capuche du Magister
            // (magicien), Runes naines (nain) — reference/16 §9. DeckFouille
            // écarte du tirage tout artefact qu'aucune classe active du groupe
            // ne pourrait porter, sans quoi le coffre du fond aurait pu rendre
            // des runes naines à un groupe sans nain.
            ['nom' => 'barbare',  'pv_body' => 8, 'pv_mind' => 2, 'attr_body' => 4, 'attr_mind' => 1, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 4, 'bonus_sac' => 0, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_distance', 'arme_arc_long', 'armure_legere', 'bouclier', 'arme_deux_mains', 'talisman_barbare']],
            ['nom' => 'nain',     'pv_body' => 7, 'pv_mind' => 3, 'attr_body' => 3, 'attr_mind' => 2, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 3, 'bonus_sac' => 1, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_distance', 'arme_arc_court', 'armure_legere', 'bouclier', 'armure_lourde', 'talisman_nain']],
            ['nom' => 'elfe',     'pv_body' => 6, 'pv_mind' => 4, 'attr_body' => 2, 'attr_mind' => 3, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 5, 'bonus_sac' => 0, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_distance', 'arme_arc_long', 'arme_arc_court', 'arme_erudit', 'armure_legere', 'bouclier', 'talisman_elfe']],
            ['nom' => 'magicien', 'pv_body' => 4, 'pv_mind' => 6, 'attr_body' => 1, 'attr_mind' => 4, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 4, 'bonus_sac' => 0, 'tags_equipement' => ['arme_legere', 'arme_erudit', 'armure_magicien', 'talisman_magicien']],

            // ----------------------------------------------------------------
            // LES 8 CLASSES D'EXTENSION (2026-08-12)
            //
            // `pv_body`, `pv_mind` et `des_defense` sont LUS SUR LES CARTES
            // numérisées par René (reference/01_personnages.md §4bis) —
            // composants cartonnés absents de tout PDF Hasbro, comme les prix
            // d'équipement avant eux.
            //
            // `des_attaque` reste l'attaque À MAINS NUES, soit 1 pour tous :
            // les 2 et 3 des cartes viennent de l'arme de départ, exactement
            // comme les 3/2/2/1 des quatre classes ci-dessus. Le MOINE fait
            // exception à 2, et c'est écrit sur sa carte : « *When attacking
            // unarmed, roll one additional Attack die* » — chez lui, les mains
            // nues SONT l'arme.
            //
            // ⚠ `attr_body`, `attr_mind`, `deplacement_base` et `bonus_sac` sont
            // DE NOUS : aucune carte ne les porte (le plateau lance 2 dés
            // rouges sans base — divergence actée doc 00). Ils sont dérivés du
            // profil de la classe et des quatre historiques.
            //
            // ⚠ `tags_equipement` est également UNE DÉCISION DE PORTAGE : aucune
            // carte de personnage n'énonce de restriction d'armement, sauf le
            // Chevalier dont la carte donne un bouclier de départ. Les valeurs
            // ci-dessous traduisent le profil décrit par les capacités, pas un
            // texte opposable.

            // Barde — sa carte : « when you are wearing no "metal" armor and
            // carrying no shield you have 1 extra defend die ». C'est un BONUS
            // CONDITIONNEL, pas une interdiction : il a donc accès à tout
            // l'armement léger, et c'est à lui de renoncer au métal.
            ['nom' => 'barde', 'pv_body' => 5, 'pv_mind' => 4, 'attr_body' => 2, 'attr_mind' => 3, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 4, 'bonus_sac' => 0, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_distance', 'arme_erudit', 'armure_legere', 'bouclier', 'talisman_barde']],

            // Druide — « A Druid hero may not wear metal armor » : interdiction
            // ferme, celle-là. Il garde le bouclier (bois) et les armes
            // courantes ; l'armure lourde et légère métallique lui sont
            // fermées, ce que `armure_legere` retiré exprime.
            ['nom' => 'druide', 'pv_body' => 6, 'pv_mind' => 4, 'attr_body' => 2, 'attr_mind' => 3, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 4, 'bonus_sac' => 0, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_erudit', 'arme_arc_court', 'bouclier', 'talisman_druide']],

            // Warlock — « follows the same rules for wearing armor as the
            // wizard » : on reprend donc EXACTEMENT le profil du magicien,
            // `armure_magicien` compris. Sa baguette est son arme.
            ['nom' => 'warlock', 'pv_body' => 4, 'pv_mind' => 5, 'attr_body' => 1, 'attr_mind' => 4, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 4, 'bonus_sac' => 0, 'tags_equipement' => ['arme_legere', 'arme_erudit', 'armure_magicien', 'talisman_warlock']],

            // Rogue — profil de lame agile. Pas d'armure lourde ni d'arme à
            // deux mains : ses trois capacités parlent toutes de dague et
            // d'épée courte, une plate le contredirait.
            ['nom' => 'rogue', 'pv_body' => 5, 'pv_mind' => 4, 'attr_body' => 2, 'attr_mind' => 3, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 5, 'bonus_sac' => 1, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_distance', 'arme_arc_court', 'armure_legere', 'talisman_rogue']],

            // Moine — 3 dés de défense SANS armure ni bouclier, ce qui est tout
            // son personnage : il esquive, il ne se protège pas. Lui donner une
            // armure le pousserait à 4-5 dés, au-dessus de n'importe quel héros.
            ['nom' => 'moine', 'pv_body' => 6, 'pv_mind' => 4, 'attr_body' => 3, 'attr_mind' => 3, 'des_attaque' => 2, 'des_defense' => 3, 'deplacement_base' => 5, 'bonus_sac' => 0, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_distance', 'arme_erudit', 'talisman_moine']],

            // Chevalier — le seul héros à DÉMARRER avec un bouclier, et deux de
            // ses trois capacités portent « **Requires shield** ». Profil de
            // tank complet : plates comprises.
            ['nom' => 'chevalier', 'pv_body' => 7, 'pv_mind' => 2, 'attr_body' => 4, 'attr_mind' => 1, 'des_attaque' => 1, 'des_defense' => 3, 'deplacement_base' => 4, 'bonus_sac' => 0, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_distance', 'armure_legere', 'armure_lourde', 'bouclier', 'arme_deux_mains', 'talisman_chevalier']],

            // Berserker — 3 dés d'attaque de base et deux capacités qui exigent
            // d'être BLESSÉ : l'armure lourde irait contre son propre jeu, qui
            // consiste à encaisser pour frapper plus fort.
            ['nom' => 'berserker', 'pv_body' => 7, 'pv_mind' => 2, 'attr_body' => 4, 'attr_mind' => 1, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 5, 'bonus_sac' => 0, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_distance', 'armure_legere', 'arme_deux_mains', 'talisman_berserker']],

            // Explorateur — le seul 5/5 du jeu, tourné vers les pièges et le
            // deck de trésor. Profil polyvalent sans excès, proche du nain sans
            // sa plate.
            ['nom' => 'explorateur', 'pv_body' => 5, 'pv_mind' => 5, 'attr_body' => 3, 'attr_mind' => 3, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 5, 'bonus_sac' => 2, 'tags_equipement' => ['arme_legere', 'arme_courante', 'arme_distance', 'arme_arc_court', 'arme_arc_long', 'armure_legere', 'bouclier', 'talisman_explorateur']],
        ];

        foreach ($classes as $classe) {
            ClasseHeros::updateOrCreate(['nom' => $classe['nom']], $classe);
        }
    }
}
