<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JETONS DE REJETON accrochés à un héros (Jungles of Delthrak, doc 18 §5 —
 * règle de retrait précisée par René le 2026-08-10).
 *
 * « Un jeton posé sur la fiche d'un héros inflige 1 Body Point automatique et
 * indéfendable à chaque fin de tour tant qu'il reste en sa possession,
 * cumulable. » Ce qui manquait — comment s'en débarrasser — est désormais connu :
 * **on les attaque**. Un héros adjacent au porteur vise le JETON et non son
 * compagnon.
 *
 * Le compteur vit sur l'état de QUÊTE, pas sur le personnage : les rejetons sont
 * une saleté qu'on ramasse dans un donjon, pas une infirmité qu'on traîne d'une
 * campagne à l'autre. Sortir du donjon les décroche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->unsignedInteger('jetons_rejeton')->default(0)->after('tombe');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('jetons_rejeton');
        });
    }
};
