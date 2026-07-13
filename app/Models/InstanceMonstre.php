<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceMonstre extends Model
{
    protected $table = 'instances_monstres';

    protected $fillable = [
        'quete_id',
        'monstre_id',
        'pv_body',
        'pv_body_max',
        'pv_mind',
        'position_x',
        'position_y',
        'etat',
        'elite',
        'revele',
        'habillage',
    ];

    protected function casts(): array
    {
        return [
            'habillage' => 'array',
            'revele' => 'boolean',
            'elite' => 'boolean',
        ];
    }

    /** Bonus fixe d'un monstre élite (3.6) : +1 attaque / +1 défense / +1 PV Body. */
    public const BONUS_ELITE = 1;

    /** Dés d'attaque effectifs (bloc de stats + bonus élite éventuel). */
    public function attaqueEffective(): int
    {
        return (int) $this->monstre->attaque + ($this->elite ? self::BONUS_ELITE : 0);
    }

    /** Dés d'attaque à distance effectifs (null si le monstre n'a pas de portée). */
    public function attaqueDistanceEffective(): ?int
    {
        $base = $this->monstre->attaque_distance;

        if ($base === null) {
            return null;
        }

        return (int) $base + ($this->elite ? self::BONUS_ELITE : 0);
    }

    /** Dés de défense effectifs (bloc de stats + bonus élite éventuel). */
    public function defenseEffective(): int
    {
        return (int) $this->monstre->defense + ($this->elite ? self::BONUS_ELITE : 0);
    }

    /**
     * PV Body MAX de cette instance : la valeur PROPRE (boss adaptés à la taille
     * du groupe, +1 élite intégré) si elle est fixée, sinon repli sur les PV du
     * catalogue + bonus élite (lignes antérieures à la colonne pv_body_max).
     */
    public function pvBodyMax(): int
    {
        return $this->pv_body_max !== null
            ? (int) $this->pv_body_max
            : (int) $this->monstre->pv_body + ($this->elite ? self::BONUS_ELITE : 0);
    }

    public function quete(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_id');
    }

    /** Bloc de stats du catalogue. */
    public function monstre(): BelongsTo
    {
        return $this->belongsTo(Monstre::class, 'monstre_id');
    }
}
