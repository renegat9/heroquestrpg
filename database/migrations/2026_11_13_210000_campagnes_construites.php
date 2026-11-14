<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE, lot K1 (`docs/plan-mode-classique.md` §3, `docs/contrat-api.md` « Constructeur de campagnes »).
 *
 * Une campagne construite est un DOCUMENT (`document`, JSON) : c'est la source complète. `quetes_construites` n'en
 * est qu'une PROJECTION, réécrite à chaque enregistrement dans la même transaction — elle sert à lister et à lire une
 * quête sans parcourir le document entier. Aucune de ces lignes ne vit en cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagnes_construites', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('titre', 200);
            $table->string('auteur', 200)->nullable();
            $table->string('mention', 500)->nullable();
            $table->json('document');
            $table->unsignedSmallInteger('version_format');
            $table->char('empreinte', 64);
            $table->timestamps();
        });

        Schema::create('quetes_construites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_construite_id')
                ->constrained('campagnes_construites')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('rang');
            $table->string('slug', 100);
            $table->string('titre', 200);
            $table->json('document');
            $table->timestamps();

            $table->unique(['campagne_construite_id', 'rang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quetes_construites');
        Schema::dropIfExists('campagnes_construites');
    }
};
