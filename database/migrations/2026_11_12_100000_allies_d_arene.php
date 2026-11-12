<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot A4 (livret F9528 p. 12 : « Heroes and allies make up the Challenger team ») : l'allié que chaque
 * combattant engage au salon, un bloc du catalogue `mercenaires`. État durable du salon : une colonne du pivot, jamais
 * du cache. Lu seulement en arène (`GroupesArene`). Aucune ligne existante ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupe_personnages', function (Blueprint $table) {
            $table->foreignId('allie_arene_id')->nullable()->constrained('mercenaires')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groupe_personnages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allie_arene_id');
        });
    }
};
