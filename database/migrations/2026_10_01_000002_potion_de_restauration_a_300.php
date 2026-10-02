<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POTION DE RESTAURATION — 300 po au lieu de 500 (errata 2021 C3, René
 * 2026-10-01).
 *
 * Notre carte photographiée (© 2022, Alchemist's Shop de *Kellar's Keep* et
 * *Return of the Witch Lord*) dit 500 pour « 1 Body et 1 Mind » — un prix que
 * les joueurs jugeaient absurde, et Hasbro leur a donné raison : la potion est
 * réimprimée à **300** dans le paquet *Alchemy* de *Rise of the Dread Moon*
 * (compilation d'errata Ye Olde Inn). On suit la réimpression, la plus récente
 * des deux cartes officielles. Doc 16 §2.1bis cite les deux.
 *
 * Le catalogue seulement : un exemplaire déjà acheté ne se rembourse pas, et
 * l'étal relit `prix_base` à chaque ouverture.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('objets')->where('nom', 'Potion de restauration')->update(['prix_base' => 300]);
    }

    public function down(): void
    {
        DB::table('objets')->where('nom', 'Potion de restauration')->update(['prix_base' => 500]);
    }
};
