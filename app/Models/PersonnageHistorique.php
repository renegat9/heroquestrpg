<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnageHistorique extends Model
{
    protected $table = 'personnage_historique';

    public $timestamps = false;

    protected $fillable = [
        'personnage_id',
        'groupe_nom',
        'theme',
        'resume',
        'issue',
        'niveau_atteint',
        'termine_le',
    ];

    protected function casts(): array
    {
        return [
            'termine_le' => 'datetime',
        ];
    }

    public function personnage(): BelongsTo
    {
        return $this->belongsTo(Personnage::class, 'personnage_id');
    }
}
