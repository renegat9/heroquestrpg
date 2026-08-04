<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobiliers', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->string('nom_anglais');
            $table->unsignedTinyInteger('largeur');
            $table->unsignedTinyInteger('hauteur');
            // Convention de portage (doc 17 §3) : le livret ne tranche jamais si un
            // héros peut se tenir sur la case d'un meuble — sur plateau la question
            // ne se pose pas, la pièce est un volume. On adopte donc la même règle
            // que les cases bloquées (infranchissable pour les deux camps) ; la
            // colonne reste un bool par ligne pour ne pas fermer la porte à un futur
            // meuble purement décoratif.
            $table->boolean('bloquant')->default(true);
            // Drapeau posé pour plus tard : AUCUN lecteur ne le consulte aujourd'hui.
            // La fouille du mobilier recoupe deux systèmes non spatiaux (coffres de
            // DeckFouille raisonnant en salle, piège de coffre avec un ordre
            // fouille-pièges-avant-trésor que le moteur ne vérifie pas) — chantier
            // à part entière, décidé plus tard (doc 17 §4).
            $table->boolean('fouillable')->default(false);
            $table->json('effet')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobiliers');
    }
};
