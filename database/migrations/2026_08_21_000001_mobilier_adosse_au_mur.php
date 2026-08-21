<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Ce meuble se pose CONTRE UN MUR » — un fait à part entière (René, 2026-08-21).
 *
 * Le placement tirait une case au hasard et une orientation à pile ou face,
 * sans jamais regarder les murs : mesuré sur douze donjons réels, une
 * bibliothèque suivait le mur quatre fois sur dix, une armoire une sur six.
 *
 * La tentation était d'en déduire la règle de `bloque_vue` — les trois meubles
 * hauts du catalogue (bibliothèque, râtelier, armoire) sont justement les trois
 * qui devraient être adossés, et « c'est haut donc ça occulte ET ça s'appuie »
 * se tient. ⚠ Mais c'est FAUX en général, et René l'a signalé avant que ça ne
 * morde : un PILIER bloque la ligne de vue et se dresse au MILIEU d'une salle.
 * Le jour où on l'ajoute, la déduction l'aurait soit collé au mur, soit — pire
 * — refusé de le poser là où il a du sens.
 *
 * C'est exactement l'histoire de `bloque_mouvement`/`bloque_vue`, scindés le
 * 2026-08-05 pour la même raison : deux faits indépendants qu'une seule colonne
 * conflait. On ne refait pas l'erreur avec un troisième.
 *
 * Défaut FALSE : un meuble se tient libre sauf mention contraire. Un futur
 * pilier, brasero ou statue n'a donc rien à déclarer pour être bien placé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobiliers', function (Blueprint $table) {
            $table->boolean('adosse_au_mur')->default(false)->after('bloque_vue');
        });
    }

    public function down(): void
    {
        Schema::table('mobiliers', function (Blueprint $table) {
            $table->dropColumn('adosse_au_mur');
        });
    }
};
