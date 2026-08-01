<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sort l'avancement d'exploration du CACHE et le met en base (verdict §2.16).
 *
 * `partie:salles:{quete}` et `partie:tresor:{quete}` vivaient uniquement dans
 * le cache, avec un TTL de 6 h. Leur perte refermait le brouillard de guerre
 * sur des zones déjà explorées : le BFS de la manette n'acceptant que les
 * cases connues, plus AUCUNE case n'était accessible et tout le groupe se
 * retrouvait figé sur place, sans recours et sans explication.
 *
 * C'est de l'état de partie durable, au même titre que la position des héros :
 * sa place est ici, et dans les snapshots de reprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('salles_decouvertes')->nullable()->after('branche_active');
            $table->json('tresors_fouilles')->nullable()->after('salles_decouvertes');
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn(['salles_decouvertes', 'tresors_fouilles']);
        });
    }
};
