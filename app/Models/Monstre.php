<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monstre extends Model
{
    protected $table = 'monstres';

    protected $fillable = [
        'nom_base',
        'deplacement',
        'attaque',
        'defense',
        'pv_body',
        'pv_mind',
        'tier',
        'cout',
        'capacites',
        'sorts_dread',
    ];

    protected function casts(): array
    {
        return [
            'capacites' => 'array',
            'sorts_dread' => 'array',
        ];
    }

    public function instances(): HasMany
    {
        return $this->hasMany(InstanceMonstre::class, 'monstre_id');
    }

    /** Mind 0 → immunité aux effets mentaux (morts-vivants). */
    public function immuniseMental(): bool
    {
        return $this->pv_mind === 0;
    }
}
