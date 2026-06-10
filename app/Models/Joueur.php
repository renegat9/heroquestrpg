<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Joueur extends Model
{
    protected $table = 'joueurs';

    protected $fillable = [
        'pseudo',
        'identifiant',
        'mot_de_passe',
    ];

    protected $hidden = [
        'mot_de_passe',
    ];

    protected function casts(): array
    {
        return [
            'mot_de_passe' => 'hashed',
        ];
    }

    /** Roster du joueur. */
    public function personnages(): HasMany
    {
        return $this->hasMany(Personnage::class, 'joueur_id');
    }
}
