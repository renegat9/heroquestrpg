<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalogue de référence des ÉPREUVES (ancrages posés sur la carte, testés au
 * contact) — données seedées, jamais modifiées en jeu, même statut que
 * `Mobilier`/`Piege`/`Tuile`.
 *
 * Une épreuve existe pour donner enfin un producteur aux bonus d'attribut des
 * talents : `bonus_attribut_body`/`bonus_attribut_mind` n'avaient jusqu'ici
 * aucun jet à modifier hors combat, et les contextes `savoir`/`social_peur`
 * de `App\Engine\MotsClesTalent::CONTEXTES` ne déclenchaient RIEN — six
 * talents dormaient dans la grille sans jamais s'appliquer.
 *
 * `attribut` fixe une fois pour toutes le jet tenté (Body ou Mind) — une
 * épreuve ne bascule pas selon le héros qui s'y présente, contrairement à un
 * jet de compétence libre. `difficulte` est un SEUIL DE SUCCÈS (1 à 4), pas
 * un nombre de dés, sur l'échelle déjà en place pour `sorts.difficulte_parchemin`
 * et `JetCompetence::resoudre()`.
 *
 * `contexte` est nullable et recoupe `MotsClesTalent::CONTEXTES` : c'est lui
 * qui fait qu'un talent « +1 dé de Mind sur les jets de savoir » a enfin un
 * jet de savoir à bonifier.
 *
 * `exige_placement` est une PRÉCONDITION DE POSE (où l'épreuve a le droit
 * d'exister), jamais un effet (ce qu'elle fait) — voir le commentaire de la
 * migration `create_epreuves_table` et `MotsClesEpreuve::PLACEMENTS`.
 */
class Epreuve extends Model
{
    protected $table = 'epreuves';

    protected $fillable = [
        'nom',
        'description',
        'attribut',
        'difficulte',
        'contexte',
        'exige_placement',
        'effet',
    ];

    protected function casts(): array
    {
        return [
            'difficulte' => 'integer',
            'effet' => 'array',
        ];
    }
}
