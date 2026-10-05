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
        // Profil d'un CAPTIF à secourir (mission « secourir », 2026-10-04,
        // chantier 3b) : Gothar aujourd'hui, posé par `DemarreurQuete` sur la
        // carte (`groupe_mercenaires.etat = 'captif'`) puis libéré en jeu —
        // jamais recruté au hub, `octroi_seul` vaut donc toujours `true` ici
        // aussi (même garde que le Squelette Hearthkin, une seconde raison
        // d'exister pour la même colonne).
        'captif',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'capacites' => 'array',
            'animal' => 'boolean',
            'octroi_seul' => 'boolean',
            'captif' => 'boolean',
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
