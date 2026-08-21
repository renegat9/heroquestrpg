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
        // Styles Élémentaires dépensés (Moine) : un troisième rythme, qui se
        // recharge dès qu'aucun monstre n'est en vue. Voir StylesElementaires.
        'styles_epuises',
    ];

    /**
     * Chute et relèvement d'un héros — OBSERVÉS SUR LA COLONNE, jamais câblés
     * aux appelants.
     *
     * `tombe => true` est écrit depuis HUIT endroits (ResolveurTour ×4,
     * MoteurPieges, MoteurDread ×3) : coup de monstre, piège, tir ami, sort de
     * Dread, jetons de rejeton… Les brancher un par un, c'est se condamner à en
     * oublier un au prochain ajout. C'est exactement le raisonnement que porte
     * déjà `Personnage::booted()` pour les PV : « les PV REMONTENT depuis autant
     * d'endroits qu'ils descendent […] s'observe donc sur la colonne, jamais
     * sur les appelants ».
     *
     * Pourquoi ça compte : en campagne réelle (2026-08-20) une héroïne est
     * restée à terre vingt-deux minutes sans qu'une seule ligne ne le dise, et
     * aucun temps fort n'existait pour ça. Un personnage joueur qui s'écroule
     * est le moment le plus dramatique de la partie.
     *
     * Best-effort : une narration qui échoue ne doit jamais faire échouer le
     * coup qui vient d'être porté — on est au milieu d'une transaction de
     * résolution de tour.
     */
    protected static function booted(): void
    {
        static::updated(function (self $etat): void {
            if (! $etat->wasChanged('tombe')) {
                return;
            }

            $cle = $etat->tombe ? 'heros_tombe' : 'heros_releve';

            try {
                app(\App\Partie\Narration\AnnonceurChute::class)->annoncer($etat, $cle);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Annonce de chute/relèvement impossible.', [
                    'etat_id' => $etat->id,
                    'cle' => $cle,
                    'erreur' => $e->getMessage(),
                ]);
            }
        });
    }

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
            'styles_epuises' => 'array',
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
