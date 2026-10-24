<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire l'« Ombre du Dread » du catalogue (René, 2026-10-24 : « si l'Ombre du Dread n'est mentionnée nulle part,
 * enlève-la »). Dread Wraith générique de Rise of the Dread Moon, elle a été remplacée dans la rencontre finale par
 * Magrian, le Dread Wraith nommé du livret (2026-10-23), et n'avait plus aucun rôle : ni boss, ni sous-boss, ni archétype.
 *
 * ⚠ Une ligne de CATALOGUE seulement, jamais une donnée de partie : `instances_monstres.monstre_id` est en
 * `restrictOnDelete`, et la ligne n'est supprimée QUE si aucune instance ne la référence (vérifié à 0 sur la vraie
 * base le 2026-10-24). Si une instance la référençait, la ligne resterait — sans rôle, comme avant — plutôt que de
 * toucher à une campagne. Le seeder ne la recrée plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('monstres')->where('nom_base', 'Ombre du Dread')->value('id');

        if ($id === null || DB::table('instances_monstres')->where('monstre_id', $id)->exists()) {
            return;
        }

        DB::table('monstres')->where('id', $id)->delete();
    }

    public function down(): void
    {
        // Rien : la ligne n'est plus semée. La rétablir, c'est la rajouter au `MonstreSeeder`.
    }
};
