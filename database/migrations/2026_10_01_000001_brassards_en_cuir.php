<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BRASSARDS — de CUIR, pas de métal (errata 2021, `docs/plan-errata-2021.md`
 * B1, René 2026-10-01).
 *
 * La carte officielle que cite `ObjetSeeder` le dit en toutes lettres :
 * « These **hardened leather** bracers give you 1 extra Defend die ». Le
 * marquage `metallique` posé le 2026-08-22 les rangeait pourtant avec la cotte
 * de mailles, et cela coûtait à trois classes : le Druide et le Rogue se les
 * voyaient REFUSER (`Equipement::estAccessible()`, « may not wear metal
 * armor »), et le Barde qui les portait perdait son dé de défense
 * supplémentaire (`porteMetalOuBouclier()`). La compilation d'errata de Ye
 * Olde Inn va dans le même sens : Hasbro confirme que le magicien les porte,
 * et la communauté les cite comme L'armure non métallique du Barde.
 *
 * Aucune ligne d'inventaire n'est touchée : l'accès et le dé du Barde relisent
 * `objets.metallique` à chaque calcul. La Rouille n'est pas concernée non
 * plus — elle vise l'arme et le casque, jamais l'emplacement `armure`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('objets')->where('nom', 'Brassards')->update(['metallique' => false]);
    }

    public function down(): void
    {
        DB::table('objets')->where('nom', 'Brassards')->update(['metallique' => true]);
    }
};
