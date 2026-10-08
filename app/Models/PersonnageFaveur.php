<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une faveur de Hopekins Rest acquise par un héros (chantier 1c, Wizards of
 * Morcar, 2026-10-06) — voir App\Partie\FaveursHopekins pour le vocabulaire
 * fermé de `cle` et tous les lecteurs.
 */
class PersonnageFaveur extends Model
{
    protected $table = 'personnage_faveurs';

    protected $fillable = [
        'personnage_id',
        'cle',
        'parametre',
    ];

    public function personnage(): BelongsTo
    {
        return $this->belongsTo(Personnage::class, 'personnage_id');
    }
}
