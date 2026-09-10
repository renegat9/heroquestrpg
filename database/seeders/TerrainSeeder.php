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
                'effet' => [
                    'jet_des_combat' => 1,
                    'sur' => ['bouclier_blanc' => ['degats_pv_body' => 1]],
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
                'effet' => [
                    'jet_des_combat' => 1,
                    'recurrent' => 'par_tour_dans_la_zone',
                    'sur' => ['crane' => ['degats_pv_body' => 1]],
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
        ];

        foreach ($terrains as $terrain) {
            Terrain::updateOrCreate(
                ['nom' => $terrain['nom']],
                $terrain,
            );
        }
    }
}
