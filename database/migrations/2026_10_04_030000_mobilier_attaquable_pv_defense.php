<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le mobilier devient attaquable AU COMBAT, en plus d'être fracassable sur un
 * jet de Body (René, 2026-10-04 — « un seul lecteur générique « mobilier à
 * PV/défense », commun à Delthrak et Morcar »).
 *
 * `difficulte_destruction` (2026-08-24) couvrait déjà « on force le passage
 * d'un coup de Body » (une tentative par héros, succès/échec immédiat). Ce que
 * trois boîtes réclament en plus — le Crystal Cluster de *Jungles of Delthrak*
 * (« can be destroyed as a monster […] with 6 Body Points »), le Haut Autel et
 * les Coffres du Dread de *Wizards of Morcar* (« may be attacked using normal
 * combat », « can be attacked […] roll 6 Defend dice ») — est une réserve de
 * PV qu'on épuise au COMBAT, exactement comme un monstre : on peut la retenter
 * sans limite, et un coup qui ne suffit pas laisse le meuble entamé plutôt que
 * de fermer l'option.
 *
 * ⚠ `pv_body` ET `defense_dice` sont nullable PAR CHOIX, comme
 * `difficulte_destruction` : `null` veut dire « ne se détruit pas par cette
 * voie », pas « pas encore renseigné ». Une pièce peut n'avoir ni l'une ni
 * l'autre voie (indestructible), ou une seule des deux — jamais les deux
 * colonnes de destruction à la fois sur les entrées portées par ce chantier
 * (aucune source ne décrit un jet de Body ET un combat pour le même meuble).
 *
 * `defense_dice` à 0 est une vraie valeur, pas une absence : le Crystal
 * Cluster « cannot defend » (0 dé), distinct du Mur magique qui en lance 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobiliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('pv_body')->nullable()->after('difficulte_destruction');
            $table->unsignedTinyInteger('defense_dice')->nullable()->after('pv_body');
        });
    }

    public function down(): void
    {
        Schema::table('mobiliers', function (Blueprint $table) {
            $table->dropColumn(['pv_body', 'defense_dice']);
        });
    }
};
