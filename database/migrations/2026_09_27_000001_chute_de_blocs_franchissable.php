<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LA CHUTE DE BLOCS SE SAUTE, TANT QU'ELLE N'EST PAS TOMBÉE (René, 2026-09-27
 * — livret de Zargon p. 14, `reference/16_armurerie.md` §7.3 : « peut être
 * désamorcée/sautée AVANT déclenchement seulement »). Seule la Fosse portait
 * `franchissable` ; la Chute de blocs reçoit la même clé, lue par
 * `MoteurPieges::estFranchissable()` (menu + résolveur du saut). Une fois
 * tombée elle passe à l'état `bloc` et ne figure plus parmi les pièges
 * détectés adjacents : le saut disparaît de lui-même.
 *
 * Migration et pas seulement le seeder : c'est la LIGNE EXISTANTE du
 * catalogue que la vraie partie relit. Ciblée par le nom, sans effet si le
 * piège n'est pas au catalogue ; aucune ligne de `cartes.grille.pieges`
 * n'est touchée (une quête en cours relit `pieges.effet` à chaque fois).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ecrire([
            'des_combat' => 3,
            'bloc_permanent' => true,
            'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
        ]);
    }

    public function down(): void
    {
        $this->ecrire(['des_combat' => 3, 'bloc_permanent' => true]);
    }

    /** @param  array<string, mixed>  $effet */
    private function ecrire(array $effet): void
    {
        DB::table('pieges')->where('nom', 'Chute de blocs')->update([
            'effet' => json_encode($effet, JSON_UNESCAPED_UNICODE),
        ]);
    }
};
