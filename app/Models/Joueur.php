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

    /**
     * Roster du joueur — ses VRAIS héros. Les doublures d'arène (mode arène, lot A1) lui appartiennent aussi, pour
     * qu'il les pilote depuis sa manette, mais n'y figurent jamais : `Personnage::scopeHorsDoublures()`.
     */
    public function personnages(): HasMany
    {
        return $this->hasMany(Personnage::class, 'joueur_id')->horsDoublures();
    }
}
