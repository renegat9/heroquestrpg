<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (3.6) — variance « élite » par instance : à l'apparition, une fraction
 * des monstres invoqués reçoit un bonus fixe (+1 attaque, +1 défense, +1 PV Body),
 * en plus du calibrage par budget de rencontre. Le bonus PV est porté par les PV
 * courants de l'instance ; le bonus attaque/défense est appliqué au combat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->boolean('elite')->default(false)->after('etat');
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn('elite');
        });
    }
};
