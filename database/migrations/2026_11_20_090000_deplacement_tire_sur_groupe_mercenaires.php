<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le DÉ DE DÉPLACEMENT d'un allié qui ne marche pas à vitesse fixe (René, 2026-11-20 — Sir Ragnar, livret de base q. 2 :
 * « rolls 1 red die to move the wounded knight »). Tiré une fois par round, à la première question, et gardé EN COLONNE
 * (un état durable, jamais en cache) jusqu'au round suivant : `GroupeMercenaire::vitesse()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->unsignedTinyInteger('deplacement_tire')->nullable()->after('a_joue');
        });
    }

    public function down(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->dropColumn('deplacement_tire');
        });
    }
};
