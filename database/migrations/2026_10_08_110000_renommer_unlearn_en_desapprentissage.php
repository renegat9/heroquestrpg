<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le sort de héros *Unlearn* (Wizards of Morcar, *Spells of Protection*) prend
 * son nom FRANÇAIS au catalogue : « Désapprentissage », comme les autres sorts
 * de la boîte (`SortSeeder`). Le nom anglais est conservé dans le registre
 * (`config/cartes.php`, section `sorts_heros`, clé `carte`).
 *
 * ⚠ Pourquoi une MIGRATION et non un simple changement dans `SortSeeder` :
 * le seeder fait un `updateOrCreate` sur le NOM. Renommer la clé sans toucher
 * la base créerait une SECONDE ligne (« Désapprentissage ») à côté de
 * « Unlearn », et les héros qui ont ce sort garderaient l'ancienne, avec ses
 * pivots. La donnée existante est donc RENOMMÉE en place — même `id`, mêmes
 * pivots `personnage_sorts`, mêmes oublis de quête.
 *
 * - Additive et idempotente : sans ligne « Unlearn », elle ne fait rien (base
 *   neuve, ou déjà renommée).
 * - Refuse de DEVINER : si les deux noms existent déjà (le seeder a tourné
 *   avant cette migration), elle lève une erreur au lieu de fusionner deux
 *   lignes qui portent peut-être des pivots différents. Aucune donnée n'est
 *   supprimée, jamais.
 * - `sorts_oublies_de_quete.nom` (source `sort`) suit le même renommage : c'est
 *   le nom qu'une quête en cours a déjà écrit pour un sort oublié.
 */
return new class extends Migration
{
    private const ANGLAIS = 'Unlearn';

    private const FRANCAIS = 'Désapprentissage';

    public function up(): void
    {
        $this->renommer(self::ANGLAIS, self::FRANCAIS);
    }

    public function down(): void
    {
        $this->renommer(self::FRANCAIS, self::ANGLAIS);
    }

    private function renommer(string $de, string $vers): void
    {
        if (! Schema::hasTable('sorts') || ! DB::table('sorts')->where('nom', $de)->exists()) {
            return; // base neuve, ou déjà renommée : rien à faire.
        }

        if (DB::table('sorts')->where('nom', $vers)->exists()) {
            throw new RuntimeException(
                "Renommage « {$de} » → « {$vers} » refusé : les DEUX noms existent dans `sorts`. "
                .'Fusionner deux lignes qui portent peut-être des pivots différents est une décision '
                .'humaine, pas une migration. Arbitrer avant de rejouer.'
            );
        }

        DB::table('sorts')->where('nom', $de)->update(['nom' => $vers]);

        if (Schema::hasTable('sorts_oublies_de_quete')) {
            DB::table('sorts_oublies_de_quete')
                ->where('source', 'sort')
                ->where('nom', $de)
                ->update(['nom' => $vers]);
        }
    }
};
