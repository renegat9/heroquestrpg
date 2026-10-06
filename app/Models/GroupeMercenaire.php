<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Allié recruté et actif dans une quête (instance, doc 14 §3.5). Consommé en
 * fin de quête.
 *
 * ⚠ Depuis le 2026-10-04 (chantier 3a, décision de René : « un allié est
 * TOUJOURS joué par son joueur »), il n'est plus joué par le moteur en phase
 * dédiée : il joue dans le tour du héros qui le contrôle
 * (`recruteur_personnage_id`), juste après lui, depuis SA manette
 * (`App\Partie\OrdreDuTour::acteurActif()`, `ResolveurTour::resoudreTourAllie()`).
 * `a_deplace`/`a_agi`/`a_joue` sont ses deux créneaux de tour — le pendant de
 * `etat_personnage_quete` pour un héros, remis à zéro chaque round par
 * `ResolveurTour::ouvrirNouveauTour()`.
 *
 * `etat` porte aussi `'captif'` depuis le chantier 3b (mission « secourir ») :
 * posé sur la carte à l'assemblage, ni joué ni contrôlé, jusqu'à ce qu'un
 * héros au contact le LIBÈRE (`resoudreLibererCaptif()`). Ce qu'il devient
 * alors dépend de `mercenaire.mode_captif` (chantier « captifs-jetons »,
 * 2026-10-05) :
 *  - `figurine` (Gothar) : `etat` passe à `'actif'` — un allié ordinaire,
 *    contrôlé par ce héros, rejoué comme n'importe quel autre (chantier 3a).
 *  - `escorte` (le Prospecteur, la Princesse Millandriel) : `etat` passe à
 *    `'porte'` — PORTÉ par le héros libérateur (`recruteur_personnage_id`),
 *    jamais une figurine : aucun tour, aucune cible pour les monstres,
 *    `position_x`/`position_y` ne bougent plus JAMAIS (ils restent la case
 *    d'ORIGINE). Si le porteur tombe, il est REPRIS — `etat` revient à
 *    `'captif'` sur cette même case ({@see
 *    \App\Partie\ResolveurTour::reprendreCaptifsPortes()}). `'porte'` est
 *    délibérément exclu de tout ce qui filtre `etat: 'actif'` (figure sur la
 *    carte, ciblage des monstres, ordre du tour) ou `etat: 'captif'`
 *    (occupation de la case, option « libérer ») : aucun garde-fou de plus à
 *    écrire, l'exclusion est déjà la valeur de la colonne.
 */
class GroupeMercenaire extends Model
{
    protected $table = 'groupe_mercenaires';

    protected $fillable = [
        'groupe_id',
        'mercenaire_id',
        'recruteur_personnage_id',
        'pv_body',
        'position_x',
        'position_y',
        'etat',
        'a_deplace',
        'a_agi',
        'a_joue',
    ];

    protected function casts(): array
    {
        return [
            'a_deplace' => 'boolean',
            'a_agi' => 'boolean',
            'a_joue' => 'boolean',
        ];
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupe_id');
    }

    /** Bloc de stats du catalogue. */
    public function mercenaire(): BelongsTo
    {
        return $this->belongsTo(Mercenaire::class, 'mercenaire_id');
    }

    public function recruteur(): BelongsTo
    {
        return $this->belongsTo(Personnage::class, 'recruteur_personnage_id');
    }
}
