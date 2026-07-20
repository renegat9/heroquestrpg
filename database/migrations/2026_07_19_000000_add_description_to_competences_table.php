<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competences', function (Blueprint $table) {
            // Description lisible du talent (doc 01 §6) — servie à la manette
            // (arbre de montée de niveau + fiche). Nullable : le seeder la remplit.
            $table->string('description')->nullable()->after('nom');
        });
    }

    public function down(): void
    {
        Schema::table('competences', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
