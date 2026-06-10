<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('groupe_id')->constrained('groupes')->cascadeOnDelete();
            $table->foreignId('gabarit_id')->constrained('gabarits_quete')->restrictOnDelete();
            $table->string('titre');
            $table->unsignedInteger('position_arc'); // n° dans l'arc (1..N)
            $table->enum('type_jalon', ['normale', 'sous_boss', 'boss_final']);
            $table->json('branche_active')->nullable(); // branche prise (ramification)
            $table->enum('etat', ['a_venir', 'en_cours', 'terminee', 'echouee'])->default('a_venir');
            $table->unsignedInteger('or_initial')->default(0); // pot commun au début de la quête
            $table->timestamps();
        });

        // Dépendance circulaire groupes ↔ quetes : la contrainte sur groupes.quete_courante_id
        // est ajoutée ici. SQLite (tests) ne supporte pas l'ajout de FK après création — la
        // colonne reste alors simplement indexée.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('groupes', function (Blueprint $table) {
                $table->foreign('quete_courante_id')->references('id')->on('quetes')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('groupes', function (Blueprint $table) {
                $table->dropForeign(['quete_courante_id']);
            });
        }

        Schema::dropIfExists('quetes');
    }
};
