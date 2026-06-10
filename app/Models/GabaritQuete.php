<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GabaritQuete extends Model
{
    protected $table = 'gabarits_quete';

    protected $fillable = [
        'nom',
        'type_jalon',
        'structure',
    ];

    protected function casts(): array
    {
        return [
            'structure' => 'array',
        ];
    }

    public function quetes(): HasMany
    {
        return $this->hasMany(Quete::class, 'gabarit_id');
    }
}
