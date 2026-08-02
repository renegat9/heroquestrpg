<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salles à COFFRE d'une quête.
 *
 * Il n'en existait qu'un, celui de la salle la plus profonde, qui porte
 * l'artefact. Les portes secrètes, elles, n'ouvraient que sur un raccourci :
 * les trouver ne rapportait rien. Chaque salle située DERRIÈRE une porte
 * secrète abrite désormais son coffre — c'est ce qui paie la fouille.
 *
 * Le coffre de la salle-artefact garde sa priorité sur l'arme unique ; les
 * autres versent de l'or ou une potion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            // Index de salles (carte.grille.salles) abritant un coffre.
            // Inclut toujours `salle_artefact` quand elle existe.
            $table->json('salles_coffre')->nullable()->after('salle_artefact');
        });
    }

    public function down(): void
    {
        Schema::table('quetes', fn (Blueprint $table) => $table->dropColumn('salles_coffre'));
    }
};
