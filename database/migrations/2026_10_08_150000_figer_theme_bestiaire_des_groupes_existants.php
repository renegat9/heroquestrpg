<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fige le thème de bestiaire des groupes qui ont DÉJÀ joué sans l'avoir écrit.
 *
 * `wizards_of_morcar` entre dans `DemarreurQuete::BOITES_THEMATIQUES` (modulo
 * 6 → 7, 2026-10-08). `groupes.theme_bestiaire` fige le thème au premier
 * démarrage de quête (migration du 2026-09-06), mais un groupe qui a joué AVANT
 * cette colonne et n'a pas rejoué depuis a encore `null` : `themeBestiaireDuGroupe()`
 * retomberait sur l'id modulo 7 et sa campagne changerait d'univers entre deux
 * portes. On écrit donc ici, pour ces seuls groupes (≥ 1 quête, thème et
 * bestiaire manuel absents), le thème que l'ANCIENNE rotation à 6 boîtes leur
 * donnait. Liste COPIÉE ici exprès : elle est gelée à l'état d'avant l'ajout et
 * ne doit jamais suivre `BOITES_THEMATIQUES`.
 *
 * Idempotente et sans effet sur un groupe déjà figé ou en bestiaire manuel.
 */
return new class extends Migration
{
    private const ANCIENNE_ROTATION = [
        'dread_moon', 'mage_du_miroir', 'horde_ogre', 'jungles_delthrak', 'horreur_des_glaces', 'first_light',
    ];

    public function up(): void
    {
        $ids = DB::table('groupes')
            ->whereNull('theme_bestiaire')
            ->whereNull('boites_bestiaire')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('quetes')->whereColumn('quetes.groupe_id', 'groupes.id'))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::table('groupes')->where('id', $id)->whereNull('theme_bestiaire')
                ->update(['theme_bestiaire' => self::ANCIENNE_ROTATION[((int) $id) % count(self::ANCIENNE_ROTATION)]]);
        }
    }

    public function down(): void
    {
        // Irréversible par construction : on ne sait plus quels groupes étaient à null.
    }
};
