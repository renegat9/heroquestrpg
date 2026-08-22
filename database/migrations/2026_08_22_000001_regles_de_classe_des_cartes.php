<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux règles du DOS DES CARTES que le modèle ne savait pas dire (René, 2026-08-22).
 *
 * `objets.metallique` — Barde, Druide et Rogue raisonnent sur « armure
 * MÉTALLIQUE », une notion que les tags de maîtrise n'expriment pas :
 * `armure_legere` / `armure_lourde` disent le POIDS, pas la matière. Le
 * catalogue actuel ne contient que deux armures ordinaires, toutes deux
 * métalliques, donc la déduction marcherait aujourd'hui — et casserait en
 * silence le jour où une armure de cuir entre au catalogue. C'est exactement le
 * piège que René avait signalé pour le pilier et `bloque_vue` : on ne déduit
 * pas un fait d'un autre qui s'y superpose par hasard.
 *
 * `classes_heros.objets_autorises` — le Moine ne peut manier que dague,
 * arbalète, hachette, épée courte et bâton. Aucune combinaison de tags ne
 * décrit cette liste : hachette et épée courte partagent `arme_courante` avec
 * l'épée large, l'épée longue et la rapière, qui lui sont interdites. Une
 * liste blanche par classe, qui REMPLACE le contrôle par tags quand elle est
 * renseignée. Re-taguer les cinq armes aurait cassé dix classes pour en servir
 * une, `tag_equipement` étant une colonne unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            $table->boolean('metallique')->default(false)->after('tag_equipement');
        });

        Schema::table('classes_heros', function (Blueprint $table) {
            // `null` = aucune restriction nominative, les tags font foi (le cas
            // de TOUTES les classes sauf le Moine).
            $table->json('objets_autorises')->nullable()->after('tags_equipement');
        });
    }

    public function down(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            $table->dropColumn('metallique');
        });

        Schema::table('classes_heros', function (Blueprint $table) {
            $table->dropColumn('objets_autorises');
        });
    }
};
