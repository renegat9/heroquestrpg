<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Objet extends Model
{
    protected $table = 'objets';

    protected $fillable = [
        'nom',
        'categorie',
        'rarete',
        'prix_base',
        'emplacement',
        // Maîtrise requise pour ÉQUIPER la pièce (doc 01 §7) ; null = aucune.
        'tag_equipement',
        // Lien DÉCLARÉ vers l'arme ordinaire dont cette ligne est la copie en
        // OS (Against the Ogre Horde p. 8) — son `nom` ; null = pas une copie.
        'os_de',
        'effet',
        // Boîte d'extension qui rend seule cet effet UTILE (2026-09-24,
        // `DeckFouille::choisirArtefact()`) ; null = utilisable dans toute
        // campagne, quel que soit son thème.
        'boite',
        // Seule classe à qui la carte refuse la pièce (2026-10-06, Drakehide
        // Cuirass) ; null = aucune exclusion. Liste blanche INVERSÉE d'une
        // seule classe — voir la migration `objets_classe_interdite`.
        'classe_interdite',
    ];

    protected function casts(): array
    {
        return [
            'metallique' => 'boolean',
            'effet' => 'array',
        ];
    }

    /**
     * Cette pièce refuse-t-elle une cible AU CONTACT ? — « cannot be used on an
     * adjacent target » (Arbalète, arcs, Fronde, Dague de jet magique).
     *
     * LE point de passage de la clé `inutilisable_adjacent` : `MenuMoteur` marque
     * les cibles au contact (`mains_nues`, `des_attaque`, `indication`), et
     * `ResolveurTour::frapper()` y fait frapper le héros À MAINS NUES — l'arme
     * ne tire pas au contact, mais ne désarme pas son porteur (René, 2026-10-15).
     * Deux sites, une seule règle, sans quoi le menu annonçait un coup que le
     * résolveur ne résolvait pas autrement. Seul un `lancer` au contact reste
     * refusé (`resoudreAttaque()`).
     *
     * La clé ne vaut que pour une arme qui TIRE : `portee: distance`, ou une
     * arme jetable le temps de son vol (`$lancee`). Une arme qui n'a pas la
     * clé n'est jamais touchée.
     */
    public function interditAuContact(bool $lancee = false): bool
    {
        return ($lancee || ($this->effet['portee'] ?? null) === 'distance')
            && (bool) ($this->effet['inutilisable_adjacent'] ?? false);
    }

    public function lignesInventaire(): HasMany
    {
        return $this->hasMany(Inventaire::class, 'objet_id');
    }
}
