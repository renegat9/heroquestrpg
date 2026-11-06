<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La campagne « Trio » passe de la rotation historique au FILTRE Rise of the Dread Moon (René, 2026-11-06 : « Est-ce
 * qu'on peut assigner les 3 extensions qu'il a utilisées à la campagne en filtre ? », puis « Dread Moon seul »).
 *
 * Trio est née avant le bestiaire manuel : `boites_bestiaire` null, thème figé `dread_moon` — une PRÉFÉRENCE, sous
 * laquelle ses deux premières quêtes ont aussi tiré le Rejeton et le Tisseur putrides (Jungles of Delthrak). Le filtre
 * garde le jeu de base et nos créatures (toujours inclus), Dread Moon et ce que The Mage of the Mirror PARTAGE avec lui
 * (Archer et Guerrier elfes, `boites_partagees`). Simulé avant d'écrire : les adversaires des quêtes 3 à 7 restent ceux
 * de la rotation (Garde-mage aux deux sous-boss, Magrian au boss final). Ajouter Delthrak au filtre les aurait remplacés
 * par Deathspinner, Bakabri et Gruulob : les boîtes cochées sont toutes préférées, et Delthrak a plus de sous-boss et de
 * boss que Dread Moon. C'est ce qui a fait écarter cette option.
 *
 * Visée : l'id ET l'identifiant du groupe, et seulement s'il est encore en rotation historique — sans effet sur toute
 * autre base (la suite de tests, une autre installation) et idempotente. `origine_boites` null : un choix des joueurs,
 * comme une liste cochée à la création. `theme_bestiaire` reste `dread_moon` : il n'est plus lu tant qu'une liste
 * existe, et `down()` rend la rotation exacte d'avant.
 */
return new class extends Migration
{
    private const GROUPE_ID = 120;

    private const IDENTIFIANT = 'trio-7cb4';

    private const BOITES = ['dread_moon'];

    public function up(): void
    {
        DB::table('groupes')
            ->where('id', self::GROUPE_ID)->where('identifiant', self::IDENTIFIANT)
            ->whereNull('boites_bestiaire')
            ->update(['boites_bestiaire' => json_encode(self::BOITES), 'origine_boites' => null]);
    }

    public function down(): void
    {
        DB::table('groupes')
            ->where('id', self::GROUPE_ID)->where('identifiant', self::IDENTIFIANT)
            ->where('boites_bestiaire', json_encode(self::BOITES))->whereNull('origine_boites')
            ->update(['boites_bestiaire' => null]);
    }
};
