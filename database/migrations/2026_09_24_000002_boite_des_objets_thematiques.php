<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `objets.boite` — même patron que `monstres.boite` (2026-09-04), pour la
 * même raison : un objet peut n'avoir de sens que dans UN thème de campagne.
 *
 * ⚠ Trouvé en jouant (René, 2026-09-24) : les Raquettes de Vitesse (*Snowshoes
 * of Speed*, Frozen Horror) ne rendent leur bonus que dans une quête FIGÉE
 * `horreur_des_glaces` (`Equipement::bonusDeplacementActif()`, 2026-09-10),
 * mais `DeckFouille::choisirArtefact()` ne le savait pas : la seule arme unique
 * du coffre de fin de donjon pouvait tomber sur cet objet dans une campagne de
 * jungle, où il ne servirait plus jamais à rien — l'artefact unique de la
 * quête consommé en butin mort.
 *
 * `null` n'est pas un trou : il vaut « **aucune boîte** », le cas de la
 * quasi-totalité du catalogue — un objet sans boîte convient à TOUTE
 * campagne, thème glacé ou pas. Seuls les objets dont l'EFFET ne sert
 * strictement à rien hors d'un thème portent une valeur ici (examen carte par
 * carte, pas une supposition — voir `DeckFouille::choisirArtefact()`) :
 * possession d'une carte officielle liée à une boîte n'implique PAS que
 * l'effet soit theme-locked (l'Anneau de Feu vient de Kellar's Keep mais
 * protège d'un dégât de feu que le jeu de BASE inflige déjà).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            $table->string('boite', 40)->nullable()->after('tag_equipement');
        });

        // ⚠ La colonne SEULE ne corrige rien sur une base existante : le seeder
        // renseigne `boite` pour une base NEUVE, mais `migrate` ne le rejoue
        // pas. Sans ces deux lignes, la base réelle aurait reçu une colonne
        // vide, le filtre de `DeckFouille::choisirArtefact()` n'aurait rien
        // exclu, et les Raquettes seraient restées tirables dans une campagne
        // de jungle — le défaut même que cette migration existe pour clore.
        // « Un changement de lignes existantes est une migration, jamais un
        // re-seed » (CLAUDE.md). Mêmes deux objets, même valeur que le seeder.
        DB::table('objets')
            ->whereIn('nom', ['Raquettes de Vitesse', 'Anneau de Chaleur'])
            ->update(['boite' => 'horreur_des_glaces']);
    }

    public function down(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            $table->dropColumn('boite');
        });
    }
};
