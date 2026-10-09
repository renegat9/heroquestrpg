<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire `mort_si_non_releve` de la condition « Tombé » (`conditions.effet`).
 *
 * Décision de René (2026-10-09, mode Story de Jungles of Delthrak) : un héros tombé
 * ne meurt jamais — « tombé, jamais mort » (`docs/regles/vocabulaires-effets.md`).
 * La clé `mort_si_non_releve` était écrite au catalogue sans AUCUN lecteur (aucun
 * code ne la lit, aucun test ne la nomme) : une règle promise au joueur et jamais
 * tenue. Le seeder ne la porte plus ; cette migration la retire de la LIGNE
 * EXISTANTE, parce qu'un changement de lignes existantes est une migration, jamais
 * un re-seed (CLAUDE.md). Idempotente. Sans ligne « Tombé » (base neuve), ne fait rien.
 */
return new class extends Migration
{
    private const CLE = 'mort_si_non_releve';

    public function up(): void
    {
        $ligne = DB::table('conditions')->where('nom', 'Tombé')->first();

        if ($ligne === null) {
            return;
        }

        $effet = json_decode((string) $ligne->effet, true);

        if (! is_array($effet) || ! array_key_exists(self::CLE, $effet)) {
            return;
        }

        unset($effet[self::CLE]);

        DB::table('conditions')->where('id', $ligne->id)->update(['effet' => json_encode($effet)]);
    }

    /**
     * Irréversible à dessein : remettre la clé rétablirait une clé décorative.
     */
    public function down(): void
    {
        // Volontairement vide.
    }
};
