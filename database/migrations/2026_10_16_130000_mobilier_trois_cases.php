<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les emprises du mobilier, décidées par René le 2026-10-16 sur des dessins mesurés à
 * l'échelle ≈ 0,9 (la bibliothèque, mesurée 2,7 cases, en fait 3) :
 *
 *   - 1×3 (3 cases) : bibliothèque, armoire, râtelier d'armes (« 3-Space Furniture (1x3
 *     Grid) ») ;
 *   - 3×2 (6 cases) : table, tombeau (couché, comme dessiné), établi d'alchimiste. Cette
 *     décision REMPLACE le 1×3 que l'établi avait reçu la veille.
 *
 * La cheminée (3×1) n'est PAS ici : elle entre au catalogue par `MobilierSeeder`, qui est
 * le seul à créer une ligne nouvelle.
 *
 * Changer une ligne EXISTANTE du catalogue se fait par migration dans ce projet
 * (CLAUDE.md) : `MobilierSeeder` pose les mêmes valeurs (`updateOrCreate`), mais un
 * `migrate` seul doit suffire sur la base de production, sans rejouer le catalogue.
 * Cette migration n'avait pas encore été jouée quand ces emprises ont été décidées : elle
 * porte donc l'ensemble des changements, et son `down()` rend les emprises d'origine.
 *
 * ⚠ AUCUNE carte déjà assemblée n'est touchée. Chaque entrée de
 * `cartes.grille.mobilier[]` porte SON emprise `l`×`h`, figée à la pose
 * (`AssembleurCarte::placerMobilier()`), et `FabriqueGrille::pour()` ne relit jamais la
 * taille du catalogue : une quête en cours garde ses meubles à leur ancienne emprise.
 * Seule la PROCHAINE carte assemblée pose les nouvelles. `MobilierTroisCasesTest` pose ce
 * fait en test.
 *
 * La table du sorcier (3 cases, décidée) n'est PAS au catalogue : ses drapeaux de vue et sa
 * table de fouille ne sont sourcés nulle part, et une ligne qui les inventerait serait le
 * défaut que ce projet traque (`reference/17_mobilier.md` §1).
 */
return new class extends Migration
{
    /** nom du meuble => [avant, après] en `[largeur, hauteur]`. */
    private const TAILLES = [
        'Bibliothèque' => [[2, 1], [3, 1]],
        'Armoire' => [[2, 1], [3, 1]],
        'Râtelier d\'armes' => [[1, 2], [1, 3]],
        'Table' => [[2, 1], [3, 2]],
        'Tombeau' => [[1, 2], [3, 2]],
        'Établi d\'alchimiste' => [[1, 2], [3, 2]],
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
        foreach (self::TAILLES as $nom => $tailles) {
            [$largeur, $hauteur] = $tailles[$indice];

            DB::table('mobiliers')->where('nom', $nom)->update([
                'largeur' => $largeur,
                'hauteur' => $hauteur,
            ]);
        }
    }
};
