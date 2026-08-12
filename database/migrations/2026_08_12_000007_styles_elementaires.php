<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Styles Élémentaires ÉPUISÉS du Moine (Path of the Wandering Monk).
 *
 * « After you use a technique, that Elemental Style is exhausted. » —
 * « The Elemental Style of Fire cannot be used until you have exhausted Air,
 * Earth, and Water. » — « If there are no monsters in your line of sight at the
 * start of your turn, recover all exhausted Elemental Styles. »
 *
 * Ni « once per quest » ni « once per turn » : un troisième rythme, qui se
 * RECHARGE en cours de quête dès que le combat s'éloigne. D'où sa propre
 * colonne — la liste des éléments dépensés — plutôt qu'une entrée de plus dans
 * `capacites_utilisees`, qu'il faudrait alors savoir vider sélectivement.
 *
 * C'est aussi ce qui fait du Moine une classe qui monte en puissance : le Feu,
 * verrouillé tant que les trois autres tiennent, n'arrive qu'au bout d'un vrai
 * combat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('styles_epuises')->nullable()->after('capacites_tour');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('styles_epuises');
        });
    }
};
