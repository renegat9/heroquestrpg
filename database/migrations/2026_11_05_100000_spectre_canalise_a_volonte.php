<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le Spectre lance *Canaliser l'Effroi* À VOLONTÉ (René, 2026-11-05 : « Met un mécanisme qui permet à un monstre d'avoir
 * "at will" »).
 *
 * « Each Specter is undead, ethereal, and can cast the following Dread spell at will: Channel Dread » (F6646 p. 19) ; le
 * règlement du livret : « Certain monsters have spells usable 'at will'; in those cases, the listed spells are always
 * available to cast as an action » (F6646 p. 4). Le mécanisme existait déjà (`sort_a_volonte`, Dragon et Scion) ; le
 * Spectre restait bridé à un usage par rencontre (`MoteurDread::USAGES_BASE`), une divergence assumée depuis le
 * 2026-09-04 que René retire.
 *
 * La capacité se lit dans le CATALOGUE à chaque tour (`MoteurDread::sortAVolonte()`) : un Spectre déjà en jeu canalise à
 * volonté dès cette migration. Vérifié sur la vraie base le 2026-11-05 : aucune instance active.
 */
return new class extends Migration
{
    private const NOM = 'Spectre';

    public function up(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)
            ->update(['capacites' => json_encode(['ethere', 'sort_a_volonte' => ['sort' => 'Canaliser l\'Effroi']])]);
    }

    public function down(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)->update(['capacites' => json_encode(['ethere'])]);
    }
};
