<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot A6b (`docs/plan-mode-arene.md` §2) : le héros DÉDIÉ à l'arène.
 *
 * René, 2026-11-09 : « mettons de côté l'import des personnages de heroquest rpg pour le moment, permettons seulement
 * la création ou l'utilisation d'un héros déjà créé pour l'arène afin d'intégrer les règles tel que dans le livret ».
 * Un héros d'arène est un vrai personnage du roster, marqué `mode = 'arene'`, qui n'entre JAMAIS en campagne. Vocabulaire
 * fermé : `Personnage::MODES`. Aucune ligne existante ne change : tout héros existant est un héros de campagne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->string('mode', 16)->default('campagne');
        });
    }

    public function down(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
