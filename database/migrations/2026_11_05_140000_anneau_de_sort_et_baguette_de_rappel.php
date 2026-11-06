<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'Anneau de Sort et la Baguette de Rappel suivent leurs cartes (René, 2026-11-05).
 *
 *  - `etat_personnage_quete.anneau_de_sort` (JSON) : le sort DÉCLARÉ au premier tour de la quête, et si son second
 *    lancer est encore dû. Une colonne, jamais le cache.
 *  - L'Anneau de Sort perd sa `frequence` : ce n'est plus « le premier sort lancé, une fois par quête », c'est le sort
 *    déclaré, dont l'état vit dans la colonne ci-dessus.
 *  - La Baguette de Rappel passe à l'ELFE et au MAGICIEN (« allows the Elf or Wizard to cast two separate and
 *    different spells ») : tag propre `talisman_elfe_magicien`, ajouté aux deux classes. Elle était `armure_magicien`,
 *    ouvert aussi au Warlock et fermé à l'Elfe.
 *
 * Personne ne portait l'un ou l'autre objet le 2026-11-05 (vérifié en lecture seule) : aucune ligne d'inventaire n'est
 * touchée. Les classes ne gagnent qu'un tag ; rien n'est retiré.
 */
return new class extends Migration
{
    private const TAG = 'talisman_elfe_magicien';

    private const CLASSES = ['elfe', 'magicien'];

    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('anneau_de_sort')->nullable();
        });

        DB::table('objets')->where('nom', 'Anneau de Sort')->update(['effet' => json_encode(['sort_non_epuise' => true])]);
        DB::table('objets')->where('nom', 'Baguette de Rappel')->update(['tag_equipement' => self::TAG]);

        foreach (self::CLASSES as $classe) {
            $ligne = DB::table('classes_heros')->where('nom', $classe)->first();

            if ($ligne === null) {
                continue;
            }

            $tags = (array) json_decode((string) $ligne->tags_equipement, true);

            if (! in_array(self::TAG, $tags, true)) {
                DB::table('classes_heros')->where('nom', $classe)->update(['tags_equipement' => json_encode([...$tags, self::TAG])]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::CLASSES as $classe) {
            $ligne = DB::table('classes_heros')->where('nom', $classe)->first();

            if ($ligne !== null) {
                $tags = array_values(array_diff((array) json_decode((string) $ligne->tags_equipement, true), [self::TAG]));
                DB::table('classes_heros')->where('nom', $classe)->update(['tags_equipement' => json_encode($tags)]);
            }
        }

        DB::table('objets')->where('nom', 'Baguette de Rappel')->update(['tag_equipement' => 'armure_magicien']);
        DB::table('objets')->where('nom', 'Anneau de Sort')
            ->update(['effet' => json_encode(['sort_non_epuise' => true, 'frequence' => 'une_fois_par_quete'])]);

        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('anneau_de_sort');
        });
    }
};
