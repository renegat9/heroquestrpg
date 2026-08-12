<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `competences.description` passe de `varchar(255)` à `text`.
 *
 * ⚠ Bug attrapé le 2026-08-12, et il mérite d'être raconté : les capacités de
 * carte portent le TEXTE DE LA CARTE, et les Styles Élémentaires du Moine
 * décrivent DEUX techniques par nœud — la carte est recto-verso. La plus longue
 * dépasse 255 caractères.
 *
 * La suite de tests était pourtant VERTE : elle tourne sur sqlite, qui
 * n'applique pas la longueur d'un `varchar`. MariaDB, lui, refusait l'insert —
 * et le seeder s'arrêtait au Moine, laissant le Chevalier, le Berserker et
 * l'Explorateur absents de la base réelle sans qu'aucun test ne le signale.
 *
 * Une description n'a de toute façon aucune raison d'être bornée à 255 : c'est
 * du texte affiché, pas une clé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competences', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('competences', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
        });
    }
};
