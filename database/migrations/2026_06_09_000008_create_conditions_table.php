<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conditions', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->enum('type', ['physique', 'mental']); // mental → immunité des monstres Mind 0
            $table->json('effet');
            $table->integer('duree_defaut'); // en tours ; 0 = jusqu'à une condition de fin (résistance, relève…)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conditions');
    }
};
