<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes_heros', function (Blueprint $table) {
            $table->id();
            $table->enum('nom', ['barbare', 'nain', 'elfe', 'magicien'])->unique();
            $table->unsignedInteger('pv_body');
            $table->unsignedInteger('pv_mind');
            $table->unsignedInteger('attr_body');
            $table->unsignedInteger('attr_mind');
            $table->unsignedInteger('des_attaque');
            $table->unsignedInteger('des_defense');
            $table->unsignedInteger('deplacement_base');
            $table->unsignedInteger('bonus_sac')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes_heros');
    }
};
