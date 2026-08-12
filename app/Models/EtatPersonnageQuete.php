<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtatPersonnageQuete extends Model
{
    protected $table = 'etat_personnage_quete';

    public $timestamps = false;

    protected $fillable = [
        'personnage_id',
        'quete_id',
        'position_x',
        'position_y',
        'a_joue',
        'a_deplace',
        'a_agi',
        'deplacement_tour',
        'deplacement_restant',
        'tombe',
        'garde_tenace_utilisee',
        'bonus_sort_utilise',
        'attaque_supplementaire',
        // Arrêt du temps : un TOUR entier de plus, consommé à la fin du tour.
        'tour_supplementaire',
        // Rejetons accrochés (Jungles of Delthrak) : 1 PV automatique et
        // indéfendable par jeton, à chaque fin de tour du porteur.
        'jetons_rejeton',
        // Réaction hors tour proposée au joueur (Dark Wings, Twisting Torrent) :
        // {sort_id, nom, source, degats, expire_a}. Voir App\Partie\MoteurReactions.
        'reaction_en_attente',
        // Capacités « once per quest » déjà dépensées (liste de noms). Un
        // booléen par capacité aurait fait 24 colonnes.
        'capacites_utilisees',
        // Idem pour les « once per TURN » — remises à zéro avec les créneaux,
        // en fin de round, et non à la quête suivante.
        'capacites_tour',
    ];

    protected function casts(): array
    {
        return [
            'a_joue' => 'boolean',
            'a_deplace' => 'boolean',
            'a_agi' => 'boolean',
            'tombe' => 'boolean',
            'garde_tenace_utilisee' => 'boolean',
            'bonus_sort_utilise' => 'boolean',
            'attaque_supplementaire' => 'boolean',
            'tour_supplementaire' => 'boolean',
            'deplacement_tour' => 'integer',
            'deplacement_restant' => 'integer',
            'reaction_en_attente' => 'array',
            'capacites_utilisees' => 'array',
            'capacites_tour' => 'array',
        ];
    }

    public function personnage(): BelongsTo
    {
        return $this->belongsTo(Personnage::class, 'personnage_id');
    }

    public function quete(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_id');
    }
}
