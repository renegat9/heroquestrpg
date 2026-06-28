<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Allié recruté et actif dans une quête (instance, doc 14 §3.5). Consommé en
 * fin de quête. Joué comme un « monstre allié » en phase dédiée (hors initiative
 * héros) : il cible les monstres, ne fait pas partie du roster.
 */
class GroupeMercenaire extends Model
{
    protected $table = 'groupe_mercenaires';

    protected $fillable = [
        'groupe_id',
        'mercenaire_id',
        'recruteur_personnage_id',
        'pv_body',
        'position_x',
        'position_y',
        'etat',
    ];

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupe_id');
    }

    /** Bloc de stats du catalogue. */
    public function mercenaire(): BelongsTo
    {
        return $this->belongsTo(Mercenaire::class, 'mercenaire_id');
    }

    public function recruteur(): BelongsTo
    {
        return $this->belongsTo(Personnage::class, 'recruteur_personnage_id');
    }
}
