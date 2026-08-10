<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marque « cette créature a été BRÛLÉE » (décision de René, 2026-08-09).
 *
 * La carte du troll dit : « Trolls may choose to regenerate 1 Body point of
 * damage instead of attacking. **Damage done by fire is permanent and cannot be
 * regenerated.** » La régénération existait déjà (`MoteurDread`, +1 PV au début
 * du tour) ; ce qui manquait, c'est de savoir qu'un feu l'a interrompue.
 *
 * ⚠ Écart assumé : la carte rend permanents les seuls PV perdus PAR LE FEU, ce
 * qui demanderait de comptabiliser les dégâts par nature. Ici, une créature
 * brûlée cesse simplement de régénérer. Même intention tactique — le feu est la
 * réponse au troll — pour une donnée booléenne au lieu d'un grand livre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->boolean('brule')->default(false)->after('etat');
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn('brule');
        });
    }
};
