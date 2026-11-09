<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot A5 (`docs/plan-mode-arene.md` §7 bis) : « l'état du salon est en base (camp, statut prêt, monstres
 * choisis), jamais en cache : un téléphone qui se recharge retrouve sa place ». Le Defender composé (ids du catalogue)
 * et le « prêt » de chaque combattant. Lus seulement en arène (`GroupesArene`). Aucune ligne existante ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->json('arene_defender')->nullable();
        });

        Schema::table('groupe_personnages', function (Blueprint $table) {
            $table->boolean('pret_arene')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn('arene_defender');
        });

        Schema::table('groupe_personnages', function (Blueprint $table) {
            $table->dropColumn('pret_arene');
        });
    }
};
