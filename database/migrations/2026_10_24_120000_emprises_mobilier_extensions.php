<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emprises des meubles d'extension MESURÉES sur les cartes des livrets (René, 2026-10-24 : « assure-toi que tous les
 * meubles sont corrects, surtout leur affichage sur la carte » — « je parle dans les livrets »). Quatre étaient posés en
 * 1×1 par repli, sans mesure :
 *
 *   - Forge des nains (Kellar's Keep, F4543 p. 15) : la salle D dessine la forge avec la pièce de la CHEMINÉE, 1 case
 *     sur 3, dressée contre le mur → 3×1, adossée, bloque la vue (la convention de la Cheminée) ;
 *   - Fournaise (Spirit Queen's Torment, G0053 p. 31) : « The furniture in each of these rooms represents a large
 *     furnace » — de grands meubles (cheminée, tombeau, établis), jamais une case seule → la même emprise que la forge ;
 *   - Haut Autel (Wizards of Morcar, G1504 p. 4 et p. 39) : la tuile fait deux cases, l'autel est dessiné sur 2 cases
 *     contre le mur de la salle E → 2×1, adossé ;
 *   - Amas de cristal (Jungles of Delthrak, F9907 p. 13, quête 1, salle B) : le cristal sur son socle est dessiné sur
 *     2×2 cases, comme la Mare seule de la même carte → 2×2.
 *
 * Les quatre autres meubles d'extension ont été vérifiés justes (Table des brasseurs 3×2 = l'établi d'alchimiste ;
 * Caisse de ravitaillement, Coffre du Dread = des coffres d'une case ; Cocon = une tuile d'une case).
 *
 * Changer une ligne EXISTANTE du catalogue se fait par migration (CLAUDE.md), sur le patron de
 * `2026_10_16_130000_mobilier_trois_cases`. ⚠ AUCUNE carte déjà assemblée n'est touchée : chaque entrée de
 * `cartes.grille.mobilier[]` porte SON emprise, figée à la pose.
 */
return new class extends Migration
{
    /** nom => [avant, après] en [largeur, hauteur, bloque_vue, adosse_au_mur]. */
    private const EMPRISES = [
        'Forge des nains' => [[1, 1, false, false], [3, 1, true, true]],
        'Fournaise' => [[1, 1, false, false], [3, 1, true, true]],
        'Haut Autel' => [[1, 1, false, false], [2, 1, false, true]],
        'Amas de cristal' => [[1, 1, true, false], [2, 2, true, false]],
    ];

    public function up(): void
    {
        $this->poser(1);
    }

    public function down(): void
    {
        $this->poser(0);
    }

    private function poser(int $indice): void
    {
        foreach (self::EMPRISES as $nom => $valeurs) {
            [$largeur, $hauteur, $bloqueVue, $adosse] = $valeurs[$indice];

            DB::table('mobiliers')->where('nom', $nom)->update([
                'largeur' => $largeur,
                'hauteur' => $hauteur,
                'bloque_vue' => $bloqueVue,
                'adosse_au_mur' => $adosse,
            ]);
        }
    }
};
