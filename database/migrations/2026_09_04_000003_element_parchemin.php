<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `sorts.element` accueille les sorts qui n'existent QU'EN PARCHEMIN.
 *
 * *Trésor sans Péril* est le premier : sa carte dit « This SPELL SCROLL enables
 * a hero to… », jamais « This spell may be cast on… ». Aucun héros ne l'apprend,
 * il arrive par le parchemin et il repart avec.
 *
 * ⚠ `parchemin` n'est PAS une école. Il n'entre dans aucun répertoire, il
 * n'est pas dans `MoteurSorts::ELEMENTS`, et les routes de création le refusent
 * déjà par `Rule::in(ELEMENTS)`. Lui donner une école existante l'aurait ajouté
 * au grimoire du magicien — un sort de plus au départ, gratuitement.
 */
return new class extends Migration
{
    private const AVEC = ['feu', 'eau', 'terre', 'air', 'barde', 'druide', 'warlock', 'elfique', 'parchemin'];

    private const SANS = ['feu', 'eau', 'terre', 'air', 'barde', 'druide', 'warlock', 'elfique'];

    public function up(): void
    {
        $this->enum(self::AVEC);
    }

    /**
     * ⚠ Irréversible côté DONNÉES : les sorts de cet élément partent, et leurs
     * parchemins avec — sans quoi l'enum refuserait de se refermer. Les lignes
     * d'inventaire qui les portaient sont perdues ; c'est le même arbitrage que
     * pour les cinq artefacts hors source du 2026-09-03, et pour la même
     * raison : un parchemin dont le sort n'existe plus n'est ni lisible, ni
     * revendable, ni affichable, et ne le dit pas.
     */
    public function down(): void
    {
        $ids = DB::table('sorts')->where('element', 'parchemin')->pluck('id');

        if ($ids->isNotEmpty()) {
            $objets = DB::table('objets')
                ->where('categorie', 'parchemin')
                ->whereIn(DB::raw("json_unquote(json_extract(effet, '$.sort_id'))"), $ids)
                ->pluck('id');

            DB::table('inventaire')->whereIn('objet_id', $objets)->delete();
            DB::table('objets')->whereIn('id', $objets)->delete();
            DB::table('personnage_sorts')->whereIn('sort_id', $ids)->delete();
            DB::table('sorts')->whereIn('id', $ids)->delete();
        }

        $this->enum(self::SANS);
    }

    /**
     * @param  list<string>  $valeurs
     */
    private function enum(array $valeurs): void
    {
        Schema::table('sorts', function (Blueprint $t) use ($valeurs) {
            $t->enum('element', $valeurs)->change();
        });
    }
};
