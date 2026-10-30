<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOL MEUBLE (Spirit Queen's Torment, quête 10 « The Tower of Earth », livret G0053 p. 13 note B ; lot H des restes, 2026-10-29).
 *
 * « if the result of the movement dice is odd, they become stuck in the first square of their movement and take 1 Body Point
 * of damage. Once stuck, a hero may only roll 1 die for movement, and they only move if the result of the die is an even
 * number. » — un ÉTAT DURABLE : le héros enlisé le reste d'un tour à l'autre, tant qu'il ne tire pas un dé pair. Même patron
 * que `enlise` (Sables mouvants, 2026-10-15) : une colonne, jamais le cache (règle consolidée du projet).
 *
 * Un booléen, pas un JSON : le sol meuble n'a ni phase de défausse ni case de sortie à mémoriser — il suffit de savoir qu'on
 * est enlisé, le dé du tour dit le reste.
 *
 * ⚠ Migration ADDITIVE : une colonne avec défaut `false`. Aucun héros n'est enlisé à la pose de la migration, et les
 * snapshots antérieurs se lisent avec `?? false` (`Sauvegarde`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('enlise_sol')->default(false)->after('enlise');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('enlise_sol');
        });
    }
};
