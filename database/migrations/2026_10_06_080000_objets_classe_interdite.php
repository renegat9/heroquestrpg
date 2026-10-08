<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `objets.classe_interdite` — liste blanche inversée, pour la carte unique qui
 * en a besoin : *Drakehide Cuirass* (Wizards of Morcar, doc 18) est une
 * armure NON métallique « cannot be worn by the Wizard ». Aucune des deux
 * restrictions de classe existantes ne le dit : `ClasseHeros.objets_autorises`
 * est une liste BLANCHE nominative (le Moine), et `Equipement::SANS_METAL`
 * ne porte que sur la MATIÈRE (`objets.metallique`) — cette cuirasse n'étant
 * pas métallique, aucune des deux ne l'atteint.
 *
 * `null` = aucune classe exclue (comportement actuel de TOUT le catalogue,
 * inchangé) ; une valeur nomme la SEULE classe à qui la carte refuse la
 * pièce — une colonne à une classe, parce qu'une seule carte à ce jour en
 * nomme une, et non un tableau JSON qu'aucune autre carte ne remplirait.
 * Lecteur : `App\Partie\Equipement::estAccessible()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            $table->string('classe_interdite', 40)->nullable()->after('boite');
        });
    }

    public function down(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            $table->dropColumn('classe_interdite');
        });
    }
};
