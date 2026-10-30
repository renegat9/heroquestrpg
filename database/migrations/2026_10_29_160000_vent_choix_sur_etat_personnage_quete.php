<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VENTS DE COULOIR (*Spirit Queen's Torment* q. 12 « The Tower of Air », livret G0053 p. 15 ; lot H des restes, 2026-10-29).
 *
 * « When a hero ends their movement in a hallway, they must roll 1 combat die. If the roll result is a shield, they move 2
 * additional squares in the direction of their choice. If the roll result is a skull, they move 2 squares in the direction of
 * Zargon's choice. » — le bouclier donne au héros un CHOIX EN ATTENTE, qui dure d'une requête à l'autre : la colonne
 * `vent_choix` (JSON `{x, y, cases, directions}`), jamais le cache, comme `piege_a_ecarter`. Un téléphone rechargé retrouve la
 * poussée à choisir, et la liste blanche des directions légales est celle qui a été décidée au moment du dé.
 *
 * ⚠ Migration ADDITIVE : une colonne nullable. Aucun héros n'attend de vent à la pose de la migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('vent_choix')->nullable()->after('enlise_sol');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('vent_choix');
        });
    }
};
