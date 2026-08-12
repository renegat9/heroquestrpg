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
        // Brûlée : la régénération s'arrête définitivement (carte du troll,
        // « damage done by fire is permanent »).
        'brule',
        // Braise du *Toucher du Brasier* (Moine) : points qui tomberont à la
        // fin du PROCHAIN tour de la créature, puis s'éteignent.
        'degat_differe',
        'habillage',
    ];

    protected function casts(): array
    {
        return [
            'habillage' => 'array',
            'revele' => 'boolean',
            'elite' => 'boolean',
            'brule' => 'boolean',
            'degat_differe' => 'integer',
        ];
    }

    /** Bonus fixe d'un monstre élite (3.6) : +1 attaque / +1 défense / +1 PV Body. */
    public const BONUS_ELITE = 1;

    /** Dés d'attaque effectifs (bloc de stats + bonus élite éventuel). */
    public function attaqueEffective(): int
    {
        $des = (int) $this->monstre->attaque + ($this->elite ? self::BONUS_ELITE : 0);

        return $this->apresConditions($des, 'attaque');
    }

    /**
     * Applique les conditions posées sur l'instance (`habillage.conditions`) à
     * un nombre de dés — deux sorts en dépendent, et tous deux plafonnent :
     *
     *  - **Terreur** (Warlock) : « attacks are reduced to 1 combat die ». Ce
     *    n'est PAS un malus : c'est un plafond, qui ramène un ogre à 4 dés
     *    comme un gobelin à 2.
     *  - **Ralenti** (Slow, répertoire elfique) : « rolls 1 less combat die
     *    when it attacks OR DEFENDS », donc les deux, « cannot be less than 1 ».
     *
     * ⚠ Un plancher à 1 dans les deux cas : le texte des deux cartes l'exige,
     * et un monstre à 0 dé d'attaque ne pourrait plus jamais toucher.
     */
    private function apresConditions(int $des, string $volee): int
    {
        $conditions = (array) data_get($this->habillage, 'conditions', []);

        if ($volee === 'attaque' && ! empty($conditions['terrifie'])) {
            $des = min($des, 1);
        }

        if (! empty($conditions['ralenti'])) {
            $des -= 1;
        }

        // Paralysé : « unable to move, attack, or defend ». Zéro dé, pas un
        // malus — c'est le seul cas où le plancher à 1 ne s'applique pas.
        if (! empty($conditions['paralyse'])) {
            return 0;
        }

        return max(1, $des);
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
        $des = (int) $this->monstre->defense + ($this->elite ? self::BONUS_ELITE : 0);

        return $this->apresConditions($des, 'defense');
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

    /**
     * Nom tel qu'on le MONTRE au joueur : l'habillage IA (ou le nom de
     * catalogue), suivi d'une **étoile** quand l'instance est une élite.
     *
     * Une élite a plus de PV que sa fiche de bestiaire, et rien ne le disait :
     * deux « Pilleur des Cryptes affamé » côte à côte, l'un à 1 PV et l'autre à
     * 2, sans le moindre indice (signalé par René, 2026-08-07 — « un gobelin
     * devrait avoir 1 pv pas 2 »).
     *
     * ⚠ Réservé à l'AFFICHAGE. Le contexte de l'IA (`ContexteAssembleur`) reçoit
     * le nom NU : lui passer l'étoile ferait écrire au narrateur « le Gobelin ★
     * s'avance ».
     */
    public function nomAffiche(): string
    {
        $nom = $this->habillage['nom'] ?? $this->monstre?->nom_base ?? 'Créature';

        return $this->elite ? "{$nom} ★" : (string) $nom;
    }
}
