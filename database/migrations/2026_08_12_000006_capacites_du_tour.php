<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compteur des capacités « **once per turn** ».
 *
 * `capacites_utilisees` comptait déjà les « once per quest » — l'état d'un héros
 * étant recréé à chaque quête, il se remet à zéro tout seul. Les capacités par
 * TOUR n'ont pas cette chance : trois cartes en portent (l'*Ambidextrie* et la
 * *Frappe opportuniste* du Rogue, le *Sixième sens* et le *Sens du piège* de
 * l'Explorateur), et il leur faut une remise à zéro explicite, au même endroit
 * que `a_joue` — sinon la capacité ne servirait qu'une fois par quête, ou pas de
 * limite du tout.
 *
 * Une liste de noms plutôt qu'une colonne par capacité : même raison qu'à côté,
 * 24 booléens pour 24 cartes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('capacites_tour')->nullable()->after('capacites_utilisees');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('capacites_tour');
        });
    }
};
