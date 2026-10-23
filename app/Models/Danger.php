<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalogue de référence des DANGERS MOBILES AUTONOMES (rocher roulant, brume de la mort) — données seedées, jamais
 * modifiées en jeu, même statut que `Terrain` / `Mobilier` / `Piege`.
 *
 * `effet` porte le vocabulaire fermé `App\Engine\MotsClesDanger` (vitesse, trajet, dégâts, arrêt, destruction, pose).
 * `boite` filtre la POSE par thème de campagne (`AssembleurCarte::placerDangers()`), comme `Terrain::$boite`.
 * L'état d'une occurrence (position, passages, destruction) n'est PAS ici : il est dans `cartes.grille['dangers']`.
 */
class Danger extends Model
{
    protected $table = 'dangers';

    protected $fillable = ['nom', 'boite', 'effet'];

    protected function casts(): array
    {
        return [
            'effet' => 'array',
        ];
    }
}
