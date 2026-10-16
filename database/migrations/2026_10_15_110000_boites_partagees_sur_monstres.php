<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BOÎTES PARTAGÉES (René, 2026-10-15) : l'Archer elfe et le Guerrier elfe sont imprimés dans
 * DEUX boîtes — *The Mage of the Mirror* et *Rise of the Dread Moon* (tableau des monstres,
 * livret F6646 p. 36, « Elf Warrior » / « Elf Archer ») — avec la même fiche. Jusqu'ici ils
 * étaient `boite: mage_du_miroir` seulement, donc JAMAIS tirés sous le thème `dread_moon`
 * (`docs/plan-dread-moon.md` §4 Q1).
 *
 * `boite: null` (la recommandation du plan) les aurait fait entrer dans TOUS les thèmes et
 * dans le bestiaire « jeu de base seul » : faux. Dupliquer la ligne aurait fait deux
 * monstres au catalogue pour une figurine. Une colonne `boites_partagees` (liste de boîtes,
 * en plus de `boite` qui reste la boîte d'origine) dit exactement « ces deux boîtes-là » sur
 * UNE ligne ; `Monstre::boites()` la lit, `BestiaireGroupe::autorise()/contient()` la
 * consomment.
 *
 * Migration ADDITIVE : une colonne nullable (`null` = aucune boîte en plus, toutes les lignes
 * existantes). Les deux lignes concernées reçoivent leur valeur ICI (c'est le seul endroit
 * qui touche à des lignes existantes) et au re-seed de `MonstreSeeder` (`updateOrCreate`, qui
 * ne supprime rien). Idempotent : sur une base neuve, les deux `UPDATE` ne trouvent rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->json('boites_partagees')->nullable()->after('boite');
        });

        DB::table('monstres')
            ->whereIn('nom_base', ['Archer elfe', 'Guerrier elfe'])
            ->where('boite', 'mage_du_miroir')
            ->update(['boites_partagees' => json_encode(['dread_moon'])]);
    }

    public function down(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->dropColumn('boites_partagees');
        });
    }
};
