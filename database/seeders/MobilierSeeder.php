<?php

namespace Database\Seeders;

use App\Models\Mobilier;
use Illuminate\Database\Seeder;

/**
 * Les 8 types de mobilier dont l'emprise a été MESURÉE (comptage direct de
 * cases sur les cartes de quête imprimées, doc 17 §1) — pas les 3 marqués
 * « ⚠ non établi par le livret » (table du sorcier, portant, cheminée), qui
 * n'ont aucune mesure indépendante et resteraient une invention si on les
 * codait.
 *
 * `bloquant` = true partout : voir le commentaire de la migration
 * `create_mobiliers_table` pour la convention retenue.
 * `fouillable` reflète la colonne « Fouillable » du tableau doc 17 §1, mais
 * ne branche RIEN côté moteur — voir le commentaire du modèle `Mobilier`.
 */
class MobilierSeeder extends Seeder
{
    public function run(): void
    {
        $mobiliers = [
            // Habillage de la fouille de SALLE (RB p. 14) : aucune note de quête
            // consultée n'accroche un trésor propre à une table — fouillable = false.
            ['nom' => 'Table', 'nom_anglais' => 'Table', 'largeur' => 2, 'hauteur' => 1, 'fouillable' => false],
            ['nom' => 'Coffre', 'nom_anglais' => 'Chest', 'largeur' => 1, 'hauteur' => 1, 'fouillable' => true],
            ['nom' => 'Trône', 'nom_anglais' => 'Throne', 'largeur' => 1, 'hauteur' => 1, 'fouillable' => true],
            ['nom' => 'Établi d\'alchimiste', 'nom_anglais' => 'Alchemist\'s bench', 'largeur' => 1, 'hauteur' => 2, 'fouillable' => true],
            ['nom' => 'Tombeau', 'nom_anglais' => 'Tomb', 'largeur' => 1, 'hauteur' => 2, 'fouillable' => true],
            ['nom' => 'Bibliothèque', 'nom_anglais' => 'Bookcase', 'largeur' => 2, 'hauteur' => 1, 'fouillable' => true],
            ['nom' => 'Râtelier d\'armes', 'nom_anglais' => 'Weapons rack', 'largeur' => 1, 'hauteur' => 2, 'fouillable' => true],
            ['nom' => 'Armoire', 'nom_anglais' => 'Cupboard', 'largeur' => 2, 'hauteur' => 1, 'fouillable' => true],
        ];

        // Purge puis recréation, comme TuileSeeder (même commentaire : données de
        // référence re-semables, aucune clé étrangère ne pointe vers `mobiliers` —
        // `cartes.grille.mobilier` est un instantané de la carte assemblée).
        Mobilier::query()->delete();

        foreach ($mobiliers as $mobilier) {
            Mobilier::create([...$mobilier, 'bloquant' => true, 'effet' => null]);
        }
    }
}
