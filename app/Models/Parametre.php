<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglages globaux du serveur (panneau « Réglages » — écran de Narrateur/
 * table) : UNE SEULE ligne en base, jamais scindée (voir {@see self::actuel()}).
 * Colonnes nullable = « suit le défaut .env/config » tant qu'aucune surcharge
 * explicite n'a été enregistrée depuis le panneau.
 */
class Parametre extends Model
{
    protected $table = 'parametres';

    protected $fillable = [
        'llm_provider',
        'modele_anthropic',
        'modele_gemini',
        'rag_actif',
        'voix_dynamique_active',
        'images_actif',
        'narration_voix',
        'rencontres_forts_par_quete',
        'rencontres_forts_escalade_arc',
        'rencontres_seuil_cout_fort',
        'rencontres_boss_pv_adaptatif',
        'rencontres_taille_reference',
    ];

    protected function casts(): array
    {
        return [
            'rag_actif' => 'boolean',
            'voix_dynamique_active' => 'boolean',
            'images_actif' => 'boolean',
            // Eloquent caste `null` en `null` (pas en `false`) : le tri-état
            // (NULL = suit .env) est préservé pour cette surcharge optionnelle.
            'rencontres_boss_pv_adaptatif' => 'boolean',
        ];
    }

    /**
     * Ligne singleton des réglages globaux — créée au premier accès avec ses
     * défauts si elle n'existe pas encore. Tableau de recherche VIDE (pas
     * `['id' => 1]`) : `id` n'est pas fillable, le passer en recherche +
     * création serait silencieusement ignoré par la protection mass-assignment.
     *
     * Les 3 booléens à défaut `true` sont explicitement listés ici plutôt que
     * de compter sur le `->default(true)` de la migration : après un
     * `create()`, Eloquent NE RELIT PAS la ligne insérée (à part son id
     * auto-incrémenté) — un attribut absent du tableau de création reste
     * `null` sur l'instance EN MÉMOIRE tant qu'aucun `fresh()`/`refresh()`
     * n'est fait, même si la colonne vaut bien `1` en base (vérifié
     * empiriquement : `(bool) null` renvoyait `false` pour `images_actif`
     * omis ici alors que la colonne est bien `DEFAULT true`).
     */
    public static function actuel(): self
    {
        return static::query()->firstOrCreate([], [
            'rag_actif' => true,
            'voix_dynamique_active' => true,
            'images_actif' => true,
        ]);
    }
}
