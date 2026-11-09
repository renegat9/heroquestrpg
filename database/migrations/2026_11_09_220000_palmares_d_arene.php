<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot A6 (`docs/plan-mode-arene.md` §5, AQ4) : ce que l'arène rapporte — rien à la campagne, des
 * statistiques au héros.
 *
 * - `arene_combats` : le palmarès des combats Coop (date, issue, rounds, combattants, Defender). Un combat Monster vs
 *   Monster n'y entre pas (René : « aucune stats »).
 * - `arene_statistiques` : une ligne par HÉROS SOURCE (jamais la doublure, qui disparaît) — combats, victoires,
 *   ennemis vaincus par NOM DE CATALOGUE. Liée au héros en `cascadeOnDelete` : supprimer un héros emporte ses
 *   statistiques. Écrire ici n'est pas toucher au héros : le test d'étanchéité compare l'empreinte HORS de cette table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arene_combats', function (Blueprint $table) {
            $table->id();
            $table->string('issue', 16); // victoire | defaite
            $table->unsignedInteger('rounds');
            $table->json('combattants'); // [{heros_id, nom, classe}]
            $table->json('defender');    // [nom_base, …]
            $table->timestamps();
        });

        Schema::create('arene_statistiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnage_id')->unique()->constrained('personnages')->cascadeOnDelete();
            $table->unsignedInteger('combats')->default(0);
            $table->unsignedInteger('victoires')->default(0);
            $table->json('ennemis_vaincus')->nullable(); // {nom_base: nombre}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arene_statistiques');
        Schema::dropIfExists('arene_combats');
    }
};
