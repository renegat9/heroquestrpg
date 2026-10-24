<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TERRAINS À PLUSIEURS CASES (Jungles of Delthrak, René 2026-10-24 : « ajuste pour pouvoir mettre des terrains à
 * plusieurs cases »). Deux manières de poser une même entrée de catalogue, lues par `AssembleurCarte::placerTerrains()` :
 *
 *   - UN BLOC FIXE (`largeur` × `hauteur`) : la Mare et le Brasier, 2×2 — le socle « Basin » du livret (F9907 p. 4-5 et
 *     symboles p. 51) ; le précédent est `mobiliers.largeur/hauteur` (migration `2026_10_16_130000_mobilier_trois_cases`) ;
 *   - UN MOTIF tiré au hasard (`motif`) : la Jungle, la Toile et le Sable entravants, en plaques (1 case, 2 ou 3 en ligne,
 *     carré 2×2, L de 3, bande de 4 — une liste FERMÉE relevée sur le livret, `App\Engine\FormesTerrain`).
 *
 * Le stockage reste PAR CASE dans `cartes.grille['terrain']` : l'unique boucle `FabriqueGrille::pour()` et le Dijkstra
 * pondéré lisent des cases. Les cases d'une même pose partagent un `groupe_id` (le précédent : `paire_id`).
 *
 * ⚠ Migration ADDITIVE : trois colonnes avec des défauts (1, 1, null) — une case isolée, comme avant. Les lignes EXISTANTES
 * changent de forme, et ce changement se fait par migration (CLAUDE.md : `db:seed` ne doit pas être le seul moyen de les
 * mettre à jour). `TerrainSeeder` pose les mêmes valeurs (`updateOrCreate`).
 *
 * ⚠ AUCUNE carte déjà assemblée n'est touchée : une entrée de `cartes.grille['terrain']` sans `groupe_id` se lit exactement
 * comme avant (une case, un coût, un effet). Seule la PROCHAINE carte assemblée pose des plaques.
 */
return new class extends Migration
{
    /** nom => [avant, après] en [largeur, hauteur, motif]. */
    private const FORMES = [
        'Mare' => [[1, 1, null], [2, 2, null]],
        'Brasier' => [[1, 1, null], [2, 2, null]],
        'Sable entravant' => [[1, 1, null], [1, 1, 'plaque']],
        'Toile entravante' => [[1, 1, null], [1, 1, 'plaque']],
        'Jungle entravante' => [[1, 1, null], [1, 1, 'plaque']],
    ];

    public function up(): void
    {
        Schema::table('terrains', function (Blueprint $table) {
            $table->unsignedTinyInteger('largeur')->default(1)->after('cout_deplacement');
            $table->unsignedTinyInteger('hauteur')->default(1)->after('largeur');
            $table->string('motif', 40)->nullable()->after('hauteur');
        });

        $this->poser(1);
    }

    public function down(): void
    {
        $this->poser(0);

        Schema::table('terrains', function (Blueprint $table) {
            $table->dropColumn(['largeur', 'hauteur', 'motif']);
        });
    }

    private function poser(int $indice): void
    {
        foreach (self::FORMES as $nom => $valeurs) {
            [$largeur, $hauteur, $motif] = $valeurs[$indice];

            DB::table('terrains')->where('nom', $nom)->update([
                'largeur' => $largeur,
                'hauteur' => $hauteur,
                'motif' => $motif,
            ]);
        }
    }
};
