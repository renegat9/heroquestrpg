<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot A9c — le COMBAT Monster vs Monster : le camp de chaque figure (`a` | `b`, `null` hors de ce mode) et
 * les deux créneaux de son activation (déplacement, frappe), joués par un joueur depuis sa manette. État durable du
 * round, en base. Aucune ligne existante ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->string('camp_arene', 1)->nullable();
            $table->boolean('mvm_deplace')->default(false);
            $table->boolean('mvm_agi')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn(['camp_arene', 'mvm_deplace', 'mvm_agi']);
        });
    }
};
