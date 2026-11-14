<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une campagne construite à la main (MODE CLASSIQUE, lot K1, `docs/plan-mode-classique.md` §3). `document` est la
 * source complète, validée par `App\Partie\Classique\ValidationCampagne` ; `empreinte` = sha256 du document canonisé.
 * Ne jamais écrire ces colonnes directement : tout passe par `App\Partie\Classique\CampagnesConstruites`.
 */
class CampagneConstruite extends Model
{
    protected $table = 'campagnes_construites';

    protected $fillable = [
        'slug',
        'titre',
        'auteur',
        'mention',
        'document',
        'version_format',
        'empreinte',
    ];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'version_format' => 'integer',
        ];
    }

    /** Les quêtes, dans l'ordre de jeu (projection de `document.quetes`). */
    public function quetes(): HasMany
    {
        return $this->hasMany(QueteConstruite::class, 'campagne_construite_id')->orderBy('rang');
    }
}
