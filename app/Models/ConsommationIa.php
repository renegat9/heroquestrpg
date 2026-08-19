<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne = une réponse HTTP LLM réellement facturée (voir {@see
 * \App\Agent\TraceurConsommation}) — journal append-only de télémétrie,
 * jamais de l'état de jeu : pas de FK/cascade sur `groupe_id` (voir la
 * migration), la table survit à la clôture/purge d'une campagne. Pas
 * d'`updated_at` : une ligne n'est jamais modifiée après écriture.
 */
class ConsommationIa extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'consommation_ia';

    protected $fillable = [
        'groupe_id',
        'skill',
        'fournisseur',
        'modele',
        'tokens_entree',
        'tokens_sortie',
        'tokens_cache',
        'tentative',
    ];

    protected function casts(): array
    {
        return [
            'tokens_entree' => 'integer',
            'tokens_sortie' => 'integer',
            'tokens_cache' => 'integer',
            'tentative' => 'integer',
        ];
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupe_id');
    }

    /**
     * Agrégat exposé par `GET /api/parametres` (panneau Réglages, bloc
     * « Consommation IA ») — cumul depuis l'origine de cette table (elle
     * survit à la clôture/purge d'une campagne, voir la migration) : le but
     * est de VÉRIFIER dans la durée le gain du lot « récits pré-générés »
     * (~145 appels/quête → 2), pas de piloter un budget en temps réel.
     *
     * `nb_quetes_mesurees` (dénominateur de « moyenne par quête ») compte les
     * quêtes ENCORE en base : une campagne close purge ses quêtes
     * (`ClotureCampagne::purger`) mais pas cette table de télémétrie, donc
     * cette moyenne est un ratio VIVANT sur les campagnes actuellement
     * ouvertes — pas un historique exact toutes campagnes confondues, faute
     * d'un `quete_id` sur cette table (portée volontairement à `groupe_id`
     * seul, voir la migration).
     *
     * @return array<string, mixed>
     */
    public static function agregat(): array
    {
        $ligne = static::query()
            ->selectRaw('COUNT(*) as appels')
            ->selectRaw('SUM(CASE WHEN tentative > 1 THEN 1 ELSE 0 END) as appels_retries')
            ->selectRaw('COALESCE(SUM(tokens_entree), 0) as tokens_entree')
            ->selectRaw('COALESCE(SUM(tokens_sortie), 0) as tokens_sortie')
            ->selectRaw('COALESCE(SUM(tokens_cache), 0) as tokens_cache')
            ->selectRaw('MIN(created_at) as depuis')
            ->first();

        $appels = (int) ($ligne->appels ?? 0);
        $tokensEntree = (int) ($ligne->tokens_entree ?? 0);
        $tokensSortie = (int) ($ligne->tokens_sortie ?? 0);
        $nbQuetes = max(1, (int) Quete::count());

        return [
            'tokens_entree' => $tokensEntree,
            'tokens_sortie' => $tokensSortie,
            'tokens_cache' => (int) ($ligne->tokens_cache ?? 0),
            'appels' => $appels,
            'appels_retries' => (int) ($ligne->appels_retries ?? 0),
            'nb_quetes_mesurees' => (int) Quete::count(),
            'moyenne_par_quete' => [
                'appels' => round($appels / $nbQuetes, 2),
                'tokens_entree' => round($tokensEntree / $nbQuetes, 1),
                'tokens_sortie' => round($tokensSortie / $nbQuetes, 1),
            ],
            'depuis' => $ligne->depuis,
        ];
    }
}
