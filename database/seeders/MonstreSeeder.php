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
        // ----- Bestiaire de base : les 8 CARTES MONSTRE -----
        //
        // Aligné le 2026-08-09 sur `sjeng-monsters.pdf` (Ye Olde Inn), PUIS
        // RECORRIGÉ le 2026-10-05 sur les cartes OFFICIELLES elles-mêmes :
        // René a scanné son propre jeu de cartes de monstre (41 cartes,
        // `reference/20_cartes_monstres.md`) — une source plus directe que
        // le PDF de fan, puisque le *Rulebook* 2021 dit que la carte et le
        // tableau de l'écran du MJ portent le MÊME chiffre (LR p. 7). Les
        // cartes confirment le paquet de fan sur Gobelin/Squelette/Orque,
        // mais divergent sur Zombie/Momie/Guerrier du Chaos/Gargouille
        // (voir chaque ligne) : **la carte gagne, partout** (décision de
        // René, 2026-10-05 : « Valeurs des cartes, partout »).
        //
        // Deux recoupements indépendants restent valides par ailleurs :
        //   - la momie à 3 dés d'attaque, déduite de « It rolls 4 Attack
        //     dice INSTEAD OF 3 » (livret de quêtes p. 5) ;
        //   - squelette / zombie / momie à Mind 0, ce qui explique enfin
        //     « Sleep may not be used against mummies, zombies, or
        //     skeletons » (livret de règles p. 8) — Mind 0 = pas de jet.
        //
        // ⚠ PRINCIPE ABANDONNÉ (2026-10-05) : jusqu'ici, "au plateau, TOUT
        // monstre de base a 1 seul point de Body" justifiait d'écraser les
        // stats à 1 Body partout. Les cartes officielles le contredisent
        // directement — Momie 2, Guerrier du Chaos 3, Gargouille 3 — donc ce
        // n'était pas une règle du plateau, seulement ce qu'un PDF de fan
        // laissait deviner. Le garde-fou contre un monstre « increvable »
        // dans le tas des faibles n'est plus un plafond de Body : c'est le
        // `cout` (recalculé ci-dessous, mesuré comme pour les boss —
        // `docs/regles/bestiaire-et-rencontres.md`) qui route un monstre de
        // base devenu coûteux vers les « forts » du budget de rencontre
        // (`DemarreurQuete::acheterMonstres()`, seuil `seuil_cout_fort`),
        // achetés UN SEUL à la fois — exactement le mécanisme qui protège
        // déjà l'Assassin/Archer elfe/Guerrier elfe, tier `base` eux aussi.
        //
        // ⚠ Gobelin, Squelette et Orque sont extraits en VARIABLES (et non
        // laissés comme les cinq autres, de simples lignes du tableau) : leur
        // variante À DISTANCE générique (Q6, Against the Ogre Horde p. 8, bloc
        // plus bas) en est DÉRIVÉE — jamais recopiée à la main une deuxième
        // fois. C'est le défaut que ce lot corrige : avant lui, « Gobelin
        // archer » et « Archer squelette » portaient des nombres identiques à
        // leur base, tapés deux fois dans ce même fichier.
        $gobelin = ['nom_base' => 'Gobelin', 'deplacement' => 10, 'attaque' => 2, 'defense' => 1, 'pv_body' => 1, 'pv_mind' => 1,
            'tier' => 'base', 'boite' => 'base', 'cout' => 1, 'capacites' => [], 'sorts_dread' => []];
        $squelette = ['nom_base' => 'Squelette', 'deplacement' => 6, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 0,
            'tier' => 'base', 'boite' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []];
        $orque = ['nom_base' => 'Orque', 'deplacement' => 8, 'attaque' => 3, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 2,
            'tier' => 'base', 'boite' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []];

        /**
         * Variante À DISTANCE générique (Q6, René 2026-10-02 : « allons-y
         * générique »). Against the Ogre Horde p. 8, verbatim : « When Zargon
         * has the option to place a monster on the board, they may place a
         * standard monster or a ranged version of that same monster type (in
         * this quest pack, that means skeletons, orcs, and goblins). A ranged
         * monster rolls Attack dice equal to their standard attack score
         * against any non-adjacent target in their line of sight. If their
         * target is adjacent, they roll 1 Attack die. »
         *
         * UN SEUL point de passage pour la formule : déplacement/défense/Body/
         * Mind COPIÉS du monstre de base (identiques, comme les deux variantes
         * déjà en jeu le montraient déjà), `attaque` ramenée à 1 (contact) et
         * `attaque_distance` = l'attaque STANDARD du monstre de base (tir). Le
         * lien est déclaré par `variante_distance_de` (nom_base du monstre
         * standard), lu par `DemarreurQuete::variantesDistanceParBase()` —
         * c'est ce lien, pas une convention de nommage, qui fait d'une entrée
         * une variante.
         *
         * ⚠ `boite: null`, volontairement. La règle du livret commence par
         * « in this quest pack », mais son mécanisme (« Zargon may place a
         * standard monster or a ranged version ») ne dépend d'aucune règle
         * propre à la boîte Ogre Horde — c'est un remplacement générique à la
         * pose, comme on ferait au plateau avec n'importe quel figurine. René
         * a tranché Q6 dans ce sens : une règle utilisable par TOUS les
         * thèmes plutôt qu'un trait de boîte. Les deux variantes déjà seedées
         * (Gobelin archer, Archer squelette) étaient jusqu'ici rangées dans
         * `jungles_delthrak` — un vestige du premier portage (2026-08-10, avant
         * que la question des boîtes ne se pose), jamais un choix de règle —
         * et en sortent ici pour rejoindre nos propres blocs de stats
         * (Champion, Seigneur…), disponibles dans toute campagne.
         *
         * `cout` : aucune carte ne chiffre de budget de rencontre pour cette
         * variante (comme tout `cout` du bestiaire, c'est une valeur à nous).
         * Les deux variantes déjà en jeu gardent leur valeur (2 chacune), pour
         * ne pas rééquilibrer une rencontre qui tourne déjà ; l'Orque archer
         * est alignée sur le Gobelin archer (+1 sur le coût de son monstre de
         * base). ⚠ Incohérence préexistante et non corrigée ici : l'Archer
         * squelette n'a jamais porté cette prime (coût = celui du Squelette) —
         * un rééquilibrage séparé, hors du périmètre de ce lot.
         */
        $varianteDistance = function (array $base, string $nomVariante, int $coutVariante): array {
            return [
                'nom_base' => $nomVariante,
                'deplacement' => $base['deplacement'],
                'attaque' => 1,
                'portee' => 'distance',
                'attaque_distance' => $base['attaque'],
                'defense' => $base['defense'],
                'pv_body' => $base['pv_body'],
                'pv_mind' => $base['pv_mind'],
                'tier' => $base['tier'],
                'boite' => null,
                'cout' => $coutVariante,
                'variante_distance_de' => $base['nom_base'],
                'capacites' => [],
                'sorts_dread' => [],
            ];
        };

        $gobelinArcher = $varianteDistance($gobelin, 'Gobelin archer', 2);
        $archerSquelette = $varianteDistance($squelette, 'Archer squelette', 2);
        // Nouveau (lot B, Against the Ogre Horde) : l'Orque archer, troisième
        // et dernière variante nommée par le livret p. 8.
        $orqueArcher = $varianteDistance($orque, 'Orque archer', 3);

        $monstres = [
            $gobelin,
            $squelette,
            // Carte « Zombie » (p02, © 2021) : Déplacement **5**, contre 4 au
            // paquet de fan (`sjeng-monsters.pdf`) — seule case qui change.
            // Attaque/Défense/Body/Mind identiques des deux côtés, donc le
            // `cout` (il ne mesure que la survie/puissance de frappe, pas la
            // vitesse) reste à 2 — reference/20_cartes_monstres.md.
            ['nom_base' => 'Zombie', 'deplacement' => 5, 'attaque' => 2, 'defense' => 3, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            $orque,
            ['nom_base' => 'Fimir', 'deplacement' => 6, 'attaque' => 3, 'defense' => 3, 'pv_body' => 1, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'base', 'cout' => 3, 'capacites' => [], 'sorts_dread' => []],
            // Carte « Mummy » (p01, © 2021) : Body **2**, contre 1 au paquet
            // de fan — seule divergence. `cout` recalculé par la même mesure
            // que les boss (`docs/regles/bestiaire-et-rencontres.md`,
            // attaques-à-3-dés pour abattre = Body / (1.5 − Défense/6)) :
            // 1.2 → 2.4 attaques, comparable au Crâne putride/Raptor (1.7
            // attaques, cout 5) à Défense moindre — 3 → 5.
            ['nom_base' => 'Momie', 'deplacement' => 4, 'attaque' => 3, 'defense' => 4, 'pv_body' => 2, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'base', 'cout' => 5, 'capacites' => [], 'sorts_dread' => []],
            // Carte « Dread Warrior » (p03, © 2021 — 1989 « Chaos Warrior »,
            // déjà documenté) : Déplacement **7**, Attaque **4**, Body **3**,
            // contre 6/3/1 au paquet de fan — trois divergences. Mêmes stats
            // A/D/B/Mi que notre Garde-mage (sous_boss, cout 8) à un point de
            // Déplacement près : `cout` recalculé par la même mesure (3.6
            // attaques-à-3-dés, contre 1.2 avant) — 3 → 6, au niveau de
            // l'Assassin (même tier `base`, même méthode).
            ['nom_base' => 'Guerrier du Chaos', 'deplacement' => 7, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'base', 'cout' => 6, 'capacites' => [], 'sorts_dread' => []],
            // Carte « Gargoyle » (p05, © 2021) : Défense **5**, Body **3**,
            // contre 4/1 au paquet de fan — deux divergences (Déplacement/
            // Attaque/Mind identiques). `cout` recalculé par la même mesure
            // (4.5 attaques-à-3-dés, contre 1.2 avant) — 4 → 7, la créature
            // la plus endurante du tier `base` (sans capacité).
            ['nom_base' => 'Gargouille', 'deplacement' => 6, 'attaque' => 4, 'defense' => 5, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'base', 'boite' => 'base', 'cout' => 7, 'capacites' => [], 'sorts_dread' => []],

            // ----- Variantes À DISTANCE génériques (Q6) -----
            $gobelinArcher,
            $archerSquelette,
            $orqueArcher,

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
            // sa liste brute : c'était jusqu'au 2026-09-30 le seul porteur en
            // production du repli de `repertoireSorts()`. Le Dragon de First
            // Light (plus bas) l'a rejoint — sa carte ne nomme QU'UN sort, pas
            // un répertoire de sorcier, et lui fabriquer un archétype pour une
            // seule entrée aurait été la donnée décorative inverse : un
            // registre rempli pour la forme plutôt que pour un besoin réel.
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

            // ----- Deux attaques par tour (Frozen Horror p. 37) -----
            // `deux_attaques` : « attacks once with its mighty paw and once with
            // its spiked mace » — sur une cible, ou une sur chacune de deux.
            // Remplace `choix_attaque` (mécanique de nous, 2026-10-04). Décision
            // 100 % moteur (`ResolveurTour::deuxAttaques()`). `cout` sous le leader sous_boss.
            ['nom_base' => 'Ours polaire de guerre', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 6, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'boite' => 'horreur_des_glaces', 'cout' => 9,
                'capacites' => ['deux_attaques'],
                'sorts_dread' => []],

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

            // ---- L'Abomination (monstre de la boîte de BASE, pas de Kellar's
            //      Keep) : CORRIGÉ le 2026-10-05. Carte « Abomination » (p11,
            //      © 2021, reference/20_cartes_monstres.md) : 6/3/3/2/3, pas de
            //      texte de capacité. Jusqu'ici volontairement absente du
            //      catalogue (doc 18 note † : « ses stats ne sont chiffrées
            //      dans aucun livret, seulement dans une table de tournoi
            //      d'une autre boîte ») — ce n'est plus vrai, la carte donne le
            //      bloc complet, directement du jeu de base dont elle fait
            //      partie (LR p. 4 : « 3 abominations », dans le MÊME
            //      inventaire que les 7 autres monstres de base ; confirmé
            //      aussi par First Light, reference/18_extensions.md §2).
            //      `tier => 'base'`, `boite => 'base'` — COMME LES AUTRES
            //      monstres de base (pas `boite => null`, réservé à nos
            //      propres créations) : c'est un monstre de la boîte de base,
            //      au même titre que le Gobelin ou la Gargouille, donc il doit
            //      apparaître dans les rencontres comme eux.
            //      `cout` par la même mesure que les quatre lignes ci-dessus
            //      (Body / (1.5 − Défense/6) = 2.0 attaques-à-3-dés, même
            //      profil de survie que l'Assassin mais avec 3 dés d'attaque
            //      au lieu de 5) : alignée sur le Crâne putride/Raptor (cout 5).
            ['nom_base' => 'Abomination', 'deplacement' => 6, 'attaque' => 3, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'base', 'cout' => 5, 'capacites' => [], 'sorts_dread' => []],

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
            // CORRIGÉ le 2026-10-05 : la carte « Assassin » (p15, © 2023,
            // reference/20_cartes_monstres.md) porte un texte de capacité que
            // la première lecture (doc 18) avait laissé tomber : « Each
            // Assassin may attack diagonally. » Le mot-clé `attaque_diagonale`
            // existait déjà pour les armes longues et les mercenaires — jamais
            // pour un monstre : troisième lecteur au même mot-clé, branché au
            // point de passage qui décide l'adjacence d'attaque d'un monstre
            // (`ResolveurTour::jouerMonstre()`, `$diagonalesMonstre`).
            ['nom_base' => 'Assassin', 'deplacement' => 10, 'attaque' => 5, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'dread_moon', 'cout' => 6, 'capacites' => ['attaque_diagonale'], 'sorts_dread' => []],
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

            // SIR RAGNAR (boss, quête 9, p. 31 — docs/plan-dread-moon.md lot I,
            // porté AVEC Ogre Horde comme René l'a tranché le 2026-10-04).
            // Stats relues M/A/D/B/Mi : 5/5/5/4/4 (même ordre que Magrian/
            // l'Ombre du Dread ci-dessus — confirmé par recoupement : « 9/6/4/5/5 »
            // de Magrian donne M9 A6 D4 B5 Mi5, exactement les valeurs DÉJÀ
            // seedées de l'Ombre du Dread avant divergence assumée de Défense).
            // Règle propre, verbatim : « The first time Sir Ragnar's Body
            // Points are reduced to 0, they are instead reduced to 1 » — PAS
            // le mot-clé `phases` (aucune nouvelle statistique, aucune
            // deuxième forme) : une capacité `increvable_une_fois`, lue au
            // MÊME point de passage unique que les phases
            // (`MoteurDegats::infligerAMonstre()`), une fois pour toute la
            // rencontre.
            ['nom_base' => 'Sir Ragnar', 'deplacement' => 5, 'attaque' => 5, 'defense' => 5, 'pv_body' => 4, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'dread_moon', 'cout' => 14, 'capacites' => ['increvable_une_fois'], 'sorts_dread' => []],

            // ⚠ Magrian, le Dread Wraith (boss final, quête 10, p. 33) reste
            // NON semée comme créature nommée distincte : ses QUATRE
            // capacités à usage unique sans action (*Terror*, *Consume
            // Magic*, *Reflection*, *Shift Reality*) dépassent le vocabulaire
            // `reactions_defense` construit ici pour Gruzbella/Gretzl
            // (`ignore_degats_attaque` seul) — *Reflection* redirige un coup
            // APRÈS l'avoir subi, *Shift Reality* lance un sort en DÉBUT de
            // tour : deux mécaniques sans point de passage encore construit.
            // L'« Ombre du Dread » ci-dessus reste son habillage, exactement
            // comme avant ce chantier (`docs/plan-dread-moon.md` lot I le dit
            // explicitement : « Magrian redevient un sous-boss nommé » une
            // fois CES patrons-là construits, pas celui des phases).

            // ---- The Mage of the Mirror (doc 18) ----
            // L'archer elfe est la seconde créature à distance du bestiaire :
            // « Attack 4 (1 si adjacent) ».
            ['nom_base' => 'Archer elfe', 'deplacement' => 6, 'attaque' => 1, 'defense' => 2, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'base', 'boite' => 'mage_du_miroir', 'cout' => 5, 'portee' => 'distance', 'attaque_distance' => 4,
                'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Guerrier elfe', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'base', 'boite' => 'mage_du_miroir', 'cout' => 5, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Loup géant', 'deplacement' => 9, 'attaque' => 6, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 1,
                // `grande_taille` : le livret nomme le Loup géant grande figurine ;
                // l'Ogre garde aussi ses 2 cases (René, 2026-10-04).
                'tier' => 'sous_boss', 'boite' => 'mage_du_miroir', 'cout' => 11, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['charge'], 'sorts_dread' => []],
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
            // « attacks OR steals an object (never an equipped weapon/armour/
            // shield) then flees at full speed ; the object is lost if no hero
            // sees it at the start of the GM's next turn » — `vol_objet`,
            // portée par `MoteurDread::voler()`.
            ['nom_base' => 'Gremlin des glaces', 'deplacement' => 10, 'attaque' => 2, 'defense' => 3, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'base', 'boite' => 'horreur_des_glaces', 'cout' => 4, 'capacites' => ['vol_objet'], 'sorts_dread' => []],
            // « dès qu'il inflige au moins 1 Body Point, agrippe le héros… » —
            // `etreinte`, portée par `ResolveurTour::resoudreAttaqueMonstre()`
            // (établissement) et `saignerParConditions()`/`jouerMonstre()`
            // (saignement automatique + interdiction d'attaquer tant qu'il tient).
            ['nom_base' => 'Yéti', 'deplacement' => 8, 'attaque' => 3, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'boite' => 'horreur_des_glaces', 'cout' => 9, 'capacites' => ['etreinte'], 'sorts_dread' => []],
            // Boss de sa boîte. Grande figurine, comme l'ogre.
            // « connaît 6 sorts Dread fixes (*Chill, Ice Storm, Ice Wall, Mind
            // Freeze, Skate, Soothe*) + 6 au choix du MJ » (Frozen Horror
            // p. 37). Trois des six ne sont pas portés — voir l'archétype.
            ['nom_base' => 'Horreur des Glaces', 'deplacement' => 8, 'attaque' => 5, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'horreur_des_glaces', 'cout' => 16, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['resistance_magique'], 'sorts_dread' => [],
                'archetype_lanceur' => 'horreur_glacee'],

            // ---- Against the Ogre Horde (doc 18) ----
            // ⚠ L'Orque archer (variante à distance, Q6, p. 8) n'est PAS ici :
            // elle est générique (`boite: null`) et définie en tête de
            // méthode, avec Gobelin archer et Archer squelette — voir
            // `$orqueArcher` plus haut.
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

            // Doralf, pit fighter ogre (q. 2, Against the Ogre Horde p. 21) —
            // M6 A5 D6 B7 Mi3 (ordre de lecture du plan : A/D/M/B/Mi). Tier
            // SOUS-BOSS (Q7, René 2026-10-02) malgré une résistance de boss
            // (~10,5 attaques de héros à 3 dés pour l'abattre) : « il n'a
            // AUCUNE capacité, une brute très dure » — d'où un `cout` au
            // sommet du palier sous-boss plutôt qu'au niveau d'un boss.
            ['nom_base' => 'Doralf', 'deplacement' => 6, 'attaque' => 6, 'defense' => 5, 'pv_body' => 7, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'horde_ogre', 'cout' => 13, 'capacites' => [], 'sorts_dread' => []],

            // MONSTRE À PHASES (chantier transverse 2026-10-04, lot C,
            // Against the Ogre Horde p. 6 et 21) : « Some powerful foes adopt
            // new statistics as the heroes battle them […] still considered
            // the same monster for game effects such as spells. » À 0 Body,
            // l'instance adopte la ligne nommée par `phase_suivante` au lieu
            // de mourir — lu par l'UNIQUE point de passage,
            // `MoteurDegats::infligerAMonstre()`. `null` = dernière phase.
            //
            // GRUZBELLA HAMMERHAND (q. 3, p. 21), BOSS de la boîte — jusqu'ici
            // « pauvre en boss », n'ayant que le Seigneur ogre. 3 formes,
            // stats relues A/D/M/B/Mi : Confiante 4/6/5/5/4 → Déterminée
            // 5/5/7/5/4 → Imprudente 6/1/8/5/4 (Body et Mind inchangés, seule
            // la fougue grimpe et la garde tombe). `reactions_defense` :
            // SEULE *Resilience* (« ignore tous les dégâts d'une attaque »,
            // une fois pour toute la rencontre) est portée — *Break* (« met
            // fin à un sort actif sur elle ») et *Deflect* (« redirige
            // l'attaque vers un héros dans ses 10 cases ») attendent un
            // patron non construit ici (interception du ciblage / de la pose
            // de condition sur toute la rencontre plutôt qu'au seul point de
            // passage de la mort) — DÉLIBÉRÉMENT absentes plutôt que
            // déclarées sans lecteur. Vaincue (dernière phase à 0 Body),
            // « elle s'incline » : `recompense_reddition` crédite 1000 po au
            // groupe au lieu d'une mort — ELLE N'EST PAS maléfique (p. 2).
            // `cout` : somme des attaques-à-3-dés par phase (10 + 7,5 + 3,75
            // ≈ 21,25), arrondie au niveau du Seigneur ogre (22) dont elle
            // partage la résistance totale.
            ['nom_base' => 'Gruzbella Hammerhand', 'deplacement' => 5, 'attaque' => 4, 'defense' => 6, 'pv_body' => 5, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'horde_ogre', 'cout' => 23,
                'capacites' => ['reactions_defense' => ['ignore_degats_attaque']],
                'sorts_dread' => [], 'phase_suivante' => 'Gruzbella Déterminée'],
            ['nom_base' => 'Gruzbella Déterminée', 'deplacement' => 7, 'attaque' => 5, 'defense' => 5, 'pv_body' => 5, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'horde_ogre', 'cout' => 23,
                'capacites' => ['reactions_defense' => ['ignore_degats_attaque']],
                'sorts_dread' => [], 'phase_suivante' => 'Gruzbella Imprudente'],
            ['nom_base' => 'Gruzbella Imprudente', 'deplacement' => 8, 'attaque' => 6, 'defense' => 1, 'pv_body' => 5, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'horde_ogre', 'cout' => 23,
                'capacites' => ['reactions_defense' => ['ignore_degats_attaque'], 'recompense_reddition' => ['or' => 1000]],
                'sorts_dread' => [], 'phase_suivante' => null],

            // SPAWN OF THE PIT (q. 1, p. 21), SOUS-BOSS (Q7). 2 formes, stats
            // relues A/D/M/B/Mi : 4/3/6/4/3 → Enraged 5/1/10/6/1. Aucune
            // capacité réactive sourcée pour ce monstre (le livret n'en donne
            // aucune, contrairement à Gruzbella) — seules les phases.
            ['nom_base' => 'Spawn of the Pit', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 4, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'horde_ogre', 'cout' => 12, 'capacites' => [], 'sorts_dread' => [],
                'phase_suivante' => 'Spawn of the Pit déchaîné'],
            ['nom_base' => 'Spawn of the Pit déchaîné', 'deplacement' => 10, 'attaque' => 5, 'defense' => 1, 'pv_body' => 6, 'pv_mind' => 1,
                'tier' => 'sous_boss', 'boite' => 'horde_ogre', 'cout' => 12, 'capacites' => [], 'sorts_dread' => [],
                'phase_suivante' => null],

            // ⚠ Guardian Effigy (q. 4, p. 27 — stats A3 D5 M0 B2 Mi0 déjà
            // relevées en lot A du plan Ogre Horde) reste NON semée ici :
            // « immobile, boule de feu à 3 dés sur un héros en vue chaque
            // tour, immunisée à tous les sorts » est un patron de TOURELLE
            // (déplacement 0 + attaque à distance automatique + immunité
            // totale aux sorts) qu'aucun lecteur du moteur ne construit
            // aujourd'hui — nommé plutôt que deviné, comme demandé (René,
            // 2026-10-04). Un sous-boss de plus sans ce patron serait une
            // brute ordinaire qui ne bougerait jamais, pas l'Effigie.

            // ---- Jungles of Delthrak (doc 18) ----
            // Attaque 0 au livret : le rejeton ne frappe pas, il S'ACCROCHE.
            // Son tour adjacent à un héros le convertit en JETON sur sa fiche —
            // 1 PV automatique et indéfendable par fin de tour, cumulable. Sa
            // Défense 0 et son Body 1 disent aussi comment on s'en débarrasse :
            // un seul crâne suffit à en détacher un (règle de retrait précisée
            // par René le 2026-08-10).
            // CORRIGÉ le 2026-10-05 : la carte « Spawnling » (p38, © 2024,
            // reference/20_cartes_monstres.md) porte mot pour mot « Venomous.
            // Agile. » — `venimeux` manquait. Sans effet pratique observable
            // tant que son Attaque reste à 0 (le lecteur de `venimeux` ne se
            // déclenche que sur un coup au corps-à-corps qui touche, ce que ce
            // monstre ne porte jamais) : ajoutée quand même, le registre « rien
            // de non lu » valant aussi pour une capacité sans conséquence
            // pratique actuelle — la carte le dit, le catalogue le cite.
            ['nom_base' => 'Rejeton putride', 'deplacement' => 3, 'attaque' => 0, 'defense' => 0, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'jungles_delthrak', 'cout' => 2,
                'capacites' => ['agile', 's_accroche', 'venimeux'], 'sorts_dread' => []],
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

            // GRETZL LA PORTE-FLÉAU (q. 12A, boss final — docs/plan-delthrak.md
            // lot A, reference/18_extensions.md l. 1376-1385) : PREMIER boss
            // du thème `jungles_delthrak`, resté « actif mais sans boss »
            // depuis le premier portage (2026-08-10). MONSTRE À PHASES (même
            // mécanisme que Gruzbella plus haut, chantier transverse
            // 2026-10-04) — 3 formes, stats relues M/A/D/B/Mi : Phase 1 M6 A4
            // D3 B5 Mi6 ; Phase 2 « Demonspider » M8 A5 D4 B4 Mi3 (Agile,
            // Venimeux) ; Phase 3 « Demonape » M8 A6 D2 B6 Mi1 (Agile).
            // ⚠ L'astérisque sur l'Attaque des trois phases EST sourcé et
            // résolu (reference/18, note de bas de tableau) : « tir à
            // distance possible en ligne de vue », aux MÊMES dés que
            // l'attaque de contact — ni un malus de portée (contrairement à
            // l'Archer elfe) ni une seconde valeur. NON porté ici : décider
            // au tour d'un monstre entre contact et distance, à dés
            // identiques, demanderait de toucher la sélection de cible du
            // tour monstre (`MoteurDread`), hors du périmètre de ce chantier
            // — nommé plutôt que deviné, même traitement que Deflect/Break.
            //
            // Sorts (archétype `gretzl_porte_fleau`, config/archetypes_lanceurs.php),
            // identiques dans les trois phases — rien ne dit qu'elle désapprend
            // un sort en changeant de forme : Étreinte des Ronces, Canaliser
            // l'Effroi, Frayeur, TOUS déjà semés (SortDreadSeeder).
            //
            // `reactions_defense` : *Demon Wings* (« ignore tous les dégâts
            // d'une attaque », `ignore_degats_attaque`) est portée, dans les
            // TROIS phases — « toujours le même monstre » pour ses capacités
            // aussi. *Dispel* (« annule un sort la ciblant ») reste absente
            // ici, même raison que *Break* chez Gruzbella (interception de la
            // pose de condition sur toute une rencontre : patron non construit,
            // nommé plutôt que deviné).
            //
            // `cout` : somme des attaques-à-3-dés par phase (5 + 4,8 + 5,14
            // ≈ 14,9), interpolée comme le Dragon (First Light) entre les
            // bosses existants plutôt qu'inventée au hasard.
            // « *Gretzl may choose to fire at range at any hero in her line of
            // sight » (livret p. 35, l'astérisque des TROIS formes) : `tir_au_choix`,
            // construit pour Gruulob (2026-10-09).
            ['nom_base' => 'Gretzl la Porte-Fléau', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 6,
                'tier' => 'boss', 'boite' => 'jungles_delthrak', 'cout' => 18,
                'capacites' => ['tir_au_choix', 'reactions_defense' => ['ignore_degats_attaque']],
                'sorts_dread' => [], 'archetype_lanceur' => 'gretzl_porte_fleau',
                'phase_suivante' => 'Demonspider'],
            ['nom_base' => 'Demonspider', 'deplacement' => 8, 'attaque' => 5, 'defense' => 4, 'pv_body' => 4, 'pv_mind' => 3,
                'tier' => 'boss', 'boite' => 'jungles_delthrak', 'cout' => 18,
                'capacites' => ['agile', 'venimeux', 'tir_au_choix', 'reactions_defense' => ['ignore_degats_attaque']],
                'sorts_dread' => [], 'archetype_lanceur' => 'gretzl_porte_fleau',
                'phase_suivante' => 'Demonape'],
            ['nom_base' => 'Demonape', 'deplacement' => 8, 'attaque' => 6, 'defense' => 2, 'pv_body' => 6, 'pv_mind' => 1,
                'tier' => 'boss', 'boite' => 'jungles_delthrak', 'cout' => 18,
                'capacites' => ['agile', 'tir_au_choix', 'reactions_defense' => ['ignore_degats_attaque']],
                'sorts_dread' => [], 'archetype_lanceur' => 'gretzl_porte_fleau',
                'phase_suivante' => null],

            // GRUULOB, SORCIER GOBELIN CORROMPU (Jungles of Delthrak, quête 8,
            // p. 27 — second boss à phases du thème, docs/plan-delthrak-execution-
            // 2026-10-09.md lot C). MONSTRE À PHASES, même mécanisme que Gretzl :
            // à 0 Body, « do not remove the miniature from the map. Instead, adopt
            // the statistics of Gruulob, Demon Form and continue the battle ».
            // Stats relues sur le livret : Phase 1 M6 A3 D4 B4 Mi5 ; Forme
            // Démoniaque M6 A4 D5 B3 Mi4 (Movement / Attack / Defend / Body / Mind).
            // Le nom de la forme est la traduction de « Demon Form » déjà portée
            // par reference/18_extensions.md.
            //
            // Sorts (archétype `gruulob_sorcier_gobelin`) : « Gruulob is a powerful
            // servant of Zargon and knows the following Dread spells: Creeping
            // Grasp, Channel Dread and Summon Orcs* (*Summons the same number of
            // Goblins instead) » — le répertoire vaut pour les DEUX formes (« still
            // considered the same monster for game effects such as spells »), donc
            // l'archétype est posé sur les deux lignes, comme pour Gretzl. Le
            // troisième sort est la VARIANTE gobeline, `Invocation de gobelins`
            // (SortDreadSeeder), et non `Invocation d'orques`.
            //
            // TIR AU CHOIX, DEUX FORMES (2026-10-09, René — « In both forms, they may
            // choose to fire at range at any hero in their line of sight », livret p. 27) :
            // `tir_au_choix` sur les DEUX lignes. Un monstre de mêlée qui voit un héros tire
            // sur place avec ses dés d'attaque, et frappe au contact — sans reculer (le recul
            // est l'archer). Lu par `ResolveurTour::jouerMonstre()`.
            //
            // EFFET GLOBAL DE QUÊTE (note A, même page : « All Goblins in this quest are elite
            // warriors dedicated to Gruulob and roll 1 additional Attack die ») : déclaré sur
            // la PREMIÈRE forme seulement — c'est elle qui entre dans la quête. Figé au
            // démarrage (`EffetsGlobauxQuete::etablir()`) : la forme démoniaque n'a pas à le
            // porter, et l'effet tient jusqu'à la fin de la quête même si Gruulob tombe.
            // Faction « Gobelin » = le monstre de base et son archer (`estDeFaction()`) :
            // Gruulob lui-même (« Gruulob, … ») et le Chamane Gobelin (nôtre, pas du livret)
            // n'en font pas partie — décision à confirmer par René.
            //
            // Aucune capacité RÉACTIVE dans le livret (contrairement à Gretzl et *Demon
            // Wings*) : aucune entrée de `reactions_defense`.
            //
            // `cout` : somme des attaques-à-3-dés par phase — Body / (1.5 − Défense/6)
            // = 4 / 0,833 + 3 / 0,667 = 4,8 + 4,5 = 9,3 — placée entre l'Ogre
            // commandant (9,0 attaques → cout 15) et le Dragon (10,5 → 16), soit
            // 15,2 → 15 : la même méthode que Gretzl, sans chiffre inventé.
            ['nom_base' => 'Gruulob, Sorcier Gobelin Corrompu', 'deplacement' => 6, 'attaque' => 3, 'defense' => 4, 'pv_body' => 4, 'pv_mind' => 5,
                'tier' => 'boss', 'boite' => 'jungles_delthrak', 'cout' => 15,
                'capacites' => ['tir_au_choix',
                    'effet_global_quete' => ['titre' => 'Les gobelins de Gruulob', 'faction' => 'Gobelin', 'volee' => 'attaque', 'des' => 1]],
                'sorts_dread' => [], 'archetype_lanceur' => 'gruulob_sorcier_gobelin',
                'phase_suivante' => 'Gruulob, Forme Démoniaque'],
            ['nom_base' => 'Gruulob, Forme Démoniaque', 'deplacement' => 6, 'attaque' => 4, 'defense' => 5, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'jungles_delthrak', 'cout' => 15,
                'capacites' => ['tir_au_choix'],
                'sorts_dread' => [], 'archetype_lanceur' => 'gruulob_sorcier_gobelin',
                'phase_suivante' => null],

            // ---- First Light (2024) ----
            // Source : carte de monstre « Dragon » photographiée par René
            // (2026-09-30), © 2024 Hasbro — reference/18_extensions.md §6.1bis.
            // Texte intégral de la carte : « The dragon uses Draconic Flight
            // and may cast Ball of Flame at will. »
            //  - grande_taille : la figurine occupe 2 cases (photo de la
            //    figurine + symbole de carte ovale 2 cases de large, FL-Q
            //    p. 9) — même emprise que l'Ogre et tous les autres grands
            //    monstres du catalogue (`['l' => 1, 'h' => 2]`, purement
            //    géométrique, `AssembleurCarte`/`FabriqueGrille` ne
            //    distinguent pas largeur et hauteur).
            //  - sorts_dread : liste BRUTE et non un archétype — la carte ne
            //    nomme qu'UN sort, pas un répertoire de sorcier nommé (voir
            //    le commentaire du Champion, plus haut).
            //  - capacites : `sort_a_volonte` (Boule de Flammes sans compteur
            //    d'usage, pour ce monstre SEUL — René, 2026-09-30,
            //    `MoteurDread::sortAVolonte()`) et `vol_draconique`
            //    (Draconic Flight — traversée des figures en approche,
            //    `MoteurDread::tentativeVolDraconique()`, FL-Q p. 7).
            //  - cout : NOTRE valeur (aucune carte ne chiffre un budget de
            //    rencontre). Mesurée comme les autres bosses
            //    (`docs/regles/bestiaire-et-rencontres.md` — attaques d'un
            //    héros à 3 dés pour l'abattre : Body / (1.5 − Défense/6)) en
            //    interpolant entre les deux SEULES créatures qui partagent sa
            //    Défense (5) : Seigneur (Body 10, cout 20, 15 attaques) et
            //    Ogre commandant (Body 6, cout 15, 9 attaques). Le Dragon
            //    (Body 7, 10.5 attaques) tombe à 15 + 1.25×(7−6) ≈ 16.
            ['nom_base' => 'Dragon', 'deplacement' => 10, 'attaque' => 5, 'defense' => 5, 'pv_body' => 7, 'pv_mind' => 6,
                'tier' => 'boss', 'boite' => 'first_light', 'cout' => 16, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['vol_draconique', 'sort_a_volonte' => ['sort' => 'Boule de Flammes']],
                'sorts_dread' => ['Boule de Flammes']],

            // ---- Prophecy of Telor (docs/plan-telor.md lot E) ----
            // SORCIER DU DREAD — monstre GÉNÉRIQUE (deux apparitions, q. 7 et
            // q. 9, mêmes stats CONFIRMÉES identiques sur le rendu PNG des
            // deux pages). Stats relues M/A/D/B/Mi : 8/4/4/3/4. Tier SOUS_BOSS
            // (Q3, René) — un répertoire qui porte malgré tout DEUX sorts de
            // palier `boss` (Nuée d'Effroi, Commandement) : l'archétype se
            // déclare COMPLET (les cinq sorts des deux quêtes : Boule de
            // Flammes, Tourmente, Frayeur, Nuée d'Effroi, Commandement, TOUS
            // déjà semés), et c'est le FILTRE PAR PALIER de
            // `MoteurDread::sortsDisponibles()` qui retire silencieusement les
            // deux sorts `boss` pour une créature `sous_boss` — exactement le
            // même mécanisme qui réduit déjà le répertoire du Chamane Gobelin.
            // Rien à construire : pure donnée, le palier existant suffit.
            // `cout` : même profil de résistance que le Garde-mage (Body 3,
            // Défense 4 → 3,6 attaques-à-3-dés), même valeur.
            ['nom_base' => 'Sorcier du Dread', 'deplacement' => 8, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => 'prophecy_telor', 'cout' => 8, 'capacites' => [], 'sorts_dread' => [],
                'archetype_lanceur' => 'sorcier_dread_telor'],

            // ---- Wizards of Morcar : les Sorciers du Dread (vague 2A) ----
            // ⚠ PALIERS (vague 2C, 2026-10-08) : QUATRE sous-boss + UN boss. Le livret
            // G1504 appelle Storm Master, High Mage, Necromancer et Orc Warcaster les
            // « lieutenants de Morcar » (quêtes 2-8 : « Defeat the High Mage to complete
            // this quest », p. 24 ; « the next Lieutenant of Morcar you must defeat »,
            // p. 28 ; « Only one Lieutenant of Morcar remains », p. 30) — un par quête,
            // jamais l'adversaire de la campagne. Le dernier mot est à la GARDIENNE
            // (Artificer, « the Keeper », quête 9 : « The Keeper has all the Artificer
            // spells […] When the Keeper is defeated, she vanishes », p. 38) : elle est
            // le seul boss ; la fin de campagne est le Haut Autel (quête 10). Cela tient
            // dans « un seul boss par campagne, jusqu'à quatre sous-boss »
            // (`JalonsCampagne::nbSousBossAttendu()`). `cout` des quatre lieutenants :
            // REMESURÉ sur la droite des sous-boss (Ogre champion 7,2 attaques → 11,
            // Minotaure 9 → 12, Doralf 10,5 → 13) : Storm Master 10 attaques → 13,
            // Necromancer 8 → 12, Warcaster 7,5 → 12, High Mage 6 → 10.
            // Un lieutenant garde ses SIX sorts : `MoteurDread::sortsDisponibles()` ne
            // filtre pas par palier un `sorts_uniques`.
            // Stats : tableau des monstres du livret G1504 p. 40-41 (Mouvement /
            // Attaque / Défense / Body / Mind) — Storm Master 6/4/6/5/7, High Mage
            // 5/5/5/4/8, Necromancer 6/4/6/4/7. `sorts_uniques` : « Each spell may
            // only be used once per quest […] a full set of six spells » (p. 10),
            // lu par `MoteurDread` (usages = taille du répertoire, un sort une seule
            // fois). Tier `boss` : chacun est l'adversaire final de sa quête
            // (« Defeat the Storm Master to complete this quest »).
            // `cout` : NOTRE valeur, mesurée comme les autres bosses
            // (`docs/regles/bestiaire-et-rencontres.md`) — attaques d'un héros à 3 dés
            // pour l'abattre, Body / (1,5 − Défense/6), sur la droite Ogre commandant
            // (9 attaques, 15) — Seigneur (15 attaques, 20) : Storm Master 10 → 16,
            // Necromancer 8 → 14, High Mage 6 → 13.
            ['nom_base' => 'Maître des orages', 'deplacement' => 6, 'attaque' => 4, 'defense' => 6, 'pv_body' => 5, 'pv_mind' => 7,
                'tier' => 'sous_boss', 'boite' => 'wizards_of_morcar', 'cout' => 13, 'capacites' => ['sorts_uniques'], 'sorts_dread' => [],
                'archetype_lanceur' => 'orages_morcar'],
            ['nom_base' => 'Haut mage', 'deplacement' => 5, 'attaque' => 5, 'defense' => 5, 'pv_body' => 4, 'pv_mind' => 8,
                'tier' => 'sous_boss', 'boite' => 'wizards_of_morcar', 'cout' => 10, 'capacites' => ['sorts_uniques'], 'sorts_dread' => [],
                'archetype_lanceur' => 'haut_mage_morcar'],
            ['nom_base' => 'Nécromancien', 'deplacement' => 6, 'attaque' => 4, 'defense' => 6, 'pv_body' => 4, 'pv_mind' => 7,
                'tier' => 'sous_boss', 'boite' => 'wizards_of_morcar', 'cout' => 12, 'capacites' => ['sorts_uniques'], 'sorts_dread' => [],
                'archetype_lanceur' => 'necromancien_morcar'],

            // ---- Wizards of Morcar : Orc Warcaster, Artificer et les créatures de la
            // boîte (vague 2B, 2026-10-08) ----
            // Stats : tableau des monstres du livret G1504 p. 41 (relu sur PNG) ET
            // cartes d'ennemis (`ennemies_p*.png`), identiques. Mouvement / Attaque /
            // Défense / Body / Mind.
            //
            // ORC WARCASTER (Nyashak) 7/5/5/5/7 et ARTIFICER (la Gardienne) 6/4/3/5/8 :
            // tier `boss`, `sorts_uniques` (comme les trois sorciers de la vague 2A).
            // L'Artificer porte « Attack 4+2* » au tableau : l'astérisque est *Hammer of
            // Ruin* (+2 dés tant que le sort tient — `bonus_attaque`), jamais une
            // statistique de base. `cout` : même mesure que la vague 2A — attaques d'un
            // héros à 3 dés pour l'abattre, Body / (1,5 − Défense/6), sur la droite
            // Ogre commandant (9 attaques, 15) — Seigneur (15, 20) : Warcaster 7,5 → 14,
            // Artificer 5 → 12.
            ['nom_base' => 'Mage de guerre orque', 'deplacement' => 7, 'attaque' => 5, 'defense' => 5, 'pv_body' => 5, 'pv_mind' => 7,
                'tier' => 'sous_boss', 'boite' => 'wizards_of_morcar', 'cout' => 12, 'capacites' => ['sorts_uniques'], 'sorts_dread' => [],
                'archetype_lanceur' => 'guerriere_orque_morcar'],
            ['nom_base' => 'Artificière', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 8,
                'tier' => 'boss', 'boite' => 'wizards_of_morcar', 'cout' => 12, 'capacites' => ['sorts_uniques'], 'sorts_dread' => [],
                'archetype_lanceur' => 'artificiere_morcar'],

            // GOLEM 5/4/5/3/0 — carte sans capacité (la règle « un bouclier noir bloque
            // tout » de la quête 3 est PONCTUELLE, pas une capacité du Golem). Mêmes
            // A/D/B que la Gargouille (cout 7) : même `cout`. Mind 0 : immunité aux jets
            // de Mind, comme les morts-vivants. Tier `base`, en tas des « forts » par son
            // coût.
            ['nom_base' => 'Golem', 'deplacement' => 5, 'attaque' => 4, 'defense' => 5, 'pv_body' => 3, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'wizards_of_morcar', 'cout' => 7, 'capacites' => [], 'sorts_dread' => []],

            // DREADSHIFTER 5/4/3/2/4 — « appear to be either a chest or a door […] the
            // first time a hero moves into the 8 squares surrounding this object,
            // replace it with the Ambush monster miniature. It may move and attack
            // immediately. » `embuscade` : `MoteurEmbuscade` (coffre seulement, voir
            // son docblock — la porte et les trois autres déclencheurs du livret ne
            // sont pas portés). Nom de catalogue anglais : le livret n'en donne pas
            // d'autre (`des_attaque_contre` d'Urdyn le lit sur `nom_base`). `cout` 6 :
            // même résistance que l'Abomination/l'Assassin (2 attaques), + l'embuscade.
            ['nom_base' => 'Dreadshifter', 'deplacement' => 5, 'attaque' => 4, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 4,
                'tier' => 'base', 'boite' => 'wizards_of_morcar', 'cout' => 6, 'capacites' => ['embuscade'], 'sorts_dread' => []],

            // MINOTAURE 7/4/5/6/4 — « Gore: in addition to their own turn, a minotaur
            // may immediately roll 2 Attack dice against a hero who ends their turn in
            // one of the 10 spaces surrounding it. A minotaur may not gore if they are
            // incapacitated. » `coup_de_corne` : `ResolveurTour::coupsDeCorne()`.
            // ⚠ `grande_taille` 1×2 est une DÉDUCTION écrite, pas une lecture : « 10 cases
            // autour » n'existe que pour une figure de deux cases (8 pour une seule),
            // et le symbole ovale de la carte est celui de l'Ogre. `cout` 12 : Body 6 /
            // Défense 5 = 9 attaques, sur la droite des sous-boss (Ogre champion 7,2 →
            // 11, Doralf 10,5 → 13).
            ['nom_base' => 'Minotaure', 'deplacement' => 7, 'attaque' => 4, 'defense' => 5, 'pv_body' => 6, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => 'wizards_of_morcar', 'cout' => 12, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['coup_de_corne'], 'sorts_dread' => []],

            // ⚠ Fellmarak, le Roi Sorcier (boss, seul boss chiffré du livret,
            // Q3) reste NON semé. Sa règle sourcée dit qu'il NE MEURT PAS
            // normalement : quête 12, « Fellmarak cannot be killed […] he
            // screams and flees » (il FUIT, la quête continue sans lui) ;
            // quête 13, sa fin dépend d'un tirage aléatoire dans une pioche de
            // sorts de Dread réduite (« Zargon's Flame », capacité nommée
            // `mort_sur_tirage`, scopée à cette seule rencontre, Telor Q6).
            // NI L'UN NI L'AUTRE n'est le mot-clé `phases` de ce chantier — et
            // aucun des deux n'est construit : le semer comme un boss
            // ORDINAIRE le ferait mourir à 0 Body comme n'importe quel autre,
            // ce que sa propre carte interdit. Semer un boss qui contredit sa
            // fiche serait la clé décorative inverse (une STAT sourcée, un
            // COMPORTEMENT qui la trahit) — laissé de côté, nommé plutôt que
            // deviné, en attendant un passage dédié à ces deux mécanismes.
        ];

        foreach ($monstres as $monstre) {
            Monstre::updateOrCreate(['nom_base' => $monstre['nom_base']], $monstre);
        }
    }
}
