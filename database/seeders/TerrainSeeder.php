<?php

namespace Database\Seeders;

use App\Models\Terrain;
use Illuminate\Database\Seeder;

/**
 * Les 7 terrains de The Frozen Horror qui portent une RÈGLE (doc 18 §4, lignes
 * ~595-611 — SEULE source, rien n'est deviné) : `Glace glissante`, `Glissière
 * de glace`, `Rivière gelée`, `Tunnel de glace`, `Chambre forte de glace`,
 * `Glace magique`, `Rebord de crevasse`.
 *
 * ⚠ N'y figurent PAS : le *Bottomless Chasm* (mort permanente — hors périmètre,
 * décision de René, le moteur n'a aucune mort permanente, cf.
 * `docs/plan-glace-et-degats-mind.md` §5.5), la *Living Fog Room* et le
 * *Sceptre* (dettes nommées, aucune couture n'existe pour elles aujourd'hui) —
 * ni les autres entrées de la doc (Frozen Crypt Room, Cage Room, Seat of Power,
 * Ice Cave Entrance, Ice Gremlin Treasure Room…), qui sont des noms de SALLE
 * sans mécanique propre, pas du terrain.
 *
 * ⚠ Le *Crystal Key Tile* est de ce dernier groupe, et le dire explicitement
 * vaut mieux que de le laisser dans le lot : `docs/plan-glace-et-degats-mind.md`
 * lui prêtait une mécanique (« clé d'ouverture, proche de `leviers` ») que la
 * SOURCE n'énonce pas. Doc 18 §4 ne fait que le NOMMER, dans la même
 * énumération que *Scepter Room* et *Cage Room* — aucune règle, aucun chiffre.
 * Le seeder d'après le plan aurait donc inventé une tuile, ce que
 * « ne jamais seeder une valeur que les livrets ne sourcent pas » interdit.
 * C'est le PLAN qui avait tort, pas cette absence ; elle est ici par écrit
 * pour qu'un prochain passage ne la reprenne pas pour un oubli.
 *
 * ⚠ Aucun des 7 ne bloque le mouvement ni la vue à ce jour — ce sont des
 * dangers de SOL (on marche dessus, on glisse, on coûte plus cher), pas des
 * murs. La seule pièce du lot qui bloquera un jour le mouvement (le Mur de
 * Glace, sort du boss — « jusqu'à 4 cases de glace pleine qui bloquent le
 * déplacement mais pas la vue ») N'EST PAS un terrain de catalogue : c'est un
 * effet posé EN COURS DE QUÊTE par un sort (Phase 2 du plan), sur une couche
 * distincte (`carte.grille['glace']`) — hors périmètre de cette table.
 *
 * `effet` (json) porte le VOCABULAIRE des règles que le prochain agent
 * câblera : jets de dé de combat (`jet_des_combat`, faces de
 * `App\Engine\Des\FaceDeCombat` — crane/bouclier_blanc/bouclier_noir),
 * dégâts, récurrence, téléportation, support de sort, décor. Cette phase-ci ne
 * pose que les FONDATIONS (données, placement, lecture de grille, publication)
 * — AUCUNE de ces clés n'a de lecteur aujourd'hui, exactement comme
 * `epreuves.effet` avant `MoteurEpreuves` ou `pieges.effet` avant
 * `MoteurPieges`.
 *
 * `boite` (2026-09-06, phase 6a) : les 7 lignes valent TOUTES
 * `horreur_des_glaces` — même colonne, même lecture que `Monstre::$boite`
 * (`null` = « convient à tout thème »). `AssembleurCarte::placerTerrains()`
 * ne pose un terrain que si sa boîte est `null` ou égale au thème FIGÉ du
 * groupe (`groupes.theme_bestiaire`) : sans cette colonne, déclarer
 * `structure.terrains` dans un gabarit aurait posé une Rivière gelée dans une
 * quête de jungle. ⚠ Conséquence assumée : `horreur_des_glaces` n'étant pas
 * dans `DemarreurQuete::BOITES_THEMATIQUES` (règles encore incomplètes), la
 * couche entière reste posée mais INERTE en jeu réel jusqu'à la réactivation
 * de la boîte — le même sort que son boss et ses créatures.
 *
 * ⚠ CLÉ SUR `nom`, PAS de purge — même raison que `MobilierSeeder` : la carte
 * de la quête stockera un `terrain_id` (`cartes.grille.terrain[]`), et
 * re-semer en purgeant orphelinerait chaque case de terrain d'une quête en
 * cours, sans une seule erreur.
 */
class TerrainSeeder extends Seeder
{
    public function run(): void
    {
        $terrains = [
            // Glace glissante (Slippery Ice) — « case glissante placée
            // seulement au contact, jet de 1 dé de combat, bouclier blanc =
            // chute et fin de tour immédiate » (doc 18 §4).
            [
                'nom' => 'Glace glissante', 'nom_anglais' => 'Slippery Ice',
                'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'horreur_des_glaces',
                'effet' => [
                    'jet_des_combat' => 1,
                    'sur' => ['bouclier_blanc' => ['chute' => true, 'fin_tour' => true]],
                ],
            ],
            // Glissière de glace (Ice Slide) — « glissière à SENS UNIQUE, fin
            // de tour, 1 Body Point sur bouclier blanc » (doc 18 §4).
            // ⚠ Le SENS n'est sourcé nulle part (aucune règle procédurale de
            // portage n'existe pour le déterminer) : c'est une DETTE, à
            // trancher par l'agent qui câble le déplacement — voir le rapport
            // de la phase 4a.
            [
                'nom' => 'Glissière de glace', 'nom_anglais' => 'Ice Slide',
                'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'horreur_des_glaces',
                'effet' => [
                    'sens_unique' => true,
                    // « Monsters cannot move onto ice slide squares » (p. 5).
                    'interdit_aux_monstres' => true,
                    'fin_tour' => true,
                    'jet_des_combat' => 1,
                    'sur' => ['bouclier_blanc' => ['degats_pv_body' => 1]],
                ],
            ],
            // Rivière gelée (Icy River) — « coûte 2 cases de déplacement par
            // case, dégâts sur bouclier blanc » (doc 18 §4). C'EST la tuile
            // qui impose le parcours PONDÉRÉ (plan §2) : `cout_deplacement` est
            // lu par `Grille` dès cette phase, mais `casesAtteignables()` /
            // `chemin()` restent une BFS uniforme tant qu'un autre agent ne
            // les rend pas pondérés (le plan l'autorise explicitement à
            // différer ce point).
            [
                'nom' => 'Rivière gelée', 'nom_anglais' => 'Icy River',
                'cout_deplacement' => 2, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'horreur_des_glaces',
                // `type_degat: froid` (2026-09-10) : le dégât de terrain est
                // un FROID au sens propre — Morsure de Froid en a déjà donné
                // la source. Sans cette clé, l'Anneau de Chaleur/le Bracelet
                // de Glace ne pourraient jamais intercepter une noyade de
                // rivière gelée, quand bien même le lecteur existerait.
                'effet' => [
                    'jet_des_combat' => 1,
                    'sur' => ['bouclier_blanc' => ['degats_pv_body' => 1]],
                    'type_degat' => 'froid',
                    // « Monsters suffer neither movement penalties nor damage
                    // from the icy river » (p. 6).
                    'ignore_par_monstres' => true,
                ],
            ],
            // Tunnel de glace (Ice Tunnels) — « paires de téléportation, très
            // nombreuses dans cette boîte » (doc 18 §4). Aucun dé, aucun
            // dégât : la carte téléporte, elle ne punit pas. Les DEUX
            // extrémités d'une paire partagent un `paire_id` posé par
            // `AssembleurCarte::placerTerrains()` (jamais dans le catalogue :
            // une seule ligne sert TOUTES les paires d'une carte).
            [
                'nom' => 'Tunnel de glace', 'nom_anglais' => 'Ice Tunnels',
                'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'horreur_des_glaces',
                'effet' => ['teleportation' => true],
            ],
            // Chambre forte de glace (Ice Vault) — « inflige 1 Body Point par
            // tour passé dedans, sur un skull » (doc 18 §4). RÉCURRENT par
            // tour et non au contact — le patron du poison
            // (`degats_pv_body_par_tour`, déjà lu par `MoteurDegats` pour la
            // condition Empoisonné), la source du dégât étant ici le TERRAIN
            // et non une condition portée par le héros.
            [
                'nom' => 'Chambre forte de glace', 'nom_anglais' => 'Ice Vault',
                'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'horreur_des_glaces',
                // `type_degat: froid` (2026-09-10) : même raison que la
                // Rivière gelée juste au-dessus — sans elle, aucun anneau ne
                // pourrait jamais protéger d'un froid qui gèle une chambre
                // forte plutôt qu'un sort.
                'effet' => [
                    'jet_des_combat' => 1,
                    'recurrent' => 'par_tour_dans_la_zone',
                    'sur' => ['crane' => ['degats_pv_body' => 1]],
                    'type_degat' => 'froid',
                ],
            ],
            // Glace magique (Magic Ice) — « support du sort Ice Bridge / Ice
            // Wall » (doc 18 §4). Pas d'effet propre : un ANCRAGE pour deux
            // sorts du boss (Pont de Glace / Mur de Glace, `config/cartes.php`),
            // dont seul le second est porté (plan Phase 2). Pur décor tant
            // qu'aucun sort ne la lit.
            [
                'nom' => 'Glace magique', 'nom_anglais' => 'Magic Ice',
                'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'horreur_des_glaces',
                'effet' => ['support_sort' => ['Mur de Glace', 'Pont de Glace']],
            ],
            // Rebord de crevasse (Ice Ledge) — décor lié au Bottomless Chasm,
            // HORS PÉRIMÈTRE (René, cf. plan §5.5 : le moteur n'a aucune mort
            // permanente). Seedé pour la complétude du catalogue demandée
            // explicitement, SANS mécanique : ni jet, ni dégât. Une dette
            // nommée, pas un oubli.
            [
                'nom' => 'Rebord de crevasse', 'nom_anglais' => 'Ice Ledge',
                'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'horreur_des_glaces',
                'effet' => ['decor' => true],
            ],

            // ===== Jungles of Delthrak (livret F9907 p. 4-5, lots B et D) =====
            // Boîte `jungles_delthrak` : `AssembleurCarte::placerTerrains()` ne
            // les pose que sous ce thème (« le gabarit dit COMBIEN, le thème dit
            // LESQUELS »), et `boite` les range du côté de la jungle pour que la
            // Rivière gelée ne tombe jamais dans une quête de jungle ni le sable
            // dans une quête de glace.

            // TERRAIN GÊNANT (« Hindering Terrain Tiles », p. 4) : « There are
            // three types of hindering terrain: sand, web, and jungle. Each
            // single square of hindering terrain costs heroes and other
            // creatures, including monsters, 2 squares of movement to traverse. »
            // TROIS entrées, UNE règle : le livret ne distingue les trois types
            // que par l'image, jamais par l'effet — le nom ne pèse que pour le
            // joueur (teinte de case et légende). `entravant` est le FAIT d'être
            // « gênant » : Agile (monstres, p. 48), le talent
            // `ignore_terrain_entravant` et les Bracers of the Wild (p. 50) le
            // lèvent, jamais la Rivière gelée. ⚠ Aucun ne bloque ni mouvement ni
            // vue : un terrain gênant ralentit, il ne barre rien.
            [
                'nom' => 'Sable entravant', 'nom_anglais' => 'Hindering sand',
                'cout_deplacement' => 2, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'jungles_delthrak',
                'effet' => ['entravant' => true],
            ],
            [
                'nom' => 'Toile entravante', 'nom_anglais' => 'Hindering web',
                'cout_deplacement' => 2, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'jungles_delthrak',
                'effet' => ['entravant' => true],
            ],
            [
                'nom' => 'Jungle entravante', 'nom_anglais' => 'Hindering jungle',
                'cout_deplacement' => 2, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'jungles_delthrak',
                'effet' => ['entravant' => true],
            ],

            // LE BASIN EN TROIS ENTRÉES (René, 2026-10-09, Q5 : « trois entrées
            // distinctes ») — *Pool of Water*, *Crystal Cluster* (mobilier
            // attaquable, `MobilierSeeder`), *Bonfire*. La Mare et le Brasier
            // sont des TERRAINS et non du mobilier, par la question que pose
            // cette couche (« que se passe-t-il quand je la TRAVERSE ou que j'y
            // reste ? ») : ni l'un ni l'autre ne bloque quoi que ce soit — « may
            // move through » —, alors que l'Amas de cristal bloque la vue et
            // s'attaque. Un effet de franchissement qui ne bloque pas se lit là
            // où se lisent déjà la Rivière gelée et la Glissière, pas à la boucle
            // du mobilier.

            // MARE (« Pool of Water », p. 4) : « Creatures may move through the
            // pool of water but may not end their turn occupying the same space
            // as it. If a hero searches for treasure in an area containing a
            // pool of water, they may choose to restore 1 lost Body Point
            // instead of drawing from the treasure deck. The pool of water does
            // not block line of sight. »
            [
                'nom' => 'Mare', 'nom_anglais' => 'Pool of Water',
                'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'jungles_delthrak',
                'effet' => ['interdit_arret' => true, 'soin_a_la_fouille' => 1],
            ],

            // BRASIER (« Bonfire », p. 4) : « Creatures may move through the
            // bonfire but may not end their turn occupying the same space as
            // it. Any creature who moves through the bonfire must roll 1 combat
            // die. If they roll a skull, they suffer 1 Body Point of damage.
            // The bonfire does not block line of sight. » Le jet est celui, déjà
            // lu, de la Rivière gelée (`jet_des_combat` + `sur` + `degats_pv_body`,
            // un jet par case ENTRÉE, jamais d'arrêt) — la seule différence est
            // la FACE (un crâne, ici) et la nature du dégât : `feu`, parce que le
            // livret l'appelle un feu (`reference/18` §4 : « le feu inflige 1 Body
            // Point »). Seule une immunité au FEU l'intercepte (Anneau de Feu, Chair
            // impie, potion de résistance au feu) : l'Anneau de Chaleur couvre le
            // froid et ne l'arrête pas. « Any CREATURE » : le monstre brûle comme le
            // héros (`ResolveurTour::blesserMonstreSurLeChemin()`), à la différence de la rivière.
            [
                'nom' => 'Brasier', 'nom_anglais' => 'Bonfire',
                'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => 'jungles_delthrak',
                'effet' => [
                    'interdit_arret' => true,
                    'jet_des_combat' => 1,
                    'sur' => ['crane' => ['degats_pv_body' => 1]],
                    'type_degat' => 'feu',
                ],
            ],
        ];

        foreach ($terrains as $terrain) {
            Terrain::updateOrCreate(
                ['nom' => $terrain['nom']],
                $terrain,
            );
        }
    }
}
