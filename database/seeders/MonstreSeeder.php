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

        // Carte « Dread Warrior » (p03, © 2021 — 1989 « Chaos Warrior ») : Déplacement 7, Attaque 4, Défense 4, Body 3,
        // Mind 3 — valeurs de CARTE (décision 2026-10-05). Variable : Borokk et Gragor, « same stats as a Dread warrior »
        // (Kellar's Keep q. 6 et q. 9), la reprennent sans la recopier.
        $guerrierDuChaos = ['nom_base' => 'Guerrier du Chaos', 'deplacement' => 7, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 3,
            'tier' => 'base', 'boite' => 'base', 'cout' => 6, 'capacites' => [], 'sorts_dread' => []];

        // Carte « Gargoyle » (p05, © 2021) : Déplacement 6, Attaque 4, Défense 5, Body 3, Mind 4. Variable : la Gargouille-statue
        // (Kellar's Keep q. 1) et la Gargouille gardienne (q. 10) « moves, attacks, and defends as a normal gargoyle would ».
        $gargouille = ['nom_base' => 'Gargouille', 'deplacement' => 6, 'attaque' => 4, 'defense' => 5, 'pv_body' => 3, 'pv_mind' => 4,
            'tier' => 'base', 'boite' => 'base', 'cout' => 7, 'capacites' => [], 'sorts_dread' => []];

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
            $guerrierDuChaos,
            // Carte « Gargoyle » (p05, © 2021) : Défense **5**, Body **3**,
            // contre 4/1 au paquet de fan — deux divergences (Déplacement/
            // Attaque/Mind identiques). `cout` recalculé par la même mesure
            // (4.5 attaques-à-3-dés, contre 1.2 avant) — 4 → 7, la créature
            // la plus endurante du tier `base` (sans capacité).
            $gargouille,

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

            // Aligné sur la fiche officielle de *The Mage of the Mirror* (doc 18).
            // UNE case (René, 2026-10-29, qui revient sur ses 2 cases du 2026-10-04) : la légende du livret
            // (F7539 PDF 7) le dessine en jeton ROND, du diamètre d'une case, à côté du Loup géant OVALE de
            // 2 cases — et le texte ne nomme que le loup comme grand monstre (« the giant wolf in this quest
            // pack, for instance », F7539 PDF 6). La règle de lecture est tenue par `BestiaireSourceTest`.
            ['nom_base' => 'Ogre', 'deplacement' => 4, 'attaque' => 6, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'boite' => 'mage_du_miroir', 'cout' => 10,
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
            // L'« Ombre du Dread » (Dread Wraith GÉNÉRIQUE, boss final de la boîte jusqu'au 2026-10-23) est RETIRÉE du
            // catalogue le 2026-10-24 (René : « si l'Ombre du Dread n'est mentionnée nulle part, enlève-la ») : Magrian, le
            // Dread Wraith NOMMÉ du livret, l'a remplacée dans la rencontre finale, et elle n'avait plus aucun rôle. La ligne
            // est supprimée de la base par la migration `2026_10_24_100000_retirer_ombre_du_dread` (seulement si aucune
            // instance ne la référence). Sa divergence de Défense (D3, René 2026-09-04) part avec elle : Magrian garde le livret.

            // SIR RAGNAR (boss, quête 9, p. 31 — docs/plan-dread-moon.md lot I,
            // porté AVEC Ogre Horde comme René l'a tranché le 2026-10-04).
            // Stats relues M/A/D/B/Mi : 5/5/5/4/4 (même ordre que Magrian,
            // ci-dessous — « 9/6/4/5/5 » de Magrian donne M9 A6 D4 B5 Mi5).
            // Règle propre, verbatim : « The first time Sir Ragnar's Body
            // Points are reduced to 0, they are instead reduced to 1 » — PAS
            // le mot-clé `phases` (aucune nouvelle statistique, aucune
            // deuxième forme) : une capacité `increvable_une_fois`, lue au
            // MÊME point de passage unique que les phases
            // (`MoteurDegats::infligerAMonstre()`), une fois pour toute la
            // rencontre.
            ['nom_base' => 'Sir Ragnar', 'deplacement' => 5, 'attaque' => 5, 'defense' => 5, 'pv_body' => 4, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'dread_moon', 'cout' => 14, 'capacites' => ['increvable_une_fois'], 'sorts_dread' => []],

            // MAGRIAN, LE DREAD WRAITH (boss FINAL de Rise of the Dread Moon, quête 10, F6646 p. 33 — chantier EXT-MAGRIAN,
            // 2026-10-23). Stats relues sur le RENDU PNG (p. 33) et sur le tableau p. 36 : Move 9 · Attack 6 · Defend 4 ·
            // Body 5 · Mind 5 — les stats du LIVRET, Defend 4 compris (René, 2026-10-24 : « laisse Magrian comme il est dans le
            // livret »). ⚠ Éthéré, une arme ne le blesse que sur un bouclier noir (1/6) contre autant de dés qui parent sur 1/6 :
            // un groupe à moins de 5 dés d'attaque ne l'abat qu'avec la magie — c'est voulu. L'ancienne Ombre du Dread (D3,
            // divergence du 2026-09-04) est retirée ; la divergence part avec elle. Éthéré (`ethere`). Quatre capacités à usage unique sans action, chacune lue sur UNE couture :
            //  - *Terror* (début du tour d'un héros) → `terreur` ; *Consume Magic* (au sort d'un héros qui vise le camp du MJ)
            //    → `consomme_magie` ; *Reflection* (au coup qui l'achèverait) → `reflexion` ; *Shift Reality* (début du tour du
            //    MJ) → `realite_decalee`. Toutes dans `reactions_defense`, registre `MotsClesReactionMj` (testé dans les deux sens).
            // Ses sorts : *Dreadlights, Channel Dread, Fear, Summon Specters*, « once per quest » chacun — l'archétype `magrian`
            // (`config/archetypes_lanceurs.php`, répertoire nommé par le livret, p. 33). La salle C (soin de 1 PV par tour, la
            // Reine) et l'image-miroir de la première rencontre (« in C or D ») sont des règles d'UNE quête : écartées
            // (`docs/plan-dread-moon.md`, décision du 2026-10-23).
            // `cout` MESURÉ (René, 2026-10-24 : « ajuste son coût » ; méthode de `docs/regles/bestiaire-et-rencontres.md`, celle de
            // Qwindrak), en attaques d'un héros à 3 dés : base 5 / (1,5 − 4/6) = 6,0 ; Consume Magic rend 2 Body (2 / 0,833 = +2,4)
            // et annule le sort d'une action de héros (+1) ; Reflection renvoie le coup fatal (+1) ; Terror prive un héros d'une
            // attaque (+1) ; ses sorts, 3 lancers au budget de boss (`MoteurDread::USAGES_BOSS`), +3 — Shift Reality lance dans ce
            // même budget, il n'en ajoute pas. N = 14,4, sur la droite des boss (Seigneur 15 → 20, pente 0,5) : 20 + 0,5 × (14,4 − 15)
            // = 19,7 ⇒ **20** (était 16, sa seule base). L'éthéré est compté À L'ACHAT, en plus : `DemarreurQuete::coutEffectif()`
            // le multiplie par `RATIO_COUT_ETHERE` (2) — 40 dans le budget de la rencontre finale.
            ['nom_base' => 'Magrian', 'deplacement' => 9, 'attaque' => 6, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 5,
                'tier' => 'boss', 'boite' => 'dread_moon', 'cout' => 20,
                'capacites' => ['ethere', 'reserve_a_son_theme',
                    'reactions_defense' => ['terreur', 'consomme_magie', 'reflexion', 'realite_decalee']],
                'sorts_dread' => [], 'archetype_lanceur' => 'magrian'],

            // ---- The Mage of the Mirror (doc 18) ----
            // L'archer elfe est la seconde créature à distance du bestiaire :
            // « Attack 4 (1 si adjacent) ».
            //
            // BOÎTES PARTAGÉES (René, 2026-10-15) : l'Archer elfe et le Guerrier elfe sont aussi
            // dans *Rise of the Dread Moon* (livret F6646 p. 36, « Elf Warrior » / « Elf Archer »,
            // même fiche) : `boite` reste la boîte d'origine, `boites_partagees` ajoute l'autre —
            // UNE ligne, deux thèmes. Jamais `boite: null` (ils entreraient dans TOUS les thèmes
            // et dans le bestiaire « jeu de base seul ») ni une ligne dupliquée.
            // Lu par `Monstre::boites()` → `BestiaireGroupe::autorise()/contient()`.
            ['nom_base' => 'Archer elfe', 'deplacement' => 6, 'attaque' => 1, 'defense' => 2, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'base', 'boite' => 'mage_du_miroir', 'boites_partagees' => ['dread_moon'], 'cout' => 5, 'portee' => 'distance', 'attaque_distance' => 4,
                'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Guerrier elfe', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'base', 'boite' => 'mage_du_miroir', 'boites_partagees' => ['dread_moon'], 'cout' => 5, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Loup géant', 'deplacement' => 9, 'attaque' => 6, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 1,
                // `grande_taille` : le livret nomme le Loup géant grande figurine
                // (F7539 PDF 6), et sa légende le dessine en jeton OVALE de 2 cases.
                'tier' => 'sous_boss', 'boite' => 'mage_du_miroir', 'cout' => 11, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['charge'], 'sorts_dread' => []],
            // ⚠ Le lanceur de l'*Invocation de loups* (René, 2026-09-04 : « on
            // devrait créer un boss elfique qui utiliserait Invocation de
            // loups »). Il n'a pas fallu l'inventer : c'est **Sinestra,
            // l'archemage**, boss final de la quête 9 de la boîte — Move 8 ·
            // Attack 4 · Defend 4 · Body 4 · Mind 9 (Mage of the Mirror p. 30,
            // doc 18). Le catalogue porte le TYPE et l'IA l'habille : « Sinestra »
            // est un nom propre, comme « Magrian » l'est pour le Dread Wraith.
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
            // Boss de sa boîte. Grande figurine : « When a monster takes up more than one square (the Frozen
            // Horror in this quest pack, for instance) » (F5815 PDF 6). ⚠ Son jeton est ROND : ce livret ne
            // dessine AUCUN ovale (sa planche « Design Your Own » le fait seulement plus grand que les autres),
            // c'est le texte qui donne la taille — l'exception nommée de `BestiaireSourceTest`.
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
            // L'Ogre guerrier tient sur UNE case (René, 2026-10-29) : jeton ROND du diamètre d'une case dans la
            // légende (F9528 PDF 19) et sur les cartes de quête, où les trois autres ogres sont des OVALES qui
            // couvrent exactement 2 cases (carte PDF 36).
            ['nom_base' => 'Ogre guerrier', 'deplacement' => 6, 'attaque' => 5, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 1,
                'tier' => 'sous_boss', 'boite' => 'horde_ogre', 'cout' => 10,
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
            // *Resilience* (« ignore tous les dégâts d'une attaque », une
            // fois pour toute la rencontre) ET *Break* (« met fin à un sort
            // actif sur elle », `rompt_sort_actif`, portée le 2026-10-15 par la
            // couture « le MJ réagit au sort d'un héros » —
            // `ReactionsMj::apresSortDeHeros()`). *Deflect* (« redirige l'attaque vers un héros dans ses 10 cases ») est PORTÉ (chantier EXT-MAGRIAN, 2026-10-23) : `redirige_attaque`, lu par `ResolveurTour::frapper()` AVANT le jet (`ReactionsMj::deflechirAttaque()`) ; le coup part vers un héros, qui répond avec sa propre défense. Vaincue (dernière phase à 0 Body),
            // « elle s'incline » : `recompense_reddition` crédite 1000 po au
            // groupe au lieu d'une mort — ELLE N'EST PAS maléfique (p. 2).
            // `cout` : somme des attaques-à-3-dés par phase (10 + 7,5 + 3,75
            // ≈ 21,25), arrondie au niveau du Seigneur ogre (22) dont elle
            // partage la résistance totale.
            ['nom_base' => 'Gruzbella Hammerhand', 'deplacement' => 5, 'attaque' => 4, 'defense' => 6, 'pv_body' => 5, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'horde_ogre', 'cout' => 23,
                'capacites' => ['reactions_defense' => ['ignore_degats_attaque', 'rompt_sort_actif', 'redirige_attaque']],
                'sorts_dread' => [], 'phase_suivante' => 'Gruzbella Déterminée'],
            ['nom_base' => 'Gruzbella Déterminée', 'deplacement' => 7, 'attaque' => 5, 'defense' => 5, 'pv_body' => 5, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'horde_ogre', 'cout' => 23,
                'capacites' => ['reactions_defense' => ['ignore_degats_attaque', 'rompt_sort_actif', 'redirige_attaque']],
                'sorts_dread' => [], 'phase_suivante' => 'Gruzbella Imprudente'],
            ['nom_base' => 'Gruzbella Imprudente', 'deplacement' => 8, 'attaque' => 6, 'defense' => 1, 'pv_body' => 5, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'horde_ogre', 'cout' => 23,
                'capacites' => ['reactions_defense' => ['ignore_degats_attaque', 'rompt_sort_actif', 'redirige_attaque'], 'recompense_reddition' => ['or' => 1000]],
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

            // ---- LES SORCIERS DU DREAD d'Against the Ogre Horde (F9528 p. 31, 35, 37, René 2026-10-15) ----
            // Stats relues sur le RENDU PNG de chaque page (colonnes Attack / Defend / Move / Body /
            // Mind du livret ; l'extraction texte les mélange). Chacun lance ses sorts avec « a reserve
            // of N cards » PAR SORT : la capacité `reserve_cartes` (`App\Partie\CartesDread`), pleine à
            // chaque placement — ni le budget par palier, ni `sorts_uniques`. Les trois sorts sont ceux
            // de `SortDreadSeeder` (Mind Burst → « Explosion mentale », Mind Lock → « Verrou mental »,
            // Dominate → « Domination »). Règle commune (p. 10) : un sort par tour, pas de coup d'arme
            // le même tour, et les effets d'un sort actif cessent à la mort de son lanceur.
            //
            // `cout` : NOTRE valeur (aucune carte n'en porte), mesurée comme les autres — attaques d'un
            // héros à 3 dés pour l'abattre, Body / (1,5 − Défense/6) (`docs/regles/bestiaire-et-rencontres.md`).
            //
            // NEXRAEL, « an apprentice Dread Sorcerer » (q. 6, p. 31) : A3 D4 M8 B1 Mi5, *Mind Burst*,
            // « a reserve of 4 cards ». Body 1 / Défense 4 = 1,2 attaque : le plus fragile du catalogue
            // après les gobelins, donc tier `base` — c'est ce que la mesure dit, et ce qu'« apprenti »
            // dit aussi. Mesuré 3 (la ligne des monstres de base : Body 1 / Déf. 4 valait 3 avant les
            // cartes 2021), RELEVÉ À 4 sur décision de portage : à 3 il tomberait dans la masse des
            // « faibles » (`seuil_cout_fort`), où une rencontre en achèterait plusieurs — le livret n'en
            // nomme QU'UN par donjon, dans sa propre salle. À 4 il est un « fort », au plus un par quête.
            // `mouvement_apres_sort` (F9528 p. 10 : « may take their movement » après un sort) : lu par
            // `ResolveurTour::avancerApresSortDread()`, testé en jeu (`SortsOgreHordeTest`).
            ['nom_base' => 'Nexrael', 'deplacement' => 8, 'attaque' => 3, 'defense' => 4, 'pv_body' => 1, 'pv_mind' => 5,
                'tier' => 'base', 'boite' => 'horde_ogre', 'cout' => 4,
                'capacites' => ['reserve_cartes' => ['Explosion mentale' => 4], 'mouvement_apres_sort'],
                'sorts_dread' => [], 'archetype_lanceur' => 'nexrael_apprenti'],

            // XENLOTH, « Festral's assistant, Xenloth the Dread Mage » (q. 9, p. 37) : A2 D4 M6 B1 Mi4,
            // *Mind Lock* et *Mind Burst*, « 5 cards for each spell ». SOUS-BOSS (René, 2026-10-02).
            // Mesuré 1,2 attaque → 6 sur la droite Garde-mage (3,6 → 8) — Ogre champion (7,2 → 11),
            // RELEVÉ À 8 sur décision de portage : `BestiaireSourceTest` tient que les paliers ne se
            // chevauchent pas en coût (un sous-boss coûte plus que tout monstre de base — la Gargouille,
            // 7), et un sous-boss à 6 serait « acheté » moins cher qu'une créature ordinaire. 8 est le
            // coût du Garde-mage, l'autre sous-boss à Body léger : le plus léger des sous-boss.
            ['nom_base' => 'Xenloth', 'deplacement' => 6, 'attaque' => 2, 'defense' => 4, 'pv_body' => 1, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => 'horde_ogre', 'cout' => 8,
                'capacites' => ['reserve_cartes' => ['Verrou mental' => 5, 'Explosion mentale' => 5], 'mouvement_apres_sort'],
                'sorts_dread' => [], 'archetype_lanceur' => 'xenloth_mage_dread'],

            // FESTRAL, « the Dread Sorcerer » (q. 8, p. 35) : A4 D5 M6 B3 Mi8, *Mind Burst*, *Mind Lock* et
            // *Dominate*, « 3 cards for each spell ». BOSS (René, 2026-10-02 : lanceur de boss, comme
            // l'Archimage elfe). « The Dread Warriors in the room are Festral's personal guard. They each
            // roll 5 Attack dice and 5 Defend dice » : `garde_personnelle`, posée au placement par
            // `App\Partie\GardePersonnelle` sur les Guerriers du Dread de SA salle. Mesuré 4,5 attaques → 11
            // sur la droite des boss (Ogre commandant 9 → 15, Seigneur 15 → 20) — au niveau de la
            // Gardienne de Morcar (5 attaques → 12) : un lanceur, pas une brute.
            ['nom_base' => 'Festral', 'deplacement' => 6, 'attaque' => 4, 'defense' => 5, 'pv_body' => 3, 'pv_mind' => 8,
                'tier' => 'boss', 'boite' => 'horde_ogre', 'cout' => 11,
                'capacites' => [
                    'reserve_cartes' => ['Explosion mentale' => 3, 'Verrou mental' => 3, 'Domination' => 3],
                    'garde_personnelle' => ['monstre' => 'Guerrier du Chaos', 'attaque' => 5, 'defense' => 5],
                    'mouvement_apres_sort',
                ],
                'sorts_dread' => [], 'archetype_lanceur' => 'festral_sorcier_dread'],

            // GUARDIAN EFFIGY (Against the Ogre Horde, quête 4, note D, p. 27) — SOUS-BOSS (Q7, René
            // 2026-10-02), semée le 2026-10-15 : le PATRON TOURELLE existe (`MotsClesAdversaires`).
            // Stats relues sur le RENDU de la page : Attack 3 dice · Defend 5 dice · Move 0 · Body 2 ·
            // Mind 0. Texte : « This Orc is a magical effigy that guards the iron gate of the ogre
            // horde. Each turn, it may launch a ball of fire of 3 Attack dice at any one hero in its
            // line of sight. It is unable to move and is immune to all spell effects. »
            //  - `tourelle` : immobile, fait feu chaque tour du MJ sur un héros en ligne de vue, au
            //    contact comme à distance, aux mêmes 3 dés (`ResolveurTour::jouerMonstreEveille()`) ;
            //  - `immunite_sorts` : « immune to ALL spell effects » — TOTALE (René 2026-10-15), comme la
            //    Gargouille gardienne de Kellar's Keep : ni sort de héros, ni sort de Dread ;
            //  - Move 0 = `Monstre::estImmobile()` : posée en SALLE seulement, hors parvis, jamais au
            //    détriment du plancher de cases jouables (`AssembleurCarte::casesPourFiguresFixes()`).
            // `cout` 8 = le PLANCHER du palier (Garde-mage, Sorcier du Dread) : la mesure donne 3
            // attaques à 3 dés pour l'abattre (Body 2 / (1,5 − 5/6)), nettement sous un sous-boss mobile,
            // mais le test de palier exige un coût au-dessus du plus cher des sbires (7) — et l'immunité
            // totale aux sorts ferme la moitié des réponses d'un groupe de lanceurs.
            ['nom_base' => 'Effigie gardienne', 'deplacement' => 0, 'attaque' => 3, 'defense' => 5, 'pv_body' => 2, 'pv_mind' => 0,
                'tier' => 'sous_boss', 'boite' => 'horde_ogre', 'cout' => 8,
                'capacites' => ['tourelle', 'immunite_sorts'], 'sorts_dread' => []],

            // ---- The Crypt of Perpetual Darkness (G1798, chantier EXT-CRYPTE, 2026-10-23) ----
            // Stats : Move/Attack/Defend/Body/Mind relues sur le texte du livret (pages imprimées). Tous les
            // `cout` sont MESURÉS (docs/regles/bestiaire-et-rencontres.md) : attaques d'un héros à 3 dés pour
            // l'abattre = Body / (1,5 − Défense/6). Boîte `crypte_tenebres` : thème ALLUMÉ par René le 2026-10-23
            // (rotation index 10). Chaque créature porte `reserve_a_son_theme` : elle ne se tire que sous la Crypte
            // (`DemarreurQuete::acheterMonstres()`), même quand un pool partagé n'a aucun autre candidat.
            //
            // DREAD SKULL (composants p. 5, réemployé quête 9 note A p. 23) : « As soon as a hero sees a Dread
            // Skull, place it on the board. Immediately, and on Zargon's turn, the Dread Skull makes a ranged
            // attack with the strength of 2 combat dice at a hero it can see with its green eye beams. The skull
            // has 1 Body Point, 0 Defend Dice, and disappears once it sustains damage. »
            //  - `apparait_a_la_vue` : posé au premier regard d'un héros, il tire aussitôt (`MoteurTourelle`) ;
            //    `tourelle` : il fait feu à chaque tour du MJ tant qu'il vit (`ResolveurTour::jouerMonstreEveille()`) ;
            //  - Move 0 : le livret ne lui donne AUCUN mouvement (« place it on the board ») → immobile : salle
            //    seulement, hors parvis, plancher tenu (`AssembleurCarte::casesPourFiguresFixes()`, décision Q2) ;
            //  - Mind 0 : le livret ne donne pas de Mind (⚠ non trouvé) — 0 par défaut ; sans conséquence sur un
            //    crâne qui disparaît au premier dégât ;
            //  - `cout` 8 = le PLANCHER du palier sous-boss (précédent de l'Effigie gardienne) : la mesure donne
            //    1 / (1,5 − 0) = 0,67 attaque, très sous le plancher. Tier `sous_boss` et non `base` : un tier
            //    `base` se tirerait dans TOUTES les boîtes (`BestiaireGroupe::autorise()` est une préférence en
            //    automatique, jamais un filtre). Nommé au pool sous-boss de la Crypte (`creatures`, comme l'Effigie
            //    gardienne) et réservé à son thème (`reserve_a_son_theme`) : il n'est tiré que sous la Crypte.
            ['nom_base' => 'Crâne du Dread', 'deplacement' => 0, 'attaque' => 2, 'defense' => 0, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'sous_boss', 'boite' => 'crypte_tenebres', 'cout' => 8,
                'capacites' => ['tourelle', 'apparait_a_la_vue', 'reserve_a_son_theme'], 'sorts_dread' => []],

            // SORCIÈRE DES MARAIS (« the Dread sorcerer represents a swamp hag », quête 3 note C, p. 11) :
            // M4 A2 D3 B2 Mi3. Répertoire NOMMÉ : Lightning, Command, Sleep, Tempest, Rust, Escape → archétype
            // `sorciere_des_marais`. `cout` : 2 / (1,5 − 3/6) = 2,0 attaques, sous le plancher 8 du palier.
            ['nom_base' => 'Sorcière des Marais', 'deplacement' => 4, 'attaque' => 2, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'crypte_tenebres', 'cout' => 8,
                'capacites' => ['repertoire_complet', 'reserve_a_son_theme'], 'sorts_dread' => [], 'archetype_lanceur' => 'sorciere_des_marais'],

            // SQUELETTE DE MINOTAURE (quête 4 note C, p. 13 — « Place a skeleton miniature on the board ») :
            // M6 A3 D3 B2 Mi0, sans répertoire. `cout` : 2 / (1,5 − 3/6) = 2,0 ⇒ plancher 8. ⚠ NON PORTÉ, scripté
            // dans le coffre de la quête 4 : il frappe « immédiatement » celui qui l'a trouvé, puis sa hache
            // revient au groupe — une règle de ce coffre, pas d'un donjon généré.
            ['nom_base' => 'Squelette de minotaure', 'deplacement' => 6, 'attaque' => 3, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 0,
                'tier' => 'sous_boss', 'boite' => 'crypte_tenebres', 'cout' => 8,
                'capacites' => ['reserve_a_son_theme'], 'sorts_dread' => []],

            // BUUBHEALXEA, REINE GOBELINE (quête 5 note E, p. 15) : M8 A3 D3 B3 Mi2. Répertoire : Command, Fear,
            // Escape → archétype `buubhealxea_reine_gobeline`. `cout` : 3 / (1,5 − 3/6) = 3,0 ⇒ plancher 8.
            ['nom_base' => 'Buubhealxea, reine gobeline', 'deplacement' => 8, 'attaque' => 3, 'defense' => 3, 'pv_body' => 3, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'boite' => 'crypte_tenebres', 'cout' => 8,
                'capacites' => ['repertoire_complet', 'reserve_a_son_theme'], 'sorts_dread' => [], 'archetype_lanceur' => 'buubhealxea_reine_gobeline'],

            // ROI ARCHALONEUS, seigneur momie (quête 6 note B, p. 17) : M6 A4 D4 B4 Mi3. Répertoire : Sleep, Rust,
            // Command → archétype `archaloneus_roi_momie`. `cout` : 4 / (1,5 − 4/6) = 4,8 attaques ⇒ plancher 8.
            ['nom_base' => 'Roi Archaloneus', 'deplacement' => 6, 'attaque' => 4, 'defense' => 4, 'pv_body' => 4, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'crypte_tenebres', 'cout' => 8,
                'capacites' => ['repertoire_complet', 'reserve_a_son_theme'], 'sorts_dread' => [], 'archetype_lanceur' => 'archaloneus_roi_momie'],

            // MOMIE-NAINE (quête 8 note C, p. 21 : « a dwarf in its former life ») : M5 A4 D5 B4 Mi0, sans
            // répertoire. `cout` : 4 / (1,5 − 5/6) = 6,0 attaques ⇒ plancher 8 (précédent : Xenloth, 6 attaques → 8).
            ['nom_base' => 'Momie-naine', 'deplacement' => 5, 'attaque' => 4, 'defense' => 5, 'pv_body' => 4, 'pv_mind' => 0,
                'tier' => 'sous_boss', 'boite' => 'crypte_tenebres', 'cout' => 8,
                'capacites' => ['reserve_a_son_theme'], 'sorts_dread' => []],

            // KEDRICK GILBANE, chevalier de la mort (quête 9 note C, p. 23 : « the Dread sorcerer represents the
            // Dread knight Kedrick Gilbane ») : M7 A5 D5 B4 Mi4. Répertoire : Cloud of Dread, Fear, Firestorm,
            // Command → archétype `kedrick_gilbane`. `cout` : 4 / (1,5 − 5/6) = 6,0 ⇒ plancher 8. Sous-boss : la
            // boîte n'a qu'un boss, Venim (règle du thème : un boss par boîte, cf. Telor et Morcar).
            ['nom_base' => 'Kedrick Gilbane', 'deplacement' => 7, 'attaque' => 5, 'defense' => 5, 'pv_body' => 4, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => 'crypte_tenebres', 'cout' => 8,
                'capacites' => ['repertoire_complet', 'reserve_a_son_theme'], 'sorts_dread' => [], 'archetype_lanceur' => 'kedrick_gilbane'],

            // VENIM LE DRAGON (boss final, quête 10, p. 25 : « Venim has the following spells: Cloud of Dread,
            // Fear, Command, Sleep, and Breathe Acid (Firestorm but with acid damage) ») : M7 A5 D5 B4 Mi4.
            // Répertoire → archétype `venim_dragon` (Souffle acide, carte-sœur de Tempête de feu). `cout` :
            // 4 / (1,5 − 5/6) = 6,0 attaques, sur la droite des boss (Seigneur 15 → 20, pente 0,5) :
            // 20 + 0,5 × (6,0 − 15) = 15,5 ⇒ 16. Venim est une cible valide du bonus « contre un dragon » de la
            // Lance (`des_attaque_contre`, noms). Taille : le livret ne la donne pas (⚠ non trouvé) : 1 × 1.
            ['nom_base' => 'Venim le Dragon', 'deplacement' => 7, 'attaque' => 5, 'defense' => 5, 'pv_body' => 4, 'pv_mind' => 4,
                'tier' => 'boss', 'boite' => 'crypte_tenebres', 'cout' => 16,
                'capacites' => ['repertoire_complet', 'reserve_a_son_theme'], 'sorts_dread' => [], 'archetype_lanceur' => 'venim_dragon'],

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
            // Le Blightcrawler du Monster Chart (p. 47 : 7/4/4/3/4, Spawn, Agile, Venomous). Deux cases (René,
            // 2026-10-29) : la légende (F9907 PDF 6) le dessine en jeton OVALE, de la taille exacte de ceux du
            // Serpent et du Singe géant, et le livret a sa règle « When a monster takes up 2 squares » (PDF 4).
            ['nom_base' => 'Rampant putride', 'deplacement' => 7, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => 'jungles_delthrak', 'cout' => 11, 'grande_taille' => ['l' => 1, 'h' => 2],
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
            // aussi. *Dispel* (« annule un sort la ciblant », `annule_sort_ciblant`)
            // l'est aussi depuis le 2026-10-15, avec elle, dans les trois phases :
            // `ReactionsMj::auSortDeHeros()` annule en entier le sort d'un héros qui
            // la prend pour cible (cible unique, zone ou rayon), une fois par
            // rencontre, sans action et sans jet.
            //
            // `cout` : somme des attaques-à-3-dés par phase (5 + 4,8 + 5,14
            // ≈ 14,9), interpolée comme le Dragon (First Light) entre les
            // bosses existants plutôt qu'inventée au hasard.
            // « *Gretzl may choose to fire at range at any hero in her line of
            // sight » (livret p. 35, l'astérisque des TROIS formes) : `tir_au_choix`,
            // construit pour Gruulob (2026-10-09).
            ['nom_base' => 'Gretzl la Porte-Fléau', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 6,
                'tier' => 'boss', 'boite' => 'jungles_delthrak', 'cout' => 18,
                'capacites' => ['tir_au_choix', 'reactions_defense' => ['ignore_degats_attaque', 'annule_sort_ciblant']],
                'sorts_dread' => [], 'archetype_lanceur' => 'gretzl_porte_fleau',
                'phase_suivante' => 'Demonspider'],
            ['nom_base' => 'Demonspider', 'deplacement' => 8, 'attaque' => 5, 'defense' => 4, 'pv_body' => 4, 'pv_mind' => 3,
                'tier' => 'boss', 'boite' => 'jungles_delthrak', 'cout' => 18,
                'capacites' => ['agile', 'venimeux', 'tir_au_choix', 'reactions_defense' => ['ignore_degats_attaque', 'annule_sort_ciblant']],
                'sorts_dread' => [], 'archetype_lanceur' => 'gretzl_porte_fleau',
                'phase_suivante' => 'Demonape'],
            ['nom_base' => 'Demonape', 'deplacement' => 8, 'attaque' => 6, 'defense' => 2, 'pv_body' => 6, 'pv_mind' => 1,
                'tier' => 'boss', 'boite' => 'jungles_delthrak', 'cout' => 18,
                'capacites' => ['agile', 'tir_au_choix', 'reactions_defense' => ['ignore_degats_attaque', 'annule_sort_ciblant']],
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
            // Faction « Gobelin » = le monstre de base, son archer ET le Chamane Gobelin
            // (`InstanceMonstre::estDeFaction()`, `MEMBRES_SUPPLEMENTAIRES_DE_FACTION`) —
            // René, 2026-10-15 : le Chamane est un gobelin, « All Goblins in this quest »
            // l'inclut. Gruulob lui-même (« Gruulob, … ») et sa forme démoniaque n'en font
            // PAS partie : le chef auquel les gobelins sont dévoués garde sa fiche imprimée.
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

            // ---- Les QUATRE ADVERSAIRES NOMMÉS de Jungles of Delthrak (René, 2026-10-15) ----
            // Stats relues sur le RENDU PNG de chaque page (F9907 p. 17, 23, 39, 41 ; l'extraction texte
            // mélange les colonnes). Aucun n'était au catalogue : ce sont des figures de QUÊTE du livret,
            // portées parce que René les a toutes demandées. Chaque capacité nomme son lecteur dans
            // `App\Engine\MotsClesAdversaires`. Mesure de `cout` : attaques d'un héros à 3 dés pour
            // abattre = Body / (1,5 − Défense/6), sur la droite des sous-boss (Ogre champion 7,2 → 11,
            // Minotaure 9 → 12, Doralf 10,5 → 13) ; le plancher du palier est 8.

            // DEATHSPINNER LA REINE DU FLÉAU (quête 3, zone D, p. 17 ; figurine Blightcrawler).
            // ⚠ « MOVEMENT 6 dice » est IMPRIMÉ ainsi, seule colonne Move du livret à porter « dice » —
            // lu 6 CASES : l'erratum 2021 (plan-errata-2021.md §5) dit « 6 cases » et `reference/18`
            // aussi ; tous les autres blocs du livret donnent un nombre de cases. Attack 5 · Defend 4 ·
            // Body 4 · Mind 4 → 4,8 attaques ; sous-boss, `cout` 10 (plancher 8, plus le Filet : un
            // sort gratuit sur chaque héros qui finit son tour en sa vue).
            // « Silencing Snare – Deathspinner may cast Channel Dread at will on any hero that ends
            // their turn within her line of sight (no action required). She cannot use this ability if
            // she would not be able to take her action (for example, when under the effects of the
            // Tempest spell). » → `filet_silencieux`, paramétré par le sort : lu par
            // `AdversairesDelthrak::filetsSilencieux()` à la fin du tour de chaque héros. PAS de
            // `sorts_dread` : la carte ne lui donne ce sort que sous cette forme, pas comme action.
            // ⚠ Non porté, nommé : l'erratum compile aussi « Spawn / Agile / Venomous » (les traits du
            // Blightcrawler dont elle emprunte la figurine) — l'erratum n'est pas un livret et le bloc
            // imprimé n'en porte aucun ; les trois mots ont déjà leur lecteur (`agile`, `venimeux`,
            // `spawn`), une ligne les ajouterait si René le décide.
            // Deux cases, comme la figurine Blightcrawler qui la représente (René, 2026-10-29 ; voir le Rampant putride).
            ['nom_base' => 'Deathspinner la Reine du Fléau', 'deplacement' => 6, 'attaque' => 5, 'defense' => 4, 'pv_body' => 4, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => 'jungles_delthrak', 'cout' => 10, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['filet_silencieux' => ['sort' => 'Canaliser l\'Effroi']], 'sorts_dread' => []],

            // GOLEM CŒUR-DE-FORGE (« Forgeheart Golem », quête 6, zone A, p. 23) : Move 8 · Attack 5 ·
            // Defend 5 · Body 5 · Mind 0 → 7,5 attaques ; sous-boss, `cout` 11 (droite des sous-boss).
            // Mind 0 : immunisé aux sorts mentaux (`Monstre::immuniseMental()`), comme les morts-vivants.
            // « Breath Of the Construct – This attack inflicts 2 Body Points of damage to all heroes
            // located in the same room or corridor. Each affected hero then rolls two red dice. For each
            // 5 or 6 rolled, the damage is reduced by 1 point. » — « once as an action » →
            // `souffle_du_construit` (`degats` 2, `des_rouges` 2 : les chiffres de la carte), lu par
            // `AdversairesDelthrak::souffle()`. Le Golem « Wizards of Morcar » (5/4/5/3/0) est une autre fiche.
            // EMPRISE 1×2 (René, 2026-10-29 : « Oui passe à 2 cases ») : « Use the Forgeheart Golem's tile » — la tuile
            // posée dans la salle A de la carte (F9907 PDF 12) couvre une colonne sur DEUX cases ; l'emprise suit le jeton
            // du livret (`docs/regles/bestiaire-et-rencontres.md`). Migration `2026_10_29_200000_golem_coeur_de_forge_deux_cases`.
            ['nom_base' => 'Golem Cœur-de-Forge', 'deplacement' => 8, 'attaque' => 5, 'defense' => 5, 'pv_body' => 5, 'pv_mind' => 0,
                'tier' => 'sous_boss', 'boite' => 'jungles_delthrak', 'cout' => 11, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => ['souffle_du_construit' => ['degats' => 2, 'des_rouges' => 2]], 'sorts_dread' => []],

            // SCION FLEUR-POURRIE (« Bloomrot Scion », quête 14 voie B, p. 39 — BOSS FINAL du livret) :
            // Move 0 · Attack 5* · Defend 5 · Body 12** · Mind 0, « (Venomous. Entangling Roots.) » →
            // 18 attaques ; boss, `cout` 22 (entre le Seigneur ogre, 15 attaques → 22, et Gruzbella,
            // 21 → 23 : le sacrifice ajoute du Body que la mesure ne compte pas, l'immobilité en retire
            // de la menace). Son Move 0 reprend le patron de la tourelle pour l'IMMOBILITÉ seule
            // (`Monstre::estImmobile()` : posé en salle, hors parvis, plancher tenu).
            //  * « has a sweeping attack that targets all heroes adjacent and diagonal to it » →
            //    `frappe_de_zone` + `attaque_diagonale`, deux mots-clés existants (le balayage lit la
            //    MÊME adjacence que `heroAuContact()`) ;
            //  ** « may sacrifice one of its Tendrils and instead be reduced to 1 Body Point » →
            //    `sacrifice_de_vrille`, lu à `MoteurDegats::infligerAMonstre()` ;
            //  « At the end of the Bloomrot Scion's turn, place one Tendril in an unoccupied square as close
            //    as possible » → `vrille_en_fin_de_tour` (la créature posée est « Vrille ») ;
            //  « immune to spells that would cause it to sleep or miss its turn » → `immunite_conditions`
            //    (`endormi`, `saute_tour`) — lecture LITTÉRALE : la paralysie (Flamme hypnotique) et
            //    l'enchaînement sont d'autres états, non cités ;
            //  « may cast the Channel Dread spell at will » → `sort_a_volonte` + `sorts_dread`, comme
            //    la boule de feu du Dragon ; il frappe d'abord ce qui est à sa portée
            //    (`ResolveurTour`, `$herosAPortee`) et ne lance le sort que sans personne au contact.
            // ⚠ NON porté, nommé : « A hero or monster that enters the rift is consumed by Dread and lost
            // forever » — la faille est un élément de la CARTE imprimée de la quête 14, que la génération
            // procédurale ne dresse pas.
            ['nom_base' => 'Scion Fleur-Pourrie', 'deplacement' => 0, 'attaque' => 5, 'defense' => 5, 'pv_body' => 12, 'pv_mind' => 0,
                'tier' => 'boss', 'boite' => 'jungles_delthrak', 'cout' => 22,
                'capacites' => [
                    'venimeux', 'racines_entravantes', 'frappe_de_zone', 'attaque_diagonale',
                    'sacrifice_de_vrille', 'vrille_en_fin_de_tour' => ['creature' => 'Vrille'],
                    'immunite_conditions' => ['endormi', 'saute_tour'],
                    'sort_a_volonte' => ['sort' => 'Canaliser l\'Effroi'],
                ],
                'sorts_dread' => ['Canaliser l\'Effroi']],

            // VRILLE (« Tendril », même page) : Move 6 · Attack 3* · Defend 2 · Body 2 · Mind 0, « (Venomous.
            // Entangling Roots.) » — « *Tendrils may attack diagonally. » Ne s'ACHÈTE JAMAIS : le Scion la
            // pose (`vrille_en_fin_de_tour`) et la sacrifie. `cout` 0 le dit — `DemarreurQuete` n'achète
            // que `cout > 0`, comme le monstre errant (`spawnErrant()`). Posée sur la case libre la plus
            // proche du Scion, révélée d'emblée, elle joue au round suivant.
            ['nom_base' => 'Vrille', 'deplacement' => 6, 'attaque' => 3, 'defense' => 2, 'pv_body' => 2, 'pv_mind' => 0,
                'tier' => 'base', 'boite' => 'jungles_delthrak', 'cout' => 0,
                'capacites' => ['venimeux', 'racines_entravantes', 'attaque_diagonale'], 'sorts_dread' => []],

            // BAKABRI LE CRUEL (quête 15 voie C, zone C, p. 41 ; figurine Goblin Warlock) : Move 8 ·
            // Attack 3* · Defend 4 · Body 4 · Mind 5 → 4,8 attaques ; sous-boss, `cout` 10. « *Bakabri may
            // choose to fire at range at any hero in his line of sight. He knows the following Dread
            // spells: Creeping Grasp, Channel Dread, and Lightning Bolt. » → `tir_au_choix` (celui de
            // Gruulob et de Gretzl : sur place, aux dés d'attaque, jamais au contact) et l'archétype
            // `bakabri_le_cruel`. Les trois sorts existent : Étreinte des Ronces, Canaliser l'Effroi et
            // Éclair de Chaos (la carte *Lightning Bolt* du paquet de Dread). Le livret l'envoie vers la
            // quête 16 (« Proceed to Quest 16 ») : il n'est PAS le dernier mot de sa voie — sous-boss.
            ['nom_base' => 'Bakabri le Cruel', 'deplacement' => 8, 'attaque' => 3, 'defense' => 4, 'pv_body' => 4, 'pv_mind' => 5,
                'tier' => 'sous_boss', 'boite' => 'jungles_delthrak', 'cout' => 10,
                'capacites' => ['tir_au_choix'], 'sorts_dread' => [], 'archetype_lanceur' => 'bakabri_le_cruel'],

            // ---- First Light (2024) ----
            // Source : carte de monstre « Dragon » photographiée par René
            // (2026-09-30), © 2024 Hasbro — reference/18_extensions.md §6.1bis.
            // Texte intégral de la carte : « The dragon uses Draconic Flight
            // and may cast Ball of Flame at will. »
            //  - grande_taille : la figurine occupe 2 cases (photo de la
            //    figurine + symbole de carte ovale 2 cases de large, FL-Q
            //    p. 9) — même emprise que l'Ogre champion et tous les autres grands
            //    monstres du catalogue (`['l' => 1, 'h' => 2]`, purement
            //    géométrique, `AssembleurCarte`/`FabriqueGrille` ne
            //    distinguent pas largeur et hauteur).
            //  - sorts_dread : liste BRUTE et non un archétype — la carte ne
            //    nomme qu'UN sort, pas un répertoire de sorcier nommé (voir
            //    le commentaire du Champion, plus haut).
            //  - capacites : `sort_a_volonte` (Boule de Flammes sans compteur
            //    d'usage, pour ce monstre SEUL — René, 2026-09-30,
            //    `MoteurDread::sortAVolonte()`) et `vol_draconique`
            //    (Draconic Flight — tour fractionné, un budget en deux jambes
            //    autour de l'action, `MoteurDread::tourDraconique()`, FL-Q p. 7).
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
                'capacites' => ['vol_draconique', 'sort_a_volonte' => ['sort' => 'Boule de Flammes'],
                    // FLAMME DU DREAD (`reference/18` §6.1, notes de quête de Qwindrak) : à l'arrivée de cette
                    // forme, chaque héros de la salle lance un dé rouge ; 3 ou moins = 1 Body. Lue à l'entrée
                    // dans la pioche (`App\Partie\MoteurSynchroformes::flammeALArrivee()`), jamais à la pose.
                    'flamme_du_dread' => ['degats' => 1]],
                'sorts_dread' => ['Boule de Flammes']],

            // QWINDRAK THE WARLOCK (First Light, confrontation finale — 2026-10-17, décision de René ; répertoire,
            // météore et coût révisés le 2026-10-22).
            // Source : notes de quête photographiées (`reference/18_extensions.md` §6.1 : « Qwindrak the Warlock
            // — Move 6 · Attack 3 dés · Defend 6 dés · Body 3 · Mind 6 »), livret de quêtes First Light p. 7 (« the
            // warlock assumes one or more distinct creature forms called Synchroforms … When the heroes defeat his
            // Synchroform creature, remove that miniature from the board. Any spell active on Qwindrak ends. »), et
            // p. 31, note C (« In all forms, Qwindrak knows the Dread spells Tempest, Cloud of Dread, and Lightning
            // Bolt. Each time a hero casts a spell or makes an attack against him, Qwindrak hurls a meteor at them »).
            // Il ne porte PAS la fiche d'une créature : il PREND des formes. Chaque forme est une ligne EXISTANTE du
            // bestiaire de base, aux statistiques de sa carte — jamais recopiées ici. La liste du livret est
            // « Abomination, Dread Warrior, Gargoyle, Goblin, Orc » : « Dread Warrior » est notre `Guerrier du Chaos`
            // (nom de 1989), « Gargoyle » notre `Gargouille`, « Orc » notre `Orque`. À sa première mort, la pioche
            // (`synchroformes`) mélange les cinq formes, puis pose le DRAGON au fond. Le Dragon n'est donc JAMAIS
            // acheté seul : il est retiré de la rencontre finale du gabarit.
            // RÉPERTOIRE (`archetype_lanceur` qwindrak, `config/archetypes_lanceurs.php`) : Tempest = Tourmente,
            // Cloud of Dread = Nuée d'Effroi, Lightning Bolt = Éclair de Chaos. « In all forms » : chaque forme le
            // porte par `InstanceMonstre::ligneDeReference()`, jamais en recopie sur sa propre fiche.
            // MÉTÉORE (`meteore`, `App\Partie\MoteurMeteore`) : chaque attaque ou sort d'un HÉROS qui vise Qwindrak
            // (toutes formes) lui vaut 2 dés d'attaque contre ce héros, après la résolution de l'action.
            // ⚠ Tuiles Skull du Dragon : aucune — la quête 10 n'en pose pas (livret, notes de quête p. 31) : le Dragon
            // entre à pleins Body. Non portées, parce qu'elles ne concernent que la quête 10 : les règles de relance
            // de Mind et de Hall of the Dead (les quêtes générées ne les ont pas).
            // `cout` (2026-10-22) — méthode du bestiaire, attaques-à-3-dés pour abattre = Body / (1,5 − Défense/6) :
            //   formes : Warlock 3/(1,5 − 1) = 6 ; Abomination 2/(1,5 − 0,5) = 2 ; Gargouille 3/(1,5 − 5/6) = 4,5 ;
            //     Guerrier du Chaos 3/(1,5 − 4/6) = 3,6 ; Gobelin 1/(1,5 − 1/6) = 0,75 ; Orque 1/(1,5 − 2/6) = 0,86 ;
            //     Dragon 7/(1,5 − 5/6) = 10,5 ⇒ N_formes = 28,2.
            //   météore : 2 dés contre les 3 de l'unité = 2/3 d'une attaque par attaque (ou sort) du groupe :
            //     N × (1 + 2/3) = 47,0.
            //   sorts : 3 lancers au plus (budget de boss, partagé par les formes), 1 action de héros chacun : +3.
            //   N_eq = 50,0 ; sur la droite des boss (Seigneur 15 → 20 ; Gruzbella, chaîne de ses trois phases, 21 → 23 ; pente 0,5) :
            //   23 + 0,5 × (50,0 − 21) = 37,5 ⇒ 38. Non chiffré : la flamme du Dragon (un dé rouge par héros de la salle,
            //   comme pour le Dragon seul) et sa Boule de Flammes à volonté.
            ['nom_base' => 'Qwindrak the Warlock', 'deplacement' => 6, 'attaque' => 3, 'defense' => 6, 'pv_body' => 3, 'pv_mind' => 6,
                'tier' => 'boss', 'boite' => 'first_light', 'cout' => 38,
                'capacites' => [
                    'synchroformes' => [
                        'formes' => ['Abomination', 'Gargouille', 'Guerrier du Chaos', 'Gobelin', 'Orque'],
                        'dernier' => 'Dragon',
                    ],
                    'meteore' => ['des_attaque' => 2],
                ],
                'sorts_dread' => [], 'archetype_lanceur' => 'qwindrak', 'phase_suivante' => null],

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

            // ---- Prophecy of Telor : FELLMARAK, LE ROI SORCIER (boss, chantier 2026-10-23) ----
            // Source : livret de quêtes G0052, p. 31 (quête 12, « The Rise of Fellmarak »). Stats
            // relues sur le RENDU PNG de la page (rendu/G0052_en-us_p16.png) : colonnes
            // MOVEMENT / ATTACK / DEFEND / BODY / MIND = 8 / 5 / 4 / 6 / 6. Répertoire (même page :
            // « Fellmarak knows the following Dread spells: Firestorm, Rust, Command, Fear, and
            // Cloud of Dread ») = archétype `fellmarak_roi_sorcier`. Aucune limite d'usage n'est
            // écrite : le budget de boss du projet (`MoteurDread::USAGES_BOSS`) s'applique.
            //
            // ⚠ ÉCART ASSUMÉ AU LIVRET (René, 2026-10-23 : « Fellmarak pourrait être juste mort sans
            // revenir ») : à 0 Body il MEURT, comme tout boss. Le livret le fait fuir par une porte
            // secrète (quête 12 : « cannot be killed ») et le fait mourir par tirage sous *Zargon's
            // Flame* (quête 13). Ces deux fins sont propres à l'arc d'un livret : un donjon généré ne
            // rejoue jamais une quête — elles sont ÉCARTÉES (docs/plan-telor.md, décision du
            // 2026-10-23). Aucune `phase_suivante` ni capacité de fuite ou de tirage. Non porté non
            // plus, parce que scripté dans UNE salle de la quête 12 : « Firestorm au premier tour
            // détruit les portes de la salle ».
            //
            // `cout` MESURÉ (docs/regles/bestiaire-et-rencontres.md) : attaques d'un héros à 3 dés
            // pour l'abattre = Body / (1,5 − Défense/6) = 6 / (1,5 − 4/6) = 7,2. Lu sur la droite
            // des boss (Seigneur 15 → 20 ; Gruzbella 21 → 23 ; pente 0,5) : 20 + 0,5 × (7,2 − 15)
            // = 16,1 ⇒ 16. Recoupement : la Horreur des Glaces a exactement ce profil de combat
            // (M8 A5 D4 B6, 7,2 attaques, cout 16) ; la Liche (même Body/Défense) coûte 18 avec une
            // invocation de plus.
            ['nom_base' => 'Fellmarak le Roi Sorcier', 'deplacement' => 8, 'attaque' => 5, 'defense' => 4,
                'pv_body' => 6, 'pv_mind' => 6, 'tier' => 'boss', 'boite' => 'prophecy_telor', 'cout' => 16,
                'capacites' => [], 'sorts_dread' => [], 'archetype_lanceur' => 'fellmarak_roi_sorcier'],
            // ---- Return of the Witch Lord (F4193, 2021/22) — lots B, C, D (chantier 2026-10-23) ----
            // Stats : Move/Attack/Defend/Body/Mind, `reference/18` §Witch Lord, relues sur le livret.
            // `cout` = attaques d'un héros à 3 dés pour abattre, Body / (1,5 − Défense/6), placé sur la
            // droite de SON palier (sous-boss : Ogre champion 7,2 → 11, Minotaure 9 → 12 ; boss : Ogre
            // commandant 9 → 15, Seigneur 15 → 20), plancher de palier 8 (sous-boss) comme l'Effigie.
            // Lecture sans vulnérabilité ni immunité : une créature qui ne craint que certaines sources est
            // comptée comme les autres (à trancher par René, voir `docs/plan-witch-lord.md` §Exécution).

            // CAVALIER-ESPRIT (Spirit Riders, q. 2, note E, p. 11) : variante de Squelette, sous-boss (plan Lot B).
            // Body 3 / (1,5 − 4/6) = 3,6 attaques : sous le plancher du palier, cout 8.
            ['nom_base' => 'Cavalier-esprit', 'deplacement' => 8, 'attaque' => 4, 'defense' => 4, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'witch_lord', 'cout' => 8, 'capacites' => [], 'sorts_dread' => []],

            // GARDE DU DREAD (Doomguard, q. 9-10, p. 25) : variante du Guerrier du Dread, sous-boss.
            // Body 3 / (1,5 − 6/6) = 6 attaques, sur la droite des sous-boss : cout 10.
            ['nom_base' => 'Garde du Dread', 'deplacement' => 8, 'attaque' => 4, 'defense' => 6, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'witch_lord', 'cout' => 10, 'capacites' => [], 'sorts_dread' => []],

            // SKULMAR, chef de la Légion Oubliée (q. 7, p. 21) : SOUS-BOSS (Q2, René 2026-10-15), comme Doralf.
            // « Skulmar should try to escape » : la capacité `fuit_si_affaibli` le fait fuir à un TIERS de ses
            // Body de départ (Q3, plancher de 3/3 = 1 Body), par SON sort de Fuite, déjà porté. Ce sort est de
            // palier boss : `repertoire_complet` le lui laisse lancer, sans quoi un sous-boss ne fuirait jamais.
            // Body 3 / (1,5 − 6/6) = 6 attaques : cout 10.
            ['nom_base' => 'Skulmar', 'deplacement' => 8, 'attaque' => 5, 'defense' => 6, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => 'witch_lord', 'cout' => 10,
                'capacites' => ['fuit_si_affaibli', 'repertoire_complet'], 'sorts_dread' => ['Fuite']],

            // KESSANDRIA, la Reine Sorcière (q. 9, p. 25) : SOUS-BOSS — tranché le 2026-10-23, le plan (Lot B) disait
            // boss. Précédent : les quatre LIEUTENANTS de Morcar sont des lanceurs à répertoire de boss et restent
            // sous-boss, l'Artificière seule ferme la campagne (G1504 ; `GabaritQueteSeeder`). Le Witch Lord ferme
            // ici, Kessandria est la quête 9, avant lui. Palier levé par `repertoire_complet` (ses sorts de palier
            // boss : Nuée d'Effroi, Patinage), SANS la règle « un sort par quête » de `sorts_uniques`.
            // Immunisée à tous les sorts SAUF le feu (« immune to all spells except fire spells », p. 25).
            // Répertoire du livret : lightning bolt (Éclair de Chaos), tempest (Tourmente), fear (Frayeur), sleep
            // (Sommeil), cloud of Dread (Nuée d'Effroi) ; sa potion de vitesse (12 cases sur le tour) est le sort
            // Patinage, Q4 (libellé « potion » par l'habillage). Fuit à un tiers (« should try to escape »).
            // Body 3 / (1,5 − 6/6) = 6 attaques : cout 10. Son immunité n'est pas comptée : les armes la blessent.
            ['nom_base' => 'Kessandria', 'deplacement' => 6, 'attaque' => 4, 'defense' => 6, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'boite' => 'witch_lord', 'cout' => 10,
                'capacites' => ['immunite_sorts_sauf' => ['feu'], 'fuit_si_affaibli', 'repertoire_complet'],
                'sorts_dread' => ['Éclair de Chaos', 'Tourmente', 'Frayeur', 'Sommeil', 'Nuée d\'Effroi', 'Patinage', 'Fuite']],

            // LE SEIGNEUR SORCIER (Witch Lord, boss final, q. 10, p. 27) : 10/5/6/4/5 (stats RELEVÉES par rapport au
            // jeu de base). BOSS : il ferme la campagne. « There must be no escape for him » : il NE FUIT JAMAIS —
            // aucune `fuit_si_affaibli`, aucune Fuite dans son répertoire, et c'est écrit ici pour que l'absence
            // soit un choix, pas un oubli (`WitchLordBestiaireTest`). Blessé UNIQUEMENT par la Lame des Esprits, le
            // « fire of wrath » (Trait de Feu), le « ball of flame » (Boule de Feu) et la Dague de jet magique
            // (`vulnerable_uniquement_a`, p. 27 : « can only be harmed by four things at this time »).
            // Répertoire : summon undead, firestorm, tempest, lightning bolt, fear, command. Body 4 / (1,5 − 6/6)
            // = 8 attaques, sur la droite des boss : cout 14.
            ['nom_base' => 'Seigneur Sorcier', 'deplacement' => 10, 'attaque' => 5, 'defense' => 6, 'pv_body' => 4, 'pv_mind' => 5,
                'tier' => 'boss', 'boite' => 'witch_lord', 'cout' => 14,
                'capacites' => ['vulnerable_uniquement_a' => ['Lame des Esprits', 'Trait de Feu', 'Boule de Feu', 'Dague de jet magique']],
                'sorts_dread' => ['Invocation de morts-vivants', 'Tempête de feu', 'Tourmente', 'Éclair de Chaos', 'Frayeur', 'Commandement']],

            // LES QUATRE STATUES D'ORCS (q. 10, note A, p. 27) : NON SEMÉES — voir `MotsClesAdversaires::SANS_DONNEE`.
            // La forme (invulnérable, arme brisée sauf exemptée) est construite et testée ; la règle « bloquent
            // totalement le couloir » est une règle de carte qu'une figure de bestiaire ne porte pas.

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

            // ----- Kellar's Keep (F4543, 2026-10-23 — docs/plan-kellars-keep.md, « Exécution (2026-10-23) ») -----
            //
            // Stats relues sur le livret, jamais devinées. `cout` MESURÉ par la méthode du bestiaire
            // (`docs/regles/bestiaire-et-rencontres.md`) : attaques-à-3-dés pour abattre = Body / (1,5 − Défense/6), placé
            // sur la droite du palier (sous-boss : 12 + 0,604 × (attaques − 8,9), d'après Ogre champion, Minotaure, Doralf).

            // Squelette-roi nain (q. 5 n.B, p. 17 : « MOVEMENT 6 · ATTACK 3 · DEFEND 4 · BODY 2 · MIND 0 »). « The skeletons
            // do not move or attack until one of the skeletons has been attacked. Then on Zargon's next turn, they all attack »
            // — DIVERGENT du Squelette de base (6/2/2/1/0), voulu par le livret (« more powerful than regular skeletons »).
            // 2 / (1,5 − 4/6) = 2,4 attaques → 8,1 → 8, le plancher du palier (Garde-mage, Effigie gardienne).
            ['nom_base' => 'Squelette-roi nain', 'deplacement' => 6, 'attaque' => 3, 'defense' => 4, 'pv_body' => 2, 'pv_mind' => 0,
                'tier' => 'sous_boss', 'boite' => 'kellars_keep', 'cout' => 8,
                'capacites' => ['passif_jusqua' => ['declencheur' => 'attaque_du_groupe']], 'sorts_dread' => []],

            // MÉTAMORPHE (Kellar's Keep, F4543 p. 25, quête 9, note A : « This monster is a shapechanger and is currently in the form
            // of an abomination. Every time it is killed, shuffle all the monster cards and take the top card. […] The monster can be
            // permanently killed only if the new card drawn matches the monster's most recent form. »). Forme de départ : l'Abomination
            // (carte © 2021, reference/20 : 6/3/3/2/3, aucune capacité). Pas de carte à lui : la carte d'Abomination, puis une carte
            // TIRÉE à chaque mort (`MoteurSynchroformes::metamorphe()`, `metamorphe` = `forme_de_depart`).
            // `cout` MESURÉ — lecture d'une créature qui REVIENT (la méthode mesure une forme ; ici il y en a plusieurs) : à chaque mort,
            // le tirage égale la forme qui tombe une fois sur huit (paquet de base : Gobelin, Squelette, Orque, Zombie, Momie, Guerrier du
            // Chaos, Gargouille, Abomination) → mort définitive ; en espérance 8 mort(s) avant elle. Chaque forme pèse 2,0 attaques en
            // moyenne (Gobelin 0,75 · Squelette 0,86 · Orque 0,86 · Zombie 1,0 · Momie 2,4 · Guerrier 3,6 · Gargouille 4,5 · Abomination 2,0,
            // moyenne 2,0) : 8 × 2,0 = 16,0 attaques. Droite des sous-boss, 12 + 0,604 × (16,0 − 8,9) = 16,3 ⇒ 16. La droite ne chiffre pas
            // la répétition elle-même : c'est cette lecture, écrite ici, qui la porte.
            // LE PAQUET est DÉCLARÉ ici, comme la pioche de Qwindrak (`synchroformes`) : les huit cartes 2021 de la boîte de base
            // (reference/20 : Goblin, Skeleton, Orc, Zombie, Mummy, Dread Warrior, Gargoyle, Abomination). Le Fimir de notre catalogue
            // (tier base, boîte base) n'a PAS de carte 2021 (reference/20 : « nom 1989 Milton Bradley ») : il n'entre pas dans le paquet.
            // Chiffres de la pondération : ceux du catalogue (la méthode mesure le jeu que nous jouons).
            ['nom_base' => 'Métamorphe', 'deplacement' => 6, 'attaque' => 3, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'kellars_keep', 'cout' => 16,
                'capacites' => ['metamorphe' => [
                    'forme_de_depart' => 'Abomination',
                    'paquet' => ['Gobelin', 'Squelette', 'Orque', 'Zombie', 'Momie', 'Guerrier du Chaos', 'Gargouille', 'Abomination'],
                ]],
                'sorts_dread' => []],

            // Gargouille-statue (q. 1 n.C, p. 9) : la Gargouille de base, en LIGNE NOMMÉE (ses capacités diffèrent — une
            // capacité posée sur la Gargouille rendrait toutes les gargouilles des salles en statues). « The gargoyle appears
            // to be a stone statue that does not move. […] If a hero searches for treasure before the trap has been disarmed,
            // the gargoyle springs to life […] The gargoyle cannot be harmed until it has either moved or attacked a hero. »
            // Éveil à la fouille du trésor de sa salle (`fouille_de_la_salle`) ; intouchable jusqu'à son premier geste.
            // Le piège du coffre n'est pas porté (le trésor de salle en tient lieu). Même `cout` que la Gargouille : 7.
            [...$gargouille, 'nom_base' => 'Gargouille-statue', 'boite' => 'kellars_keep',
                'capacites' => ['passif_jusqua' => ['declencheur' => 'fouille_de_la_salle', 'intouchable_jusqua' => 'action']]],

            // Gargouille gardienne (q. 10 n.C, p. 27 : « On Zargon's next turn, the gargoyle moves, attacks, and defends as a
            // normal gargoyle would, but it is also immune to all spells and has 4 Body Points. ») — le gardien de Grin's Crag,
            // DERNIER obstacle de la boîte : la porte de sortie ne s'ouvre qu'à sa mort. Boss final du thème. Le premier héros
            // qui pose le pied sur la case « C » l'active : déclencheur propre à cette quête, non porté — elle est posée comme
            // un boss ordinaire. `immunite_sorts` : le mot-clé de l'Effigie (`MotsClesAdversaires::IMMUNITE_SORTS`), totale.
            // `cout` : 4 / (1,5 − 5/6) = 6,0 attaques, sur la droite des boss (Ogre commandant 9 → 15, Seigneur 15 → 20) :
            // 12,5 → 13 (arrondi au supérieur, comme le Scion à 21,5 → 22 : l'immunité n'est pas chiffrée par la méthode).
            [...$gargouille, 'nom_base' => 'Gargouille gardienne', 'tier' => 'boss', 'boite' => 'kellars_keep', 'pv_body' => 4,
                'cout' => 13, 'capacites' => ['immunite_sorts'], 'sorts_dread' => []],

            // Borokk (q. 9 n.B, p. 25 : « Borokk has the same stats as a Dread warrior, but he also has a very special and deadly
            // magical power. On each of his turns, he attacks the mind of any hero in the same room or corridor and in his line
            // of sight. To do this, Borokk rolls 2 combat dice. For each skull he rolls, the target of the attack loses 1 Mind
            // Point. ») — sous-boss ; hors système de sorts (pas de répertoire). Le Mind à 0 = état de choc (Q1). `cout` :
            // 3 / (1,5 − 4/6) = 3,6 attaques → 8,8 → 9.
            [...$guerrierDuChaos, 'nom_base' => 'Borokk', 'tier' => 'sous_boss', 'boite' => 'kellars_keep', 'cout' => 9,
                'capacites' => ['attaque_mind' => ['des' => 2, 'portee' => 'salle_ou_couloir']], 'sorts_dread' => []],

            // Gragor (q. 6 n.E, p. 19 : « His stats are the same as a Dread warrior, but he also knows the following spells:
            // summon orcs, fear, rust, ball of flame, and lightning bolt »). Sous-boss (plan Kellar's Keep §7). Son répertoire est
            // l'archétype `gragor_kellar`. Deux de ses cinq sorts sont de palier BOSS (Invocation d'orques, Rouille) : le livret
            // lui donne le répertoire ENTIER, donc `repertoire_complet` lève le palier, comme pour Kessandria (fusion du 2026-10-23).
            // `cout` : 3,6 attaques → 9, comme Borokk.
            [...$guerrierDuChaos, 'nom_base' => 'Gragor', 'tier' => 'sous_boss', 'boite' => 'kellars_keep', 'cout' => 9,
                'capacites' => ['repertoire_complet'], 'sorts_dread' => [], 'archetype_lanceur' => 'gragor_kellar'],

            // Bellthor (Return of the Witch Lord, F4193 q. 5 n.B, p. 17 : « MOVEMENT 6 · ATTACK 4 · DEFEND 6 · BODY 3 · MIND 3 »).
            // « Bellthor does not move or attack on its turn until all of the heroes are in the room together. Bellthor cannot be
            // harmed until after it attacks. » Son souffle, « after it has attacked […] once per turn », roule 6 dés de combat ;
            // chaque crâne retire 1 Mind (`attaque_mind`, après attaque). NON PORTÉS : la fin « capturée » (héros retirés de la
            // quête — écartée, René 2026-10-15) et la brume de sa mort qui assomme la salle (non tranché). `cout` : 3 / (1,5 − 1)
            // = 6,0 attaques → 10,25 → 10. Boîte Witch Lord : hors thème tant que la boîte n'est pas allumée.
            ['nom_base' => 'Bellthor', 'deplacement' => 6, 'attaque' => 4, 'defense' => 6, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'witch_lord', 'cout' => 10,
                'capacites' => [
                    'passif_jusqua' => ['declencheur' => 'groupe_dans_la_salle', 'intouchable_jusqua' => 'attaque'],
                    'attaque_mind' => ['des' => 6, 'portee' => 'salle', 'apres_attaque' => true],
                ],
                'sorts_dread' => []],
            // ---- Spirit Queen's Torment (G0053, 2026-10-23) — lot C : deux sorciers nommés, sous le thème `spirit_queen`
            // (boîte allumée le 2026-10-23 — `DemarreurQuete::BOITES_THEMATIQUES`, « Oui » de René). ----
            // Source : livret de quêtes G0053. Kavra (quête 3, p. 11) : « Her stats are those of a Dread Warrior, with 1
            // additional Defend die. She may cast the Dread spells Lightning Bolt and Ball of Flame. On her first turn
            // only, she may cast both spells simultaneously. » Nelath (quête 14, p. 33) : « She has the statistics of a
            // Dread Warrior with 6 Body Points, and may cast … Command, Fear, Lightning Bolt, and Firestorm. She may cast
            // two spells in a single turn. »
            //
            // Le Dread Warrior est la CARTE officielle (`reference/20_cartes_monstres.md`, p03 : Move 7 · Attack 4 ·
            // Defend 4 · Body 3 · Mind 3), « la carte gagne, partout » (René, 2026-10-05) — les 6/3/4/1/3 du paquet de fan
            // de `docs/plan-spirit-queen.md` (2026-10-04) sont périmés et ne se portent pas.
            //
            // Aucune limite d'usage n'est écrite par le livret. Kavra garde le budget de son palier (`USAGES_SOUS_BOSS` 2),
            // comme Fellmarak : ses deux sorts partent au premier tour, le seul où sa carte lui en donne deux.
            // Nelath lance CHAQUE SORT DE SA LISTE UNE FOIS (`sorts_uniques`, la règle des sorciers de Morcar ; René,
            // 2026-10-29, option 2) : 4 sorts, donc deux tours complets à deux sorts. Le budget du palier (3) la laissait
            // à 2 puis 1 puis plus rien, et son double sort ne servait qu'une fois. `sorts_uniques` n'apporte rien
            // d'autre ici : son répertoire n'a ni invocation ni réaction, et elle est déjà au palier boss.
            //
            // `cout` MESURÉ (docs/regles/bestiaire-et-rencontres.md, attaques d'un héros à 3 dés = Body / (1,5 − Défense/6)) :
            // Kavra 3 / (1,5 − 5/6) = 4,5 attaques, sur la droite des SOUS-BOSS (Ogre champion 7,2 → 11 ; Doralf 10,5 → 13,
            // pente ≈ 0,61) : 11 − 0,61 × 2,7 ≈ 9,4 ⇒ 9. Nelath 6 / (1,5 − 4/6) = 7,2 attaques, sur la droite des BOSS
            // (Seigneur 15 → 20, pente 0,5) : 20 + 0,5 × (7,2 − 15) ≈ 16,1 ⇒ 16, comme Fellmarak (même Défense, même Body).
            // Les sorts ne sont pas comptés dans le `cout`, comme pour Fellmarak.
            ['nom_base' => 'Kavra', 'deplacement' => 7, 'attaque' => 4, 'defense' => 5, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'boite' => 'spirit_queen', 'cout' => 9,
                'capacites' => ['double_sort' => ['premier_tour' => true]], 'sorts_dread' => [],
                'archetype_lanceur' => 'kavra_sorciere'],
            ['nom_base' => 'Nelath, la Reine des Esprits', 'deplacement' => 7, 'attaque' => 4, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 3,
                'tier' => 'boss', 'boite' => 'spirit_queen', 'cout' => 16,
                'capacites' => ['double_sort', 'sorts_uniques'], 'sorts_dread' => [],
                'archetype_lanceur' => 'nelath_reine_des_esprits'],
        ];

        foreach ($monstres as $monstre) {
            Monstre::updateOrCreate(['nom_base' => $monstre['nom_base']], $monstre);
        }
    }
}
