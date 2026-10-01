<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bloc de stats d'un allié recrutable (catalogue, doc 14 §3.5). PNJ scripté.
 */
class Mercenaire extends Model
{
    protected $table = 'mercenaires';

    protected $fillable = [
        'nom',
        'type',
        'deplacement',
        'attaque',
        'portee',
        'attaque_distance',
        'defense',
        'pv_body',
        'pv_mind',
        'capacites',
        'prix',
        'animal',
        // Le Squelette Hearthkin (First Light) partage ce catalogue sans
        // jamais être recrutable au hub : `MercenaireController::catalogue()`
        // l'exclut. Voir la migration `octroi_seul_sur_mercenaires`.
        'octroi_seul',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'capacites' => 'array',
            'animal' => 'boolean',
            'octroi_seul' => 'boolean',
        ];
    }

    public function instances(): HasMany
    {
        return $this->hasMany(GroupeMercenaire::class, 'mercenaire_id');
    }

    /** Attaque à distance (ligne de vue) plutôt qu'au contact. */
    public function aDistance(): bool
    {
        return $this->portee === 'distance';
    }
}
