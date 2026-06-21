<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jeu LAN entre amis : la connexion d'un joueur se fait par NOM seul (pas de
 * mot de passe). Le hash devient donc optionnel (conservé pour compat ; les
 * anciens comptes gardent le leur, ignoré à la connexion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('joueurs', function (Blueprint $table) {
            $table->string('mot_de_passe')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('joueurs', function (Blueprint $table) {
            $table->string('mot_de_passe')->nullable(false)->change();
        });
    }
};
