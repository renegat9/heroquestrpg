<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passage du catalogue de sorts de Dread aux CARTES OFFICIELLES
 * (`dread_spells.pdf`, 29 sorts — doc 09 §4bis, photos de René du 2026-09-04).
 *
 * Trois changements de schéma, chacun exigé par une carte :
 *
 *  1. `type` gagne **`soin`** — *Soothe* et *Restore Dread* rendent des PV de
 *     Body à un monstre. Aucun des quatre types existants ne les décrivait, et
 *     les ranger dans `controle` aurait fait passer un soin par le jet de Mind
 *     d'un héros qui n'est même pas concerné.
 *
 *  2. `palier` gagne **`base`** — les extensions donnent la magie à des
 *     créatures ORDINAIRES : le Dread Cultist, le Specter, le Blightweaver et
 *     le Magus Guard lancent des sorts, et nos trois premiers sont de tier
 *     `base` (doc 18). Sans ce palier, `sortsDisponibles()` les aurait filtrés
 *     à zéro et leurs répertoires n'auraient jamais rien produit — un
 *     répertoire muet, exactement ce que le filtre par palier existe pour
 *     rendre lisible.
 *
 *  3. Le **Trait de Chaos** est SUPPRIMÉ. Il n'existe sur aucune carte : c'est
 *     un sort de notre invention, et il portait à lui seul la « priorité 2 » du
 *     choix de sort. `SortDreadSeeder` écrit en `updateOrCreate` et ne purge
 *     jamais (retirer la ligne du seeder ne l'aurait pas retirée de la base) —
 *     même raison, même geste que pour les cinq artefacts sans carte du
 *     2026-09-03. Les répertoires qui le nommaient encore sont réécrits ici
 *     aussi : un `monstres.sorts_dread` citant un sort disparu rétrécit en
 *     silence.
 */
return new class extends Migration
{
    /** Le sort inventé qui quitte le catalogue, et ce qui le remplace dans les répertoires. */
    private const REMPLACEMENTS = ['Trait de Chaos' => 'Éclair de Chaos'];

    public function up(): void
    {
        Schema::table('sorts_dread', function (Blueprint $table) {
            $table->string('type', 20)->change();
            $table->string('palier', 20)->change();
        });

        DB::table('sorts_dread')->whereIn('nom', array_keys(self::REMPLACEMENTS))->delete();

        $this->reecrireRepertoires(self::REMPLACEMENTS);
    }

    public function down(): void
    {
        // On ne ressuscite pas un sort qu'aucune carte ne décrit : la descente
        // se contente de rétrécir les colonnes, et laisse le seeder faire foi.
        DB::table('sorts_dread')->whereIn('type', ['soin'])->delete();
        DB::table('sorts_dread')->where('palier', 'base')->update(['palier' => 'sous_boss']);

        Schema::table('sorts_dread', function (Blueprint $table) {
            $table->enum('type', ['degats', 'controle', 'invocation', 'fuite'])->change();
            $table->enum('palier', ['sous_boss', 'boss'])->change();
        });
    }

    /**
     * Remplace, dans chaque `monstres.sorts_dread`, les sorts disparus par leur
     * héritier — et retire ceux qui n'en ont pas.
     *
     * @param  array<string, string>  $remplacements
     */
    private function reecrireRepertoires(array $remplacements): void
    {
        foreach (DB::table('monstres')->whereNotNull('sorts_dread')->get() as $monstre) {
            $sorts = json_decode((string) $monstre->sorts_dread, true);

            if (! is_array($sorts) || $sorts === []) {
                continue;
            }

            $nouveaux = [];

            foreach ($sorts as $nom) {
                $nom = $remplacements[$nom] ?? $nom;

                if ($nom !== null && ! in_array($nom, $nouveaux, true)) {
                    $nouveaux[] = $nom;
                }
            }

            if ($nouveaux !== $sorts) {
                DB::table('monstres')->where('id', $monstre->id)
                    ->update(['sorts_dread' => json_encode(array_values($nouveaux), JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
};
