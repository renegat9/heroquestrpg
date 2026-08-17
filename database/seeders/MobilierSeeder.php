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
 * `fouillable` reflète la colonne « Fouillable » du tableau doc 17 §1, et
 * commande bel et bien la fouille depuis le 2026-08-14. `effet.fouille` porte
 * désormais la TABLE de butin propre à chaque meuble (voir plus bas).
 */
class MobilierSeeder extends Seeder
{
    public function run(): void
    {
        // TABLE DE FOUILLE PROPRE À CHAQUE MEUBLE (`effet.fouille`), décision de
        // René du 2026-08-17 — elle remplace le tirage dans le deck de la quête.
        //
        // Le meuble tirait auparavant une carte du deck de fouille, une seule
        // fois pour tout le groupe : un râtelier d'armes pouvait donc rendre une
        // potion de soin, et un seul héros épuisait la pièce pour tous. Chaque
        // meuble a maintenant SA table, et se fouille UNE FOIS PAR HÉROS — comme
        // une salle.
        //
        // ⚠ Aucun livret ne source ces tables : ils ne disent même pas qu'un
        // meuble bloque le passage (doc 17 §3). C'est un choix de jeu, guidé par
        // ce que la pièce contient PLAUSIBLEMENT — des armes dans un râtelier,
        // des parchemins dans une bibliothèque, des fioles sur un établi.
        //
        // Format d'une entrée : `issue` + `poids` (tirage pondéré), plus les
        // clés que l'issue demande. Les issues sont celles du deck de fouille,
        // pour que `ResolveurTour::appliquerButin()` les applique sans rien
        // savoir de leur provenance :
        //   - `tresor` + `or: [min, max]`
        //   - `objet`  + `categories: [...]` (+ `rarete` facultatif) → l'objet
        //     est tiré du catalogue au moment de la fouille
        //   - `rien`
        //
        // ⚠ CHAQUE table porte une issue `rien`, et un test l'exige : un meuble
        // qui donnerait toujours quelque chose ferait de l'exploration une
        // récolte, et l'or cesserait d'être une ressource.
        $mobiliers = [
            // Habillage de la fouille de SALLE (RB p. 14) : aucune note de quête
            // consultée n'accroche un trésor propre à une table — fouillable = false.
            ['nom' => 'Table', 'nom_anglais' => 'Table', 'largeur' => 2, 'hauteur' => 1, 'fouillable' => false, 'bloque_vue' => false],

            // Le coffre est le contenant à butin par excellence : c'est celui qui
            // paie le plus souvent, et le seul à pouvoir rendre un objet rare.
            ['nom' => 'Coffre', 'nom_anglais' => 'Chest', 'largeur' => 1, 'hauteur' => 1, 'fouillable' => true, 'bloque_vue' => false,
                'effet' => ['fouille' => [
                    ['issue' => 'tresor', 'poids' => 4, 'or' => [25, 60]],
                    ['issue' => 'objet', 'poids' => 2, 'categories' => ['consommable']],
                    ['issue' => 'objet', 'poids' => 1, 'categories' => ['arme', 'armure'], 'rarete' => ['commun', 'peu_commun']],
                    ['issue' => 'rien', 'poids' => 3],
                ]]],

            // Un trône ne se fouille pas, il se dépouille : pierreries du dossier,
            // pièces oubliées sous l'assise. De l'or, ou rien.
            ['nom' => 'Trône', 'nom_anglais' => 'Throne', 'largeur' => 1, 'hauteur' => 1, 'fouillable' => true, 'bloque_vue' => false,
                'effet' => ['fouille' => [
                    ['issue' => 'tresor', 'poids' => 4, 'or' => [35, 75]],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],

            // L'établi de l'alchimiste ne rend QUE des fioles — c'est le meuble
            // le plus spécialisé du lot, et le plus généreux dans sa spécialité.
            ['nom' => 'Établi d\'alchimiste', 'nom_anglais' => 'Alchemist\'s bench', 'largeur' => 1, 'hauteur' => 2, 'fouillable' => true, 'bloque_vue' => false,
                'effet' => ['fouille' => [
                    ['issue' => 'objet', 'poids' => 5, 'categories' => ['consommable']],
                    ['issue' => 'rien', 'poids' => 3],
                ]]],

            // Le mobilier funéraire : de l'or déposé avec le mort, parfois une
            // arme de sa main, souvent la poussière seule.
            ['nom' => 'Tombeau', 'nom_anglais' => 'Tomb', 'largeur' => 1, 'hauteur' => 2, 'fouillable' => true, 'bloque_vue' => false,
                'effet' => ['fouille' => [
                    ['issue' => 'tresor', 'poids' => 3, 'or' => [30, 70]],
                    ['issue' => 'objet', 'poids' => 2, 'categories' => ['arme', 'armure'], 'rarete' => ['commun', 'peu_commun']],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],

            // Une bibliothèque contient des ÉCRITS : c'est le seul meuble à
            // rendre des parchemins, ce qui en fait la pièce du lanceur de sorts.
            ['nom' => 'Bibliothèque', 'nom_anglais' => 'Bookcase', 'largeur' => 2, 'hauteur' => 1, 'fouillable' => true, 'bloque_vue' => true,
                'effet' => ['fouille' => [
                    ['issue' => 'objet', 'poids' => 3, 'categories' => ['parchemin']],
                    ['issue' => 'tresor', 'poids' => 1, 'or' => [10, 25]],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],

            // Le râtelier d'armes : armes et armures, l'exemple donné par René.
            // Aucune chance d'or — on n'y range pas sa bourse.
            ['nom' => 'Râtelier d\'armes', 'nom_anglais' => 'Weapons rack', 'largeur' => 1, 'hauteur' => 2, 'fouillable' => true, 'bloque_vue' => true,
                'effet' => ['fouille' => [
                    ['issue' => 'objet', 'poids' => 4, 'categories' => ['arme', 'armure'], 'rarete' => ['commun', 'peu_commun']],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],

            // L'armoire est le meuble à tout faire : un peu de tout, souvent rien.
            ['nom' => 'Armoire', 'nom_anglais' => 'Cupboard', 'largeur' => 2, 'hauteur' => 1, 'fouillable' => true, 'bloque_vue' => true,
                'effet' => ['fouille' => [
                    ['issue' => 'objet', 'poids' => 2, 'categories' => ['consommable']],
                    ['issue' => 'objet', 'poids' => 2, 'categories' => ['outil']],
                    ['issue' => 'tresor', 'poids' => 2, 'or' => [15, 40]],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],
        ];

        // Purge puis recréation, comme TuileSeeder (même commentaire : données de
        // référence re-semables, aucune clé étrangère ne pointe vers `mobiliers` —
        // `cartes.grille.mobilier` est un instantané de la carte assemblée).
        Mobilier::query()->delete();

        foreach ($mobiliers as $mobilier) {
            Mobilier::create([...$mobilier, 'bloque_mouvement' => true]);
        }
    }
}
