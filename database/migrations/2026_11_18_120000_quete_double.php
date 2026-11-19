<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE — LA QUÊTE DOUBLE (lot K5p, R10 ; *The Frozen Horror* q. 9-10).
 *
 * `quetes.double_id` relie les deux moitiés d'une quête double, posé sur les deux lignes quand la seconde moitié est
 * créée (`App\Partie\Classique\QueteDouble`). `null` pour toute quête existante et toute quête générée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->unsignedBigInteger('double_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('double_id');
        });
    }
};
