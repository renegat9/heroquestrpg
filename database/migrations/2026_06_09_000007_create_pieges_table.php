<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieges', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->boolean('detectable')->default(true);
            $table->enum('desarmable', ['oui', 'non', 'partiel']);
            $table->enum('usage', ['unique', 'persistant']);
            $table->json('effet');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieges');
    }
};
