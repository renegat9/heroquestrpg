<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `personnages.recrutements_a_rabais` / `personnages.rabais_recrutement_po` —
 * l'ÉTAT DURABLE de la Potion of Charm (Wizards of Morcar, carte de trésor
 * relue à l'image 2026-10-08 : « You may hire up to three Mercenaries for 25
 * gold coins each less than normal »).
 *
 * Ce n'est PAS une remise permanente tant qu'on possède la potion (la première
 * version, fausse, la lisait sur l'inventaire) : boire la potion (au hub,
 * `MoteurPotions::boireAuHub()`) ajoute des recrutements à rabais au héros, et
 * `MercenaireController::recruter()` en consomme UN par recrutement. Une colonne,
 * jamais le cache : la règle consolidée du projet (CLAUDE.md).
 *
 * Additive, défaut 0 : tout héros existant garde le prix plein, comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->unsignedTinyInteger('recrutements_a_rabais')->default(0);
            $table->unsignedSmallInteger('rabais_recrutement_po')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->dropColumn(['recrutements_a_rabais', 'rabais_recrutement_po']);
        });
    }
};
