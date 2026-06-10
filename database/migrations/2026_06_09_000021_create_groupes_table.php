<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupes', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant')->unique(); // code saisi sur la tablette
            $table->string('nom');
            $table->text('theme');
            $table->enum('longueur', ['tres_courte', 'courte', 'normale', 'longue', 'tres_longue']);
            $table->unsignedInteger('nb_quetes_total');
            $table->json('plan_campagne')->nullable(); // squelette : prémisse, menace/boss, jalons, fils narratifs
            $table->json('ton')->nullable(); // préférence de table
            $table->unsignedInteger('or')->default(0); // bourse commune (M3)
            $table->enum('etat', ['en_cours', 'en_pause', 'terminee'])->default('en_cours');
            $table->enum('phase', ['hub', 'quete'])->default('hub');
            // FK vers quetes ajoutée dans la migration create_quetes (dépendance circulaire groupes ↔ quetes)
            $table->unsignedBigInteger('quete_courante_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupes');
    }
};
