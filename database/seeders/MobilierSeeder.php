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
 * `bloque_mouvement` = true partout (inchangé) : voir le commentaire de la
 * migration `create_mobiliers_table` pour la convention retenue (aucun
 * livret ne dit noir sur blanc qu'un héros ne peut pas se tenir sur la case
 * d'un meuble — doc 17 §3 — c'est un choix de portage).
 *
 * `bloque_vue` : ⚠ DÉCISION DE PORTAGE, pas une donnée sourcée. Aucun des
 * deux livrets officiels ne traite JAMAIS de la ligne de vue du mobilier —
 * doc 17 §3 a déjà établi qu'ils ne disent même pas qu'un meuble bloque le
 * PASSAGE, alors la vue... Le critère retenu ici est la hauteur physique de
 * la pièce : un meuble HAUT (à hauteur d'yeux ou plus) coupe la vue comme un
 * mur ; un meuble BAS (hauteur de table) laisse voir par-dessus.
 *   - true  : Bibliothèque, Râtelier d'armes, Armoire — mobilier vertical,
 *     dressé contre un mur, qui dépasse largement la taille d'un héros.
 *   - false : Table, Coffre, Trône, Établi d'alchimiste, Tombeau — mobilier
 *     bas, à hauteur de ceinture ou moins.
 * Ne pas prétendre que cette répartition est sourcée : c'est une convention
 * de jeu, au même titre que `bloque_mouvement`.
 *
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
            ['nom' => 'Table', 'nom_anglais' => 'Table', 'largeur' => 2, 'hauteur' => 1, 'fouillable' => false, 'bloque_vue' => false],
            ['nom' => 'Coffre', 'nom_anglais' => 'Chest', 'largeur' => 1, 'hauteur' => 1, 'fouillable' => true, 'bloque_vue' => false],
            ['nom' => 'Trône', 'nom_anglais' => 'Throne', 'largeur' => 1, 'hauteur' => 1, 'fouillable' => true, 'bloque_vue' => false],
            ['nom' => 'Établi d\'alchimiste', 'nom_anglais' => 'Alchemist\'s bench', 'largeur' => 1, 'hauteur' => 2, 'fouillable' => true, 'bloque_vue' => false],
            ['nom' => 'Tombeau', 'nom_anglais' => 'Tomb', 'largeur' => 1, 'hauteur' => 2, 'fouillable' => true, 'bloque_vue' => false],
            ['nom' => 'Bibliothèque', 'nom_anglais' => 'Bookcase', 'largeur' => 2, 'hauteur' => 1, 'fouillable' => true, 'bloque_vue' => true],
            ['nom' => 'Râtelier d\'armes', 'nom_anglais' => 'Weapons rack', 'largeur' => 1, 'hauteur' => 2, 'fouillable' => true, 'bloque_vue' => true],
            ['nom' => 'Armoire', 'nom_anglais' => 'Cupboard', 'largeur' => 2, 'hauteur' => 1, 'fouillable' => true, 'bloque_vue' => true],
        ];

        // Purge puis recréation, comme TuileSeeder (même commentaire : données de
        // référence re-semables, aucune clé étrangère ne pointe vers `mobiliers` —
        // `cartes.grille.mobilier` est un instantané de la carte assemblée).
        Mobilier::query()->delete();

        foreach ($mobiliers as $mobilier) {
            Mobilier::create([...$mobilier, 'bloque_mouvement' => true, 'effet' => null]);
        }
    }
}
