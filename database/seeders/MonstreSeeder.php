<?php

namespace Database\Seeders;

use App\Models\Monstre;
use Illuminate\Database\Seeder;

/**
 * Bestiaire (doc 09 §3-4) : 8 monstres de base + gabarits sous-boss/boss.
 * `cout` (budget de rencontres) : non chiffré dans le doc — barème de départ
 * croissant avec la dangerosité, à régler en playtest (doc 06 §10).
 */
class MonstreSeeder extends Seeder
{
    public function run(): void
    {
        $monstres = [
            // ----- Bestiaire de base : les 8 CARTES MONSTRE -----
            //
            // Aligné le 2026-08-09 sur `sjeng-monsters.pdf` (Ye Olde Inn). C'est
            // la première fois que ces valeurs sont SOURCÉES : le doc 16 §4
            // portait « ⚠ non trouvé » sur toute la table, parce que le tableau
            // chiffré des monstres vit sur l'écran du MJ, un carton jamais
            // numérisé. Deux recoupements indépendants confirment le paquet :
            //   - la momie à 3 dés d'attaque, déduite de « It rolls 4 Attack
            //     dice INSTEAD OF 3 » (livret de quêtes p. 5) ;
            //   - squelette / zombie / momie à Mind 0, ce qui explique enfin
            //     « Sleep may not be used against mummies, zombies, or
            //     skeletons » (livret de règles p. 8) — Mind 0 = pas de jet.
            //
            // ⚠ CONSÉQUENCE D'ÉQUILIBRAGE : au plateau, TOUT monstre de base a
            // **1 seul point de Body**. On en donnait 2 ou 3 aux plus costauds.
            // Un gobelin et une gargouille tombent donc désormais du même coup
            // réussi — c'est le design du jeu (les héros encaissent, les
            // monstres non), et c'est ce qui rend les paliers sous_boss/boss
            // lisibles. Les `cout` sont réajustés en conséquence : ils ne
            // dépendaient plus des vraies stats.
            ['nom_base' => 'Gobelin', 'deplacement' => 10, 'attaque' => 2, 'defense' => 1, 'pv_body' => 1, 'pv_mind' => 1,
                'tier' => 'base', 'cout' => 1, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Squelette', 'deplacement' => 6, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Zombie', 'deplacement' => 4, 'attaque' => 2, 'defense' => 3, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Orque', 'deplacement' => 8, 'attaque' => 3, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 2,
                'tier' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Fimir', 'deplacement' => 6, 'attaque' => 3, 'defense' => 3, 'pv_body' => 1, 'pv_mind' => 3,
                'tier' => 'base', 'cout' => 3, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Momie', 'deplacement' => 4, 'attaque' => 3, 'defense' => 4, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 3, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Guerrier du Chaos', 'deplacement' => 6, 'attaque' => 3, 'defense' => 4, 'pv_body' => 1, 'pv_mind' => 3,
                'tier' => 'base', 'cout' => 3, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Gargouille', 'deplacement' => 6, 'attaque' => 4, 'defense' => 4, 'pv_body' => 1, 'pv_mind' => 4,
                'tier' => 'base', 'cout' => 4, 'capacites' => [], 'sorts_dread' => []],

            // Troll (carte « Cave Troll ») : la seule créature du paquet dont
            // le texte NOMME le feu — « Trolls may choose to regenerate 1 Body
            // point instead of attacking. Damage done by fire is permanent and
            // cannot be regenerated. » C'est ce qui donne un second lecteur au
            // type de dégât `feu`, à côté de l'Anneau de Feu : sans lui, la
            // nature d'un dégât n'aurait servi qu'à une carte défensive.
            ['nom_base' => 'Troll', 'deplacement' => 8, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'base', 'cout' => 6, 'capacites' => ['regeneration'], 'sorts_dread' => []],

            // ----- Gabarits élites (doc 09 §4 — exemples proposés, à équilibrer) -----
            // capacites = bibliothèque assignable (l'IA choisit l'habillage, le moteur résout)
            ['nom_base' => 'Champion', 'deplacement' => 7, 'attaque' => 4, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'cout' => 10,
                'capacites' => ['charge'],
                'sorts_dread' => ['Trait de Chaos', 'Frayeur', 'Sommeil', 'Tempête de feu']],
            ['nom_base' => 'Seigneur', 'deplacement' => 7, 'attaque' => 5, 'defense' => 5, 'pv_body' => 10, 'pv_mind' => 5,
                'tier' => 'boss', 'cout' => 20,
                'capacites' => ['invocation', 'frappe_de_zone'],
                'sorts_dread' => ['Tempête de feu', 'Invocation de morts-vivants', 'Commandement', 'Fuite']],

            // ----- Sorciers nommés à répertoire dédié (3.8 — config/archetypes_lanceurs.php) -----
            // Le répertoire vient de l'archétype ; `sorts_dread` reste vide (l'archétype prime).
            // `cout` sous les leaders de tier (Champion 10 / Seigneur 20) pour ne pas changer
            // la rencontre finale auto-sélectionnée des quêtes.
            ['nom_base' => 'Chamane Gobelin', 'deplacement' => 8, 'attaque' => 2, 'defense' => 2, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'cout' => 9,
                'capacites' => [], 'sorts_dread' => [], 'archetype_lanceur' => 'chaman_orque'],
            ['nom_base' => 'Liche', 'deplacement' => 6, 'attaque' => 3, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 6,
                'tier' => 'boss', 'cout' => 18,
                'capacites' => ['invocation'], 'sorts_dread' => [], 'archetype_lanceur' => 'necromancien'],
            ['nom_base' => 'Sorcier des Tempêtes', 'deplacement' => 7, 'attaque' => 3, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 5,
                'tier' => 'boss', 'cout' => 17,
                'capacites' => [], 'sorts_dread' => [], 'archetype_lanceur' => 'maitre_tempetes'],

            // ----- Monstre à choix tactique (3.7) -----
            // `choix_attaque` : cible robuste (PV > seuil) → coup massif unique
            // (dés +massive_des_bonus) ; cible affaiblie → double_nombre attaques.
            // Décision 100 % moteur (ResolveurTour). `cout` sous le leader sous_boss.
            ['nom_base' => 'Ours polaire de guerre', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 6, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'cout' => 9,
                'capacites' => ['choix_attaque' => ['seuil' => 2, 'massive_des_bonus' => 2, 'double_nombre' => 2]],
                'sorts_dread' => []],

            // ----- Monstre à distance (3.4) -----
            // `portee` distance + `attaque_distance` (dés en tir) ; au contact il
            // perd un dé (attaque corps-à-corps moindre). Exige la ligne de vue.
            // Aligné sur la fiche officielle de *Jungles of Delthrak* (doc 18) :
            // « Attack 2 (1 adj.) » — 2 dés en tir, 1 seul au contact.
            ['nom_base' => 'Gobelin archer', 'deplacement' => 10, 'attaque' => 1, 'defense' => 1, 'pv_body' => 1, 'pv_mind' => 1,
                'tier' => 'base', 'cout' => 2, 'portee' => 'distance', 'attaque_distance' => 2,
                'capacites' => [], 'sorts_dread' => []],

            // ----- Grande figurine multi-cases (3.9) -----
            // `grande_taille` : emprise 1×2 (deux cases). Adjacence/ligne de vue/
            // déplacement raisonnent sur l'emprise (moteur Grille).
            // Aligné sur la fiche officielle de *The Mage of the Mirror* (doc 18).
            ['nom_base' => 'Ogre', 'deplacement' => 4, 'attaque' => 6, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'cout' => 10, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => [], 'sorts_dread' => []],

            // ================= CRÉATURES DES EXTENSIONS OFFICIELLES =================
            //
            // Stats issues de `reference/18_extensions.md`, qui les tient des
            // LIVRETS officiels Hasbro — une meilleure source que les cartes de
            // fans : le paquet `sjeng-monsters.pdf` diverge d'ailleurs sur
            // plusieurs (Gremlin des glaces Body 2 au lieu de 3, Ours polaire
            // 3+3 au lieu de 4+4). Quand les deux se contredisent, le livret
            // gagne — c'est la règle du doc 16.
            //
            // `tier` et `cout` sont les SEULES valeurs de nous : ils pilotent le
            // budget de rencontre (doc 06), qui n'existe pas au plateau. Comme
            // tous les chiffres d'équilibrage du projet, ce sont des propositions
            // de départ à régler en playtest.
            //
            // Les mots-clés de capacité de Jungles of Delthrak (livret p. 48-49,
            // règles citées en reference/18) sont PORTÉS depuis le 2026-08-10 :
            // `agile`, `venimeux`, `tacticien`, `racines_entravantes`.
            //
            // `spawn` (2026-08-10) porte le nom de la créature engendrée :
            // notre `invocation` ne sait invoquer que ce que dit un SORT, des
            // morts-vivants, et aurait fait cracher des squelettes au serpent.
            // `ethere` (Rise of the Dread Moon) et la double-action du
            // tacticien sont portés le même jour — reference/16 §4.7.

            // ---- Kellar's Keep : l'Abomination n'est PAS semée. Ses stats ne
            //      sont chiffrées dans aucun livret (doc 18 note †), seulement
            //      dans la table de tournoi d'une AUTRE boîte. On ne sème pas une
            //      valeur qu'aucune source n'assume.

            // ---- Rise of the Dread Moon (doc 18) ----
            ['nom_base' => 'Cultiste du Dread', 'deplacement' => 7, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 2,
                'tier' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Spectre', 'deplacement' => 8, 'attaque' => 3, 'defense' => 3, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 5, 'capacites' => ['ethere'], 'sorts_dread' => []],
            ['nom_base' => 'Assassin', 'deplacement' => 10, 'attaque' => 5, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 3,
                'tier' => 'base', 'cout' => 6, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Garde-mage', 'deplacement' => 8, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'cout' => 8, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Ombre du Dread', 'deplacement' => 9, 'attaque' => 6, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 5,
                'tier' => 'boss', 'cout' => 17, 'capacites' => ['ethere'], 'sorts_dread' => []],

            // ---- The Mage of the Mirror (doc 18) ----
            // L'archer elfe est la seconde créature à distance du bestiaire :
            // « Attack 4 (1 si adjacent) ».
            ['nom_base' => 'Archer elfe', 'deplacement' => 6, 'attaque' => 1, 'defense' => 2, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'base', 'cout' => 5, 'portee' => 'distance', 'attaque_distance' => 4,
                'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Guerrier elfe', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'base', 'cout' => 5, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Loup géant', 'deplacement' => 9, 'attaque' => 6, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 1,
                'tier' => 'sous_boss', 'cout' => 11, 'capacites' => ['charge'], 'sorts_dread' => []],

            // ---- The Frozen Horror (doc 18) ----
            ['nom_base' => 'Gremlin des glaces', 'deplacement' => 10, 'attaque' => 2, 'defense' => 3, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'base', 'cout' => 4, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Yéti', 'deplacement' => 8, 'attaque' => 3, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'cout' => 9, 'capacites' => [], 'sorts_dread' => []],
            // Boss de sa boîte. Grande figurine, comme l'ogre.
            ['nom_base' => 'Horreur des Glaces', 'deplacement' => 8, 'attaque' => 5, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 4,
                'tier' => 'boss', 'cout' => 16, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['resistance_magique'], 'sorts_dread' => []],

            // ---- Against the Ogre Horde (doc 18) ----
            ['nom_base' => 'Ogre guerrier', 'deplacement' => 6, 'attaque' => 5, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 1,
                'tier' => 'sous_boss', 'cout' => 10, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Ogre champion', 'deplacement' => 6, 'attaque' => 5, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 1,
                'tier' => 'sous_boss', 'cout' => 11, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Ogre commandant', 'deplacement' => 4, 'attaque' => 6, 'defense' => 5, 'pv_body' => 6, 'pv_mind' => 2,
                'tier' => 'boss', 'cout' => 15, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['charge'], 'sorts_dread' => []],
            // 10 points de Body : la créature la plus résistante du catalogue.
            ['nom_base' => 'Seigneur ogre', 'deplacement' => 4, 'attaque' => 6, 'defense' => 6, 'pv_body' => 10, 'pv_mind' => 5,
                'tier' => 'boss', 'cout' => 22, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['frappe_de_zone', 'resistance_magique'], 'sorts_dread' => []],

            // ---- Jungles of Delthrak (doc 18) ----
            ['nom_base' => 'Rejeton putride', 'deplacement' => 3, 'attaque' => 1, 'defense' => 1, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 2, 'capacites' => ['venimeux', 'agile'], 'sorts_dread' => []],
            ['nom_base' => 'Archer squelette', 'deplacement' => 6, 'attaque' => 1, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 2, 'portee' => 'distance', 'attaque_distance' => 2,
                'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Tisseur putride', 'deplacement' => 7, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 2,
                'tier' => 'base', 'cout' => 3, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Crâne putride', 'deplacement' => 6, 'attaque' => 3, 'defense' => 2, 'pv_body' => 2, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 5, 'capacites' => ['racines_entravantes'], 'sorts_dread' => []],
            ['nom_base' => 'Raptor', 'deplacement' => 8, 'attaque' => 3, 'defense' => 2, 'pv_body' => 2, 'pv_mind' => 3,
                'tier' => 'base', 'cout' => 5, 'capacites' => ['tacticien'], 'sorts_dread' => []],
            ['nom_base' => 'Rampant putride', 'deplacement' => 7, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'cout' => 11,
                'capacites' => ['agile', 'venimeux', 'spawn' => ['creature' => 'Rejeton putride']],
                'sorts_dread' => []],
            ['nom_base' => 'Serpent géant', 'deplacement' => 8, 'attaque' => 4, 'defense' => 3, 'pv_body' => 6, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'cout' => 12, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['venimeux', 'spawn' => ['creature' => 'Rejeton putride']],
                'sorts_dread' => []],
            ['nom_base' => 'Singe géant', 'deplacement' => 8, 'attaque' => 4, 'defense' => 3, 'pv_body' => 7, 'pv_mind' => 5,
                'tier' => 'sous_boss', 'cout' => 12, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['agile'], 'sorts_dread' => []],
        ];

        foreach ($monstres as $monstre) {
            Monstre::updateOrCreate(['nom_base' => $monstre['nom_base']], $monstre);
        }
    }
}
