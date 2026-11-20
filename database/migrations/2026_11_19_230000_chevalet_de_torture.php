<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * « Chevalet » → « Chevalet de torture » (2026-11-19) : le meuble du livret (« Rack », F2847 p. 33) venait d'entrer au
 * catalogue, et son nom seul se lisait « chevalet de peintre » — l'illustration générée en a peint un. Le nom dit
 * désormais ce qu'il est. `MobilierSeeder` clé sur `nom` : sans ce renommage, le seeder créerait une seconde ligne et
 * laisserait l'ancienne au catalogue. L'illustration suit le nom (`{id}-{slug}`) : l'ancienne est à régénérer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mobiliers')->where('nom', 'Chevalet')->update(['nom' => 'Chevalet de torture']);
    }

    public function down(): void
    {
        DB::table('mobiliers')->where('nom', 'Chevalet de torture')->update(['nom' => 'Chevalet']);
    }
};
