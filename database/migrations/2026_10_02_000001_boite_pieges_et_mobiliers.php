<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pieges.boite` / `mobiliers.boite` — même colonne, même sémantique que
 * `terrains.boite` (migration `2026_09_06_000002_ajouter_boite_aux_terrains`)
 * et `monstres.boite` : `null` veut dire « aucune boîte, convient à TOUS les
 * thèmes », une valeur la réserve au thème de bestiaire correspondant.
 *
 * Lot B de *Against the Ogre Horde* (plan `docs/plan-ogre-horde.md` §3) pose
 * quatre éléments de carte propres à la boîte — la porte de pierre (un ÉTAT
 * de porte, donc aucune colonne neuve côté `pieges`/`mobiliers`), la lame
 * balançoire et la fosse des ténèbres (`pieges`), la caisse de ravitaillement
 * (`mobiliers`). Sans cette colonne, ces quatre entrées seraient posées sur
 * N'IMPORTE QUELLE carte, quel que soit le thème choisi par le groupe —
 * exactement le défaut que `terrains.boite` corrigeait pour *The Frozen
 * Horror*. `AssembleurCarte` lit cette colonne au même point de passage que
 * `Terrain::boite` (`$bestiaire?->contient($x->boite)`), jamais une seconde
 * fois ailleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pieges', function (Blueprint $table) {
            $table->string('boite', 40)->nullable()->after('effet');
        });

        Schema::table('mobiliers', function (Blueprint $table) {
            $table->string('boite', 40)->nullable()->after('effet');
        });
    }

    public function down(): void
    {
        Schema::table('pieges', function (Blueprint $table) {
            $table->dropColumn('boite');
        });

        Schema::table('mobiliers', function (Blueprint $table) {
            $table->dropColumn('boite');
        });
    }
};
