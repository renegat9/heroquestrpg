<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Groupe extends Model
{
    protected $table = 'groupes';

    protected $fillable = [
        'identifiant',
        'nom',
        'theme',
        'longueur',
        'nb_quetes_total',
        'plan_campagne',
        'ton',
        'or',
        'etat',
        'phase',
        'quete_courante_id',
    ];

    protected function casts(): array
    {
        return [
            'plan_campagne' => 'array',
            'ton' => 'array',
        ];
    }

    /** Composition du groupe (+ initiative). */
    public function personnages(): BelongsToMany
    {
        return $this->belongsToMany(Personnage::class, 'groupe_personnages', 'groupe_id', 'personnage_id')
            ->withPivot(['ordre_initiative', 'actif']);
    }

    /** Personnages dont c'est le groupe actif. */
    public function personnagesActifs(): HasMany
    {
        return $this->hasMany(Personnage::class, 'groupe_actif_id');
    }

    public function quetes(): HasMany
    {
        return $this->hasMany(Quete::class, 'groupe_id');
    }

    public function queteCourante(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_courante_id');
    }

    /** Journal rejouable. */
    public function evenements(): HasMany
    {
        return $this->hasMany(Evenement::class, 'groupe_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class, 'groupe_id');
    }
}
