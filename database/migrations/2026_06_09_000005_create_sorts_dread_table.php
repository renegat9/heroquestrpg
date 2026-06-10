<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sorts_dread', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->enum('palier', ['sous_boss', 'boss']);
            $table->enum('type', ['degats', 'controle', 'invocation', 'fuite']);
            $table->json('effet');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sorts_dread');
    }
};
