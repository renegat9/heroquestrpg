<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot A1 (`docs/plan-mode-arene.md` §2) : le héros n'entre jamais dans l'arène, sa DOUBLURE y entre.
 *
 * Une doublure est une ligne `personnages` à part entière — le moteur s'y applique sans changer d'une ligne — qui
 * porte l'id du héros qu'elle copie. Posée par le serveur seul (`App\Partie\Arene\Doublures::doubler()`), jamais par
 * un client : ce n'est pas le drapeau `est_test` refusé le 2026-08-23.
 *
 * `cascadeOnDelete` : supprimer un héros emporte ses doublures en cours (et, par leurs propres cascades, leur
 * inventaire, leurs sorts, leurs talents). Aucune ligne existante ne change : aucun héros n'est une doublure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->foreignId('doublure_de')->nullable()->constrained('personnages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('doublure_de');
        });
    }
};
