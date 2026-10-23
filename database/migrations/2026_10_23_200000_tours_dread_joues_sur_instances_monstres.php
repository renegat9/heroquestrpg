<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOURS DE DREAD JOUÉS (Spirit Queen's Torment, lot C, 2026-10-23 — `docs/plan-spirit-queen.md`, « Exécution (2026-10-23) »).
 *
 * Kavra « may cast both spells simultaneously ON HER FIRST TURN ONLY » (G0053 p. 11) : pour que la capacité
 * `double_sort` { premier_tour } sache ce qu'est « le premier tour », un monstre doit se souvenir de ceux qu'il
 * a déjà joués. Aucun compteur de tour n'existait (`ResolveurTour::ouvrirNouveauTour()` ne numérote pas les
 * rounds). Une colonne, jamais un cache : un `/reprise` ne doit pas rendre son second sort à la sorcière.
 *
 * Migration ADDITIVE : entier non signé, défaut 0. Les lignes existantes valent 0, ce qui est exact pour une
 * rencontre déjà en cours : aucun lanceur de Dread n'a de capacité `double_sort` aujourd'hui, donc la colonne
 * n'est lue par rien d'autre que le nouveau lecteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('instances_monstres', 'tours_dread_joues')) {
            Schema::table('instances_monstres', function (Blueprint $table): void {
                $table->unsignedSmallInteger('tours_dread_joues')->default(0)->after('passif_etat');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('instances_monstres', 'tours_dread_joues')) {
            Schema::table('instances_monstres', function (Blueprint $table): void {
                $table->dropColumn('tours_dread_joues');
            });
        }
    }
};
