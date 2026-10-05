<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Désigne LE captif de cette quête (gabarit `objectif: 'secourir'`) — état
 * durable en DB, jamais en cache (CLAUDE.md « consolidated rule ») : c'est ce
 * qui permet à `Quete::objectifAccompli()`/`captifEstMortOuCapture()` de
 * savoir QUELLE ligne de `groupe_mercenaires` est l'objectif plutôt qu'un
 * mercenaire recruté comme un autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->foreignId('captif_mercenaire_id')->nullable()
                ->constrained('groupe_mercenaires')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('captif_mercenaire_id');
        });
    }
};
