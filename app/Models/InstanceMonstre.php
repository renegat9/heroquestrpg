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
        // Usages de sorts de Dread restants pour la rencontre, et les deux
        // verrous 1×/rencontre (Invocation, Fuite). EN COLONNE, jamais en
        // cache : perdre le compteur rendait le boss muet en silence.
        'usages_dread',
        'invocation_dread_utilisee',
        'fuite_dread_utilisee',
        // Braise du *Toucher du Brasier* (Moine) : points qui tomberont à la
        // fin du PROCHAIN tour de la créature, puis s'éteignent.
        'degat_differe',
        // *Baguette d'Os* : la créature est passée du côté des héros pour un
        // tour. `controle_par` dit CHEZ QUI (la phase des sbires se joue à la
        // fin du tour de ce héros-là), `controle_agi` si elle a déjà joué —
        // sans quoi `phaseMonstres()` la ferait rejouer côté Zargon dans le
        // même round.
        'controle_par',
        'controle_agi',
        'habillage',
    ];

    protected function casts(): array
    {
        return [
            'habillage' => 'array',
            'revele' => 'boolean',
            'elite' => 'boolean',
            'brule' => 'boolean',
            'usages_dread' => 'integer',
            'invocation_dread_utilisee' => 'boolean',
            'fuite_dread_utilisee' => 'boolean',
            'degat_differe' => 'integer',
            'controle_par' => 'integer',
            'controle_agi' => 'boolean',
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
     *
     * Deux conditions échappent au plancher parce que leurs cartes le disent
     * mot pour mot — « unable to move, attack, or defend » (Paralysé) et « so it
     * cannot move, attack, or defend itself » (Sommeil, doc 16 §3bis) : elles
     * rendent ZÉRO. C'est la même phrase, donc la même ligne.
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

        // Paralysé et ENDORMI : « unable to move, attack, or defend ». Zéro dé,
        // pas un malus — les deux seuls cas où le plancher à 1 ne s'applique
        // pas.
        //
        // ⚠ Le dormeur ne parait plus depuis le 2026-09-02 (René : « après tout,
        // il dort »). C'était le dernier écart de la carte de Sommeil, et le
        // corriger ICI plutôt qu'aux trois sites d'appel est ce qui garantit
        // qu'il vaut partout — coup de héros, sort, et attaque d'allié passent
        // tous par `defenseEffective()`.
        //
        // ⚠ L'ORDRE tient tout seul, et il compte : le réveil par l'attaque est
        // posé APRÈS la résolution dans les deux chemins de frappe. Le coup
        // porte donc bien sur un dormeur sans défense, puis le réveille.
        if (! empty($conditions['paralyse']) || ! empty($conditions['endormi'])) {
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
