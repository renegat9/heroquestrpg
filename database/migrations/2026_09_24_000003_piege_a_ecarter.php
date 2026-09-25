<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chute de blocs (livret p. 14, contrat « Les trois pièges de sol, enfin tels
 * que le livret les décrit », 2026-09-24) : « the hero then decides to move
 * ahead or move back to an empty square ». Tant que ce choix n'est pas fait,
 * le menu du héros ne contient QUE `s_ecarter_du_bloc` — et cet état DOIT
 * survivre à un téléphone rechargé, donc une COLONNE, jamais une clé de
 * cache (règle consolidée, CLAUDE.md : « chaque état durable vit en base »).
 *
 * Même patron que `reaction_en_attente` (2026-08-11) : {x, y, cases:
 * [{x, y, sens}]}, posé par `MoteurPieges::declencher()`, lu par
 * `MenuMoteur::generer()` pour forcer le menu, effacé par
 * `ResolveurTour::resoudreEcartDuBloc()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('piege_a_ecarter')->nullable()->after('reaction_en_attente');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('piege_a_ecarter');
        });
    }
};
