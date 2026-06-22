<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tour d'un héros = DEUX créneaux (doc 03 §28) : un DÉPLACEMENT et une ACTION,
 * dans l'ordre choisi. `a_joue` (tour terminé) ne passe à true que lorsque les
 * deux créneaux sont consommés, ou via une action « terminante » (concentration,
 * relever, terminer le tour). Réinitialisés à chaque nouveau tour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('a_deplace')->default(false)->after('a_joue');
            $table->boolean('a_agi')->default(false)->after('a_deplace');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn(['a_deplace', 'a_agi']);
        });
    }
};
