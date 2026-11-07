<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La Potion de force donne aussi ses +2 dés au prochain JET DE BODY (René, 2026-11-06, en pleine partie : « une potion
 * de force devrait aussi donner un bonus de 2 dés au test de force » ; « au premier des deux »). Écart assumé : la carte
 * ne parle que de l'attaque. Même valeur que `ObjetSeeder` ; le lecteur est `MoteurSorts::bonusJetBody()`.
 *
 * L'effet se relit sur l'OBJET à chaque jet (`effetSortSource()`) : une potion déjà bue avant la migration en profite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->poser(2);
    }

    public function down(): void
    {
        $this->poser(null);
    }

    private function poser(?int $valeur): void
    {
        $ligne = DB::table('objets')->where('nom', 'Potion de force')->first(['id', 'effet']);

        if ($ligne === null) {
            return;
        }

        $effet = (array) json_decode((string) $ligne->effet, true);

        if ($valeur === null) {
            unset($effet['bonus_des_body']);
        } else {
            $effet['bonus_des_body'] = $valeur;
        }

        DB::table('objets')->where('id', $ligne->id)->update(['effet' => json_encode($effet, JSON_UNESCAPED_UNICODE)]);
    }
};
