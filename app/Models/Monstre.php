<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monstre extends Model
{
    protected $table = 'monstres';

    protected $fillable = [
        'nom_base',
        'deplacement',
        'attaque',
        'portee',
        'attaque_distance',
        'defense',
        'pv_body',
        'pv_mind',
        'tier',
        // Boîte d'origine (doc 18) : `null` = aucune, donc compatible avec TOUS
        // les thèmes — c'est le cas de nos propres blocs de stats.
        'boite',
        'cout',
        'grande_taille',
        'capacites',
        'sorts_dread',
        'archetype_lanceur',
        // Lien DÉCLARÉ vers le monstre standard dont ce monstre est la
        // variante à distance (Q6, Against the Ogre Horde p. 8) — nom_base de
        // la cible, jamais null + une convention de nommage. `null` = ce
        // monstre n'est pas une variante.
        'variante_distance_de',
        // MONSTRE À PHASES (chantier 2026-10-04, Ogre Horde p. 6) : nom_base de
        // la phase SUIVANTE, même patron que `variante_distance_de`. `null` =
        // dernière phase (ou monstre ordinaire) — c'est ce qui dit au point de
        // passage unique qu'il doit vraiment mourir plutôt qu'adopter une
        // nouvelle statistique.
        'phase_suivante',
    ];

    protected function casts(): array
    {
        return [
            'capacites' => 'array',
            'sorts_dread' => 'array',
            'grande_taille' => 'array',
        ];
    }

    /** Attaque à distance (ligne de vue) plutôt qu'au contact. */
    public function aDistance(): bool
    {
        return $this->portee === 'distance';
    }

    /** Le monstre porte-t-il la capacité *Ambush* (Dreadshifter) ? */
    public function aCapaciteEmbuscade(): bool
    {
        $capacites = (array) ($this->capacites ?? []);

        return in_array('embuscade', $capacites, true) || array_key_exists('embuscade', $capacites);
    }

    /** Emprise en cases : [largeur, hauteur]. Par défaut 1×1. */
    public function emprise(): array
    {
        $t = $this->grande_taille;

        return [
            'l' => (int) ($t['l'] ?? 1),
            'h' => (int) ($t['h'] ?? 1),
        ];
    }

    public function grandeTaille(): bool
    {
        $e = $this->emprise();

        return $e['l'] > 1 || $e['h'] > 1;
    }

    public function instances(): HasMany
    {
        return $this->hasMany(InstanceMonstre::class, 'monstre_id');
    }

    /** Mind 0 → immunité aux effets mentaux (morts-vivants). */
    public function immuniseMental(): bool
    {
        return $this->pv_mind === 0;
    }

    /** Ce monstre est-il une variante À DISTANCE d'un monstre standard (Q6) ? */
    public function estVarianteDistance(): bool
    {
        return $this->variante_distance_de !== null;
    }

    /**
     * Ce bloc de stats a-t-il une phase SUIVANTE (chantier 2026-10-04) ? Une
     * chaîne de phases (Gruzbella, Spawn of the Pit, Gretzl) se termine par une
     * ligne qui rend `false` ici — c'est elle qui meurt pour de vrai.
     */
    public function aPhaseSuivante(): bool
    {
        return $this->phase_suivante !== null;
    }

    /**
     * Le bloc de stats de la phase suivante, ou `null` si la colonne est vide
     * OU si elle nomme une ligne absente du catalogue (donnée de seeder
     * incohérente — mieux vaut une mort normale qu'une exception en pleine
     * résolution de combat).
     */
    public function monstrePhaseSuivante(): ?self
    {
        return $this->phase_suivante === null
            ? null
            : self::where('nom_base', $this->phase_suivante)->first();
    }
}
