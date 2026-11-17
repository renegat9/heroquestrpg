<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE, lot K5j (R4) — l'ÉQUIPEMENT CONFISQUÉ (*Rise of the Dread Moon*, F6646 p. 11).
 *
 * `quetes.consignes` = `{personnage_id: {or, objets: [lignes d'inventaire], rendue}}` : ce qu'on a pris aux héros au
 * départ d'une quête construite, en attendant qu'ils le reprennent en fouillant la salle désignée. Rien n'est perdu :
 * tout ce qui n'est pas repris est rendu à la fin de la quête (`App\Partie\Classique\Confiscation`). État durable : une
 * colonne, jamais le cache. `null` pour toute quête existante — aucune n'a de consigne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('consignes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('consignes');
        });
    }
};
