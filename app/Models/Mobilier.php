<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalogue de référence du mobilier de salle (doc 17) — 8 types dont
 * l'emprise a été mesurée sur les cartes de quête officielles. Données
 * seedées, jamais modifiées en jeu (même statut que Piege/Tuile).
 *
 * `bloque_mouvement` et `bloque_vue` sont DEUX propriétés indépendantes
 * (migration `separer_bloque_mouvement_bloque_vue_mobilier`) : une table
 * bloque le passage mais on voit par-dessus, une bibliothèque bloque les
 * deux. Ne JAMAIS les refusionner en un seul drapeau — c'est exactement le
 * bug corrigé par cette séparation (une case occupée coupait aussi la ligne
 * de vue de toute arme à distance, cf. `FabriqueGrille`/`Grille::occulter()`).
 *
 * `fouillable` commande la fouille depuis le 2026-08-14 (`MoteurMobilier`), et
 * chaque pièce porte SA table de butin dans `effet.fouille`.
 *
 * `difficulte_destruction` (2026-08-24) ouvre le deuxième emploi de
 * `attribut_body` : fracasser l'obstacle. ⚠ `null` veut dire INDESTRUCTIBLE et
 * non « pas encore renseigné » — le tombeau est un sarcophage de pierre. Une
 * pièce FOUILLABLE détruite rend une dernière fouille à son destructeur, même
 * si tout le groupe l'avait déjà vidée : c'est le troc, on ouvre le passage et
 * on rafle le fond, mais plus personne ne la fouillera.
 *
 * `pv_body` / `defense_dice` (2026-10-04) ouvrent la TROISIÈME voie : on
 * épuise le meuble AU COMBAT, comme un monstre (Crystal Cluster de *Jungles of
 * Delthrak*, Haut Autel et Coffres du Dread de *Wizards of Morcar*) — pas de
 * tentative limitée par héros, on le retente sans limite, un coup qui ne
 * suffit pas laisse le meuble entamé. ⚠ `null` = INDESTRUCTIBLE par cette
 * voie, pas « non renseigné » ; `defense_dice` à `0` est une vraie valeur (le
 * Crystal Cluster « cannot defend »), distincte de `null`. Les PV COURANTS
 * vivent dans `cartes.grille.mobilier[i].pv_restants` (jamais une colonne ici
 * — c'est un état de PARTIE, le catalogue reste une donnée de référence).
 */
class Mobilier extends Model
{
    protected $table = 'mobiliers';

    protected $fillable = [
        'nom',
        'nom_anglais',
        'largeur',
        'hauteur',
        'bloque_mouvement',
        'bloque_vue',
        'adosse_au_mur',
        'fouillable',
        // Difficulté du jet de Body pour mettre la pièce en pièces (2026-08-24).
        // ⚠ `null` = INDESTRUCTIBLE, pas « non renseigné » : le tombeau est un
        // sarcophage de pierre. Et c'est la difficulté BRUTE — le plafond
        // (`App\Partie\DifficulteBody`) s'applique à la génération du menu.
        'difficulte_destruction',
        // PV de Body et dés de défense pour la détruire AU COMBAT (2026-10-04),
        // comme un monstre — voir le docblock de la classe. `null` = pas de
        // destruction par cette voie.
        'pv_body',
        'defense_dice',
        'effet',
        // `null` = toutes les boîtes (migration `boite_pieges_et_mobiliers`,
        // même convention que `Terrain::boite`) ; une valeur réserve la
        // pièce au thème de bestiaire correspondant (ex. `horde_ogre`).
        'boite',
    ];

    protected function casts(): array
    {
        return [
            'bloque_mouvement' => 'boolean',
            'adosse_au_mur' => 'boolean',
            'bloque_vue' => 'boolean',
            'fouillable' => 'boolean',
            'difficulte_destruction' => 'integer',
            'pv_body' => 'integer',
            'defense_dice' => 'integer',
            'effet' => 'array',
        ];
    }
}
