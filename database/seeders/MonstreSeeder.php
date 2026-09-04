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
                'tier' => 'base', 'boite' => 'base', 'cout' => 1, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Squelette', 'deplacement' => 6, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Zombie', 'deplacement' => 4, 'attaque' => 2, 'defense' => 3, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Orque', 'deplacement' => 8, 'attaque' => 3, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 2,
                'tier' => 'base', 'boite' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Fimir', 'deplacement' => 6, 'attaque' => 3, 'defense' => 3, 'pv_body' => 1, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'base', 'cout' => 3, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Momie', 'deplacement' => 4, 'attaque' => 3, 'defense' => 4, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'base', 'cout' => 3, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Guerrier du Chaos', 'deplacement' => 6, 'attaque' => 3, 'defense' => 4, 'pv_body' => 1, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'base', 'cout' => 3, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Gargouille', 'deplacement' => 6, 'attaque' => 4, 'defense' => 4, 'pv_body' => 1, 'pv_mind' => 4,
                'tier' => 'base', 'boite' => 'base', 'cout' => 4, 'capacites' => [], 'sorts_dread' => []],

            // Troll (carte « Cave Troll ») : la seule créature du paquet dont
            // le texte NOMME le feu — « Trolls may choose to regenerate 1 Body
            // point instead of attacking. Damage done by fire is permanent and
            // cannot be regenerated. » C'est ce qui donne un second lecteur au
            // type de dégât `feu`, à côté de l'Anneau de Feu : sans lui, la
            // nature d'un dégât n'aurait servi qu'à une carte défensive.
            // ⚠ Palier SOUS-BOSS, pas `base` (test de jeu du 2026-08-10). Il y a
            // été placé le 2026-08-09 en pensant à la mécanique du feu, pas au
            // budget de rencontre — et c'était une erreur de rangement : avec
            // 3 PV et 4 dés de défense, il a TROIS FOIS les PV de n'importe quel
            // autre monstre de base (tous à 1 depuis l'alignement sur les
            // cartes) et la meilleure défense du palier.
            //
            // Conséquence mesurée en partie réelle : le budget d'une quête 1 (14
            // points) a acheté « Troll + 2 gobelins + orque + squelette +
            // zombie » et l'a lâché sur deux héros de niveau 1 sans talent ni or.
            // Un barbare à 3 dés lui arrache 0,98 PV par attaque là où il tue
            // n'importe quel autre monstre de base d'un coup ; le troll, lui,
            // rend 1,40 PV par attaque — soit un magicien mort en 3 coups. TPK
            // dès la première salle avec un ennemi.
            ['nom_base' => 'Troll', 'deplacement' => 8, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'boite' => null, 'cout' => 9, 'capacites' => ['regeneration'], 'sorts_dread' => []],

            // ----- Gabarits élites (doc 09 §4 — exemples proposés, à équilibrer) -----
            // capacites = bibliothèque assignable (l'IA choisit l'habillage, le moteur résout)
            ['nom_base' => 'Champion', 'deplacement' => 7, 'attaque' => 4, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => null, 'cout' => 10,
                'capacites' => ['charge'],
                // ⚠ Le *Trait de Chaos* a quitté ce répertoire le 2026-09-04 :
                // c'était notre seul sort de Dread sans carte. L'*Éclair de
                // Chaos* (carte *Lightning Bolt*) reprend la frappe à distance
                // qu'il portait, en ligne droite et sans jet de défense.
                'sorts_dread' => ['Éclair de Chaos', 'Frayeur', 'Sommeil', 'Tempête de feu']],
            // ⚠ Son répertoire est passé en ARCHÉTYPE le 2026-09-04 : le pool de
            // rencontre finale se déclare en archétypes, et un boss qui n'en
            // porte pas ne peut plus être tiré du tout. Le Champion, lui, garde
            // sa liste brute — il reste le seul porteur en production du repli
            // de `repertoireSorts()`.
            ['nom_base' => 'Seigneur', 'deplacement' => 7, 'attaque' => 5, 'defense' => 5, 'pv_body' => 10, 'pv_mind' => 5,
                'tier' => 'boss', 'boite' => null, 'cout' => 20,
                'capacites' => ['invocation', 'frappe_de_zone'],
                'sorts_dread' => [], 'archetype_lanceur' => 'seigneur_du_chaos'],

            // ----- Sorciers nommés à répertoire dédié (3.8 — config/archetypes_lanceurs.php) -----
            // Le répertoire vient de l'archétype ; `sorts_dread` reste vide (l'archétype prime).
            // `cout` sous les leaders de tier (Champion 10 / Seigneur 20) pour ne pas changer
            // la rencontre finale auto-sélectionnée des quêtes.
            ['nom_base' => 'Chamane Gobelin', 'deplacement' => 8, 'attaque' => 2, 'defense' => 2, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => null, 'cout' => 9,
                'capacites' => [], 'sorts_dread' => [], 'archetype_lanceur' => 'chaman_orque'],
            ['nom_base' => 'Liche', 'deplacement' => 6, 'attaque' => 3, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 6,
                'tier' => 'boss', 'boite' => null, 'cout' => 18,
                'capacites' => ['invocation'], 'sorts_dread' => [], 'archetype_lanceur' => 'necromancien'],
            ['nom_base' => 'Sorcier des Tempêtes', 'deplacement' => 7, 'attaque' => 3, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 5,
                'tier' => 'boss', 'boite' => null, 'cout' => 17,
                'capacites' => [], 'sorts_dread' => [], 'archetype_lanceur' => 'maitre_tempetes'],

            // ----- Monstre à choix tactique (3.7) -----
            // `choix_attaque` : cible robuste (PV > seuil) → coup massif unique
            // (dés +massive_des_bonus) ; cible affaiblie → double_nombre attaques.
            // Décision 100 % moteur (ResolveurTour). `cout` sous le leader sous_boss.
            ['nom_base' => 'Ours polaire de guerre', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 6, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'boite' => 'horreur_des_glaces', 'cout' => 9,
                'capacites' => ['choix_attaque' => ['seuil' => 2, 'massive_des_bonus' => 2, 'double_nombre' => 2]],
                'sorts_dread' => []],

            // ----- Monstre à distance (3.4) -----
            // `portee` distance + `attaque_distance` (dés en tir) ; au contact il
            // perd un dé (attaque corps-à-corps moindre). Exige la ligne de vue.
            // Aligné sur la fiche officielle de *Jungles of Delthrak* (doc 18) :
            // « Attack 2 (1 adj.) » — 2 dés en tir, 1 seul au contact.
            ['nom_base' => 'Gobelin archer', 'deplacement' => 10, 'attaque' => 1, 'defense' => 1, 'pv_body' => 1, 'pv_mind' => 1,
                'tier' => 'base', 'boite' => 'jungles_delthrak', 'cout' => 2, 'portee' => 'distance', 'attaque_distance' => 2,
                'capacites' => [], 'sorts_dread' => []],

            // ----- Grande figurine multi-cases (3.9) -----
            // `grande_taille` : emprise 1×2 (deux cases). Adjacence/ligne de vue/
            // déplacement raisonnent sur l'emprise (moteur Grille).
            // Aligné sur la fiche officielle de *The Mage of the Mirror* (doc 18).
            ['nom_base' => 'Ogre', 'deplacement' => 4, 'attaque' => 6, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'boite' => 'mage_du_miroir', 'cout' => 10, 'grande_taille' => ['l' => 1, 'h' => 2],
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
            // « connaît *Dreadlights* et *Channel Dread*, chacun 1 fois par quête »
            // (doc 18). Premier monstre de tier BASE à lancer des sorts : c'est
            // lui qui a fait naître le palier `base` de `sorts_dread`.
            ['nom_base' => 'Cultiste du Dread', 'deplacement' => 7, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 2,
                'tier' => 'base', 'boite' => 'dread_moon', 'cout' => 2, 'capacites' => [], 'sorts_dread' => [],
                'archetype_lanceur' => 'culte_effroi'],
            // « mort-vivant et éthéré, lance *Channel Dread* à volonté » (doc 18).
            // Le « à volonté » reste borné par notre budget d'usages — un par
            // rencontre pour une créature de base.
            ['nom_base' => 'Spectre', 'deplacement' => 8, 'attaque' => 3, 'defense' => 3, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'dread_moon', 'cout' => 5, 'capacites' => ['ethere'], 'sorts_dread' => [],
                'archetype_lanceur' => 'spectre_hurlant'],
            ['nom_base' => 'Assassin', 'deplacement' => 10, 'attaque' => 5, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'dread_moon', 'cout' => 6, 'capacites' => [], 'sorts_dread' => []],
            // « connaît *Ball of Flame* et *Tempest*, chacun 1 fois par quête ».
            ['nom_base' => 'Garde-mage', 'deplacement' => 8, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'dread_moon', 'cout' => 8, 'capacites' => [], 'sorts_dread' => [],
                'archetype_lanceur' => 'garde_magus'],
            // « éthéré, connaît *Dreadlights, Channel Dread, Fear, Summon
            // Specters*, chacun 1 fois par quête » : le seul répertoire officiel
            // qui couvre les trois familles — marquer, blesser, appeler.
            // ⚠ **DIVERGENCE ASSUMÉE du livret** (René, 2026-09-04) : Defend **3**
            // là où le Dread Wraith de Rise of the Dread Moon est chiffré à 4.
            // Elle est ÉTHÉRÉE, donc une arme ne la blesse que sur un bouclier
            // noir (1/6) contre autant de dés qui parent sur 1/6 : les dégâts
            // nets valent (attaque − défense)/6. À 4 de défense, un groupe à 3
            // dés d'attaque ne pouvait PAS l'abattre — jamais, à aucun niveau
            // réaliste (30 tours encore à 5 dés). À 3, elle redevient un boss :
            // 15 tours à 5 dés d'attaque, 10 à 6 — exactement la fourchette du
            // Seigneur. La divergence est DÉCLARÉE dans `BestiaireSourceTest`
            // plutôt que dissimulée : c'est le seul écart de stat que nous nous
            // accordions sur une créature sourcée.
            ['nom_base' => 'Ombre du Dread', 'deplacement' => 9, 'attaque' => 6, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 5,
                'tier' => 'boss', 'boite' => 'dread_moon', 'cout' => 17, 'capacites' => ['ethere'], 'sorts_dread' => [],
                'archetype_lanceur' => 'spectre_effroi'],

            // ---- The Mage of the Mirror (doc 18) ----
            // L'archer elfe est la seconde créature à distance du bestiaire :
            // « Attack 4 (1 si adjacent) ».
            ['nom_base' => 'Archer elfe', 'deplacement' => 6, 'attaque' => 1, 'defense' => 2, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'base', 'boite' => 'mage_du_miroir', 'cout' => 5, 'portee' => 'distance', 'attaque_distance' => 4,
                'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Guerrier elfe', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'base', 'boite' => 'mage_du_miroir', 'cout' => 5, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Loup géant', 'deplacement' => 9, 'attaque' => 6, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 1,
                'tier' => 'sous_boss', 'boite' => 'mage_du_miroir', 'cout' => 11, 'capacites' => ['charge'], 'sorts_dread' => []],
            // ⚠ Le lanceur de l'*Invocation de loups* (René, 2026-09-04 : « on
            // devrait créer un boss elfique qui utiliserait Invocation de
            // loups »). Il n'a pas fallu l'inventer : c'est **Sinestra,
            // l'archemage**, boss final de la quête 9 de la boîte — Move 8 ·
            // Attack 4 · Defend 4 · Body 4 · Mind 9 (Mage of the Mirror p. 30,
            // doc 18). Le catalogue porte le TYPE et l'IA l'habille : « Sinestra »
            // est un nom propre, comme « Magrian » l'est pour l'Ombre du Dread.
            //
            // ⚠ **Mind 9**, la plus haute valeur du bestiaire : une archimage
            // ne s'endort pas. C'est la fiche qui le dit, pas nous.
            // Le `cout` en revanche est nôtre — il l'aligne sur les autres
            // bosses lanceurs (Liche 18, Sorcier des Tempêtes 17), sous le
            // Seigneur (20) pour ne pas déplacer la rencontre finale par défaut.
            ['nom_base' => 'Archimage elfe', 'deplacement' => 8, 'attaque' => 4, 'defense' => 4, 'pv_body' => 4, 'pv_mind' => 9,
                'tier' => 'boss', 'boite' => 'mage_du_miroir', 'cout' => 17, 'capacites' => [], 'sorts_dread' => [],
                'archetype_lanceur' => 'archimage_elfe'],

            // ---- The Frozen Horror (doc 18) ----
            ['nom_base' => 'Gremlin des glaces', 'deplacement' => 10, 'attaque' => 2, 'defense' => 3, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'horreur_des_glaces', 'cout' => 4, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Yéti', 'deplacement' => 8, 'attaque' => 3, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'boite' => 'horreur_des_glaces', 'cout' => 9, 'capacites' => [], 'sorts_dread' => []],
            // Boss de sa boîte. Grande figurine, comme l'ogre.
            // « connaît 6 sorts Dread fixes (*Chill, Ice Storm, Ice Wall, Mind
            // Freeze, Skate, Soothe*) + 6 au choix du MJ » (Frozen Horror
            // p. 37). Trois des six ne sont pas portés — voir l'archétype.
            ['nom_base' => 'Horreur des Glaces', 'deplacement' => 8, 'attaque' => 5, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'horreur_des_glaces', 'cout' => 16, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['resistance_magique'], 'sorts_dread' => [],
                'archetype_lanceur' => 'horreur_glacee'],

            // ---- Against the Ogre Horde (doc 18) ----
            ['nom_base' => 'Ogre guerrier', 'deplacement' => 6, 'attaque' => 5, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 1,
                'tier' => 'sous_boss', 'boite' => 'horde_ogre', 'cout' => 10, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Ogre champion', 'deplacement' => 6, 'attaque' => 5, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 1,
                'tier' => 'sous_boss', 'boite' => 'horde_ogre', 'cout' => 11, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Ogre commandant', 'deplacement' => 4, 'attaque' => 6, 'defense' => 5, 'pv_body' => 6, 'pv_mind' => 2,
                'tier' => 'boss', 'boite' => 'horde_ogre', 'cout' => 15, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['charge'], 'sorts_dread' => []],
            // 10 points de Body : la créature la plus résistante du catalogue.
            ['nom_base' => 'Seigneur ogre', 'deplacement' => 4, 'attaque' => 6, 'defense' => 6, 'pv_body' => 10, 'pv_mind' => 5,
                'tier' => 'boss', 'boite' => 'horde_ogre', 'cout' => 22, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['frappe_de_zone', 'resistance_magique'], 'sorts_dread' => []],

            // ---- Jungles of Delthrak (doc 18) ----
            // Attaque 0 au livret : le rejeton ne frappe pas, il S'ACCROCHE.
            // Son tour adjacent à un héros le convertit en JETON sur sa fiche —
            // 1 PV automatique et indéfendable par fin de tour, cumulable. Sa
            // Défense 0 et son Body 1 disent aussi comment on s'en débarrasse :
            // un seul crâne suffit à en détacher un (règle de retrait précisée
            // par René le 2026-08-10).
            ['nom_base' => 'Rejeton putride', 'deplacement' => 3, 'attaque' => 0, 'defense' => 0, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'jungles_delthrak', 'cout' => 2, 'capacites' => ['agile', 's_accroche'], 'sorts_dread' => []],
            ['nom_base' => 'Archer squelette', 'deplacement' => 6, 'attaque' => 1, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'jungles_delthrak', 'cout' => 2, 'portee' => 'distance', 'attaque_distance' => 2,
                'capacites' => [], 'sorts_dread' => []],
            // Monster Chart des Jungles of Delthrak p. 47 : « Sorts *Channel
            // Dread*, *Creeping Grasp* ». Il ne frappe presque pas (2 dés,
            // 1 PV) — il entrave, et laisse les autres faire le travail.
            ['nom_base' => 'Tisseur putride', 'deplacement' => 7, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 2,
                'tier' => 'base', 'boite' => 'jungles_delthrak', 'cout' => 3, 'capacites' => [], 'sorts_dread' => [],
                'archetype_lanceur' => 'tisseur_fleau'],
            ['nom_base' => 'Crâne putride', 'deplacement' => 6, 'attaque' => 3, 'defense' => 2, 'pv_body' => 2, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'jungles_delthrak', 'cout' => 5, 'capacites' => ['racines_entravantes'], 'sorts_dread' => []],
            ['nom_base' => 'Raptor', 'deplacement' => 8, 'attaque' => 3, 'defense' => 2, 'pv_body' => 2, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'jungles_delthrak', 'cout' => 5, 'capacites' => ['tacticien'], 'sorts_dread' => []],
            ['nom_base' => 'Rampant putride', 'deplacement' => 7, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => 'jungles_delthrak', 'cout' => 11,
                'capacites' => ['agile', 'venimeux', 'spawn' => ['creature' => 'Rejeton putride']],
                'sorts_dread' => []],
            ['nom_base' => 'Serpent géant', 'deplacement' => 8, 'attaque' => 4, 'defense' => 3, 'pv_body' => 6, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'jungles_delthrak', 'cout' => 12, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['venimeux', 'spawn' => ['creature' => 'Rejeton putride']],
                'sorts_dread' => []],
            ['nom_base' => 'Singe géant', 'deplacement' => 8, 'attaque' => 4, 'defense' => 3, 'pv_body' => 7, 'pv_mind' => 5,
                'tier' => 'sous_boss', 'boite' => 'jungles_delthrak', 'cout' => 12, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['agile'], 'sorts_dread' => []],
        ];

        foreach ($monstres as $monstre) {
            Monstre::updateOrCreate(['nom_base' => $monstre['nom_base']], $monstre);
        }
    }
}
