<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La visite d'un héros à Hopekins Rest, à UN tour de hub (chantier faveurs, 2026-10-16) : la quête
 * achevée qui précède ce hub (`quete_id`) et le lieu choisi (`lieu`, clé de
 * `App\Partie\FaveursHopekins::TOUTES`), `null` quand le héros n'en visite aucun. Une ligne = la
 * décision est prise ; pas de ligne = à choisir. Voir `App\Partie\FaveursHopekins::choisirLieu()`.
 */
class PersonnageVisiteHopekins extends Model
{
    protected $table = 'personnage_visites_hopekins';

    protected $fillable = [
        'personnage_id',
        'quete_id',
        'lieu',
    ];

    public function personnage(): BelongsTo
    {
        return $this->belongsTo(Personnage::class, 'personnage_id');
    }

    public function quete(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_id');
    }
}
