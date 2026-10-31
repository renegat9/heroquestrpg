<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire nos six créations du catalogue (René, 2026-10-31 : « Enlève nos créations et mets ceux du jeu de base »).
 *
 * Champion, Seigneur, Chamane Gobelin, Liche, Sorcier des Tempêtes (inventés : `reference/09_bestiaire.md` les dit
 * « nôtre ») et Troll (carte « Cave Troll » du paquet Sjeng, compilation d'amateur, `reference/16_armurerie.md`).
 * Les méchants du livret de base les remplacent (Ulag, Grak, Balur, le Seigneur Sorcier de Barak Tor), semés par
 * `MonstreSeeder`. Vérifié à 0 instance sur la vraie base le 2026-10-31.
 *
 * ⚠ Une ligne de CATALOGUE seulement, jamais une donnée de partie : `instances_monstres.monstre_id` est en
 * `restrictOnDelete`, et la ligne n'est supprimée QUE si aucune instance ne la référence. Une ligne encore référencée
 * RESTE — sans rôle, comme avant — plutôt que de toucher à une campagne (précédent : `2026_10_24_100000_retirer_ombre_du_dread`).
 * Le seeder ne les recrée plus.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const NOMS = ['Champion', 'Seigneur', 'Chamane Gobelin', 'Liche', 'Sorcier des Tempêtes', 'Troll'];

    public function up(): void
    {
        $ids = DB::table('monstres')->whereIn('nom_base', self::NOMS)->pluck('id', 'nom_base');

        foreach ($ids as $id) {
            if (DB::table('instances_monstres')->where('monstre_id', $id)->exists()) {
                continue;
            }

            DB::table('monstres')->where('id', $id)->delete();
        }
    }

    public function down(): void
    {
        // Rien : les lignes ne sont plus semées. Les rétablir, ce serait les rajouter au `MonstreSeeder`.
    }
};
