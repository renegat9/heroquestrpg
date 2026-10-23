<?php

namespace Database\Seeders;

use App\Models\Danger;
use Illuminate\Database\Seeder;

/**
 * Les DEUX dangers mobiles autonomes portés (2026-10-23), sourcés à la lettre — RIEN n'est deviné :
 *
 *  - **Rocher roulant** (*Kellar's Keep*, quête 3 « The Spiral Passage », note A, livret F4543 p. 12-13) :
 *    « When the last hero passes the square marked "A," a giant stone boulder falls from the ceiling. […] On Zargon's
 *    next turn and subsequent turns, roll 2 red dice to see how far down the corridor the boulder rolls […]. The
 *    boulder eventually crashes into the wall at the end of the passage […]. The passage is then blocked for the rest
 *    of the quest. Tell each hero hit by the boulder to roll 5 combat dice. For each skull rolled, a hero loses 1 Body
 *    Point. (No Defend dice are rolled.) The boulder trap cannot be searched for or disarmed. »
 *
 *  - **Brume de la mort** (*Return of the Witch Lord*, quête 3 « The Silent Passages », note A, livret F4193 p. 13) :
 *    « At the beginning of the quest, the death mist tile is placed […] next to the square marked A. […] The death
 *    mist tile moves up to 6 squares on each of Zargon's turns and can only move within the shaded corridors. When the
 *    death mist tile passes over a hero, they lose 1 Body Point. […] It does not affect monsters. The mist cannot move
 *    onto the same square twice in one turn. It also cannot end its turn on the same square with a hero. […] The mist
 *    is not affected by normal weapons. It can only be destroyed by a tempest spell or by the Spirit Blade. »
 *
 * ⚠ Non sourcé, donc non seedé : les PV de la brume (aucun chiffre dans le livret — la destruction se lit comme un
 * coup qualifiant), la direction qu'elle prend (le livret ne la dit pas, `MoteurDanger::avancer()` la fixe et le dit),
 * et la fréquence de pose (aucune source — `AssembleurCarte::CHANCE_DANGER`, écrite comme décision).
 *
 * `boite` : le rocher appartient à *Kellar's Keep*, la brume à *Return of the Witch Lord* — un thème ne les pose que
 * dans sa campagne (`DemarreurQuete::BOITES_THEMATIQUES`).
 */
class DangerSeeder extends Seeder
{
    public function run(): void
    {
        Danger::updateOrCreate(
            ['nom' => 'Rocher roulant'],
            [
                'boite' => 'kellars_keep',
                'effet' => [
                    'deplacement' => ['des_rouges' => 2],
                    'chemin' => 'ligne',
                    'degats' => ['des_combat' => 5],
                    'arret' => 'mur',
                    'pose' => 'declencheur',
                ],
            ],
        );

        Danger::updateOrCreate(
            ['nom' => 'Brume de la mort'],
            [
                'boite' => 'witch_lord',
                'effet' => [
                    'deplacement' => ['cases' => 6],
                    'chemin' => 'reseau_de_couloirs',
                    'degats' => ['pv' => 1],
                    'arret' => 'jamais',
                    'destruction' => ['Tempête', 'Lame des Esprits'],
                    'pose' => 'depart',
                ],
            ],
        );
    }
}
