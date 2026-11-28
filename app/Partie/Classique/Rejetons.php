<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;

/**
 * LES JETONS SPAWNLING POSÉS SUR LA FICHE — lot E2, *Jungles of Delthrak* (F9907) :
 * - la carte « monstre errant » (q. 1, 2, 3, 6, 11, 13) : « Wandering Monster in the Quest: Spawnling. When the Wandering
 *   Monster card is drawn, place a Spawnling tile on the triggering hero's card. The hero's turn immediately ends. » ;
 * - les cocons (q. 3 C, 6 C, 11 D, 13 D) : « one Blightcrawler Spawnling. Place a Spawnling tile on the hero's card. That
 *   hero's turn immediately ends » — effet de note `rejetons {nombre?}` (« four Blightcrawler Spawnlings (place all
 *   Spawnling tiles on the hero's card » q. 13 D).
 *
 * Point de passage unique de ces deux portes. Le jeton est celui du mode RPG (`etats_personnages_quete.jetons_rejeton`) :
 * 1 PV indéfendable par jeton à la fin de chacun de ses tours (`ResolveurTour::rongerParRejetons()`, p. 48 « More on
 * Spawnlings »), et l'action « arracher » (`detacher_rejetons`) — rien de neuf à cet endroit.
 *
 * « The hero's turn immediately ends » : `FinDeTourForcee` — toute la cérémonie de fin de tour se joue, jetons compris.
 *
 * La carte errante se lit sur la figure désignée par l'auteur : un monstre errant qui S'ACCROCHE (`s_accroche`, le Rejeton
 * putride) ne se pose pas, il devient des jetons (`nombre` du `monstre_errant`).
 */
final class Rejetons
{
    /** La capacité de la fiche qui fait d'une figure un jeton (`MonstreSeeder` : le Rejeton putride). */
    public const CAPACITE = 's_accroche';

    /** L'errant désigné par l'auteur s'accroche-t-il au lieu de se poser ? Quêtes construites seulement. */
    public static function errantQuiSAccroche(Quete $quete, Monstre $monstre): bool
    {
        return $quete->estConstruite() && (new InstanceMonstre)->setRelation('monstre', $monstre)->aCapacite(self::CAPACITE);
    }

    /**
     * Pose `$nombre` jetons sur la fiche du héros ; son tour s'arrête.
     *
     * @return array{nombre: int, jetons: int, personnage_id: int, nom: string}
     */
    public function poser(Personnage $heros, EtatPersonnageQuete $etat, int $nombre): array
    {
        $nombre = max(1, $nombre);
        $etat->update(['jetons_rejeton' => (int) $etat->jetons_rejeton + $nombre]);
        FinDeTourForcee::demander($etat);

        return ['nombre' => $nombre, 'jetons' => (int) $etat->fresh()->jetons_rejeton,
            'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom];
    }
}
