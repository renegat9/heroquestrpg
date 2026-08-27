<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le mobilier devient DESTRUCTIBLE, sur un jet de Body (René, 2026-08-24).
 *
 * `attribut_body` ne servait qu'à deux situations rares — un piège détecté
 * qu'on a le droit de désamorcer, une fosse sur le trajet —, si bien que les
 * trois nœuds `bonus_attribut_body` de la grille de talents étaient achetables
 * et quasi sans effet. Fracasser un obstacle est le deuxième des trois emplois
 * que René lui donne.
 *
 * ⚠ `null` = INDESTRUCTIBLE, et ce n'est pas « pas encore renseigné » : le
 * tombeau est un sarcophage de pierre, on ne le met pas en pièces à mains nues.
 * La colonne est donc nullable par CHOIX, pas par commodité.
 *
 * ⚠ La valeur est la difficulté **brute**. Le plafond (`App\Partie\DifficulteBody`)
 * s'applique à la génération du menu, jamais ici : il dépend du meilleur Body
 * du groupe, qui monte quand un héros achète *Colosse* et descend quand le
 * costaud s'en va. Figer la difficulté effective dans le catalogue la ferait
 * mentir dès le niveau suivant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobiliers', function (Blueprint $table) {
            $table->unsignedTinyInteger('difficulte_destruction')->nullable()->after('fouillable');
        });
    }

    public function down(): void
    {
        Schema::table('mobiliers', function (Blueprint $table) {
            $table->dropColumn('difficulte_destruction');
        });
    }
};
