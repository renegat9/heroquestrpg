<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * *Toucher du Brasier* (Moine, Style du Feu) — « inflict 1 Body Point of damage
 * to any one adjacent enemy. The target takes an additional **2 Body Points of
 * damage at the end of its next turn**. »
 *
 * Le premier dégât DIFFÉRÉ posé sur un monstre. Même famille que le jeton de
 * Rejeton porté par les héros, à ceci près qu'il EXPIRE : il tombe une fois,
 * à la fin du tour suivant de la créature, et disparaît. La colonne porte les
 * points en attente, `null` quand la braise est éteinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->unsignedTinyInteger('degat_differe')->nullable()->after('brule');
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn('degat_differe');
        });
    }
};
