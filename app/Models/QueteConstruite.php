<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une quête d'une campagne construite (MODE CLASSIQUE, lot K1). Ligne de PROJECTION : `document` est l'objet `quete`
 * de `campagnes_construites.document.quetes[rang - 1]`, réécrit à chaque enregistrement de la campagne.
 */
class QueteConstruite extends Model
{
    protected $table = 'quetes_construites';

    protected $fillable = [
        'campagne_construite_id',
        'rang',
        'slug',
        'titre',
        'document',
    ];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'rang' => 'integer',
        ];
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneConstruite::class, 'campagne_construite_id');
    }
}
