<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mobiliers.bloquant` confondait deux notions distinctes : bloquer le
 * MOUVEMENT et bloquer la VUE. Un meuble `bloquant` était ajouté aux cases
 * OCCUPÉES de `Grille` — or une case occupée bloque `estTraversable()` ET
 * coupe `ligneDeVue()` dès que l'appelant passe `figuresBloquent: true`, ce
 * que fait `MenuMoteur` pour toute arme à distance. Conséquence : une simple
 * table arrêtait les flèches. Une table bloque le passage mais on voit
 * par-dessus ; une bibliothèque bloque les deux.
 *
 * Renommage propre plutôt qu'un alias : la table `mobiliers` date d'hier
 * (migration `2026_08_04_000002_create_mobiliers_table`), aucune donnée de
 * partie n'en dépend encore.
 *
 * - `bloquant` → `bloque_mouvement` (même sémantique qu'avant : infranchissable,
 *   lu par `FabriqueGrille::pour()` pour peupler les cases occupées).
 * - `bloque_vue` (nouveau, défaut false) : coupe `ligneDeVue()` INCONDITIONNELLEMENT,
 *   comme un mur — pas conditionné à `figuresBloquent`, qui ne concerne que les
 *   FIGURES interposées (héros, monstres). Un meuble est du décor, pas une figure.
 *   Voir `Grille::occulter()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobiliers', function (Blueprint $table) {
            $table->renameColumn('bloquant', 'bloque_mouvement');
        });

        Schema::table('mobiliers', function (Blueprint $table) {
            $table->boolean('bloque_vue')->default(false)->after('bloque_mouvement');
        });
    }

    public function down(): void
    {
        Schema::table('mobiliers', function (Blueprint $table) {
            $table->dropColumn('bloque_vue');
        });

        Schema::table('mobiliers', function (Blueprint $table) {
            $table->renameColumn('bloque_mouvement', 'bloquant');
        });
    }
};
