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
        'portee',
        'attaque_distance',
        'defense',
        'pv_body',
        'pv_mind',
        'tier',
        'cout',
        'grande_taille',
        'capacites',
        'sorts_dread',
        'archetype_lanceur',
    ];

    protected function casts(): array
    {
        return [
            'capacites' => 'array',
            'sorts_dread' => 'array',
            'grande_taille' => 'array',
        ];
    }

    /** Attaque à distance (ligne de vue) plutôt qu'au contact. */
    public function aDistance(): bool
    {
        return $this->portee === 'distance';
    }

    /** Emprise en cases : [largeur, hauteur]. Par défaut 1×1. */
    public function emprise(): array
    {
        $t = $this->grande_taille;

        return [
            'l' => (int) ($t['l'] ?? 1),
            'h' => (int) ($t['h'] ?? 1),
        ];
    }

    public function grandeTaille(): bool
    {
        $e = $this->emprise();

        return $e['l'] > 1 || $e['h'] > 1;
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
