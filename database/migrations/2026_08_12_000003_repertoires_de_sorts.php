<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sorts.element` accueille les RÉPERTOIRES, pas seulement les quatre écoles.
 *
 * Le Barde, le Druide et le Warlock n'ont pas d'élément : leur carte leur donne
 * trois sorts fixes. L'Elfe, lui, choisit à la création entre une école et
 * trois sorts du répertoire **elfique** (Mage of the Mirror). La colonne
 * `element` sert donc désormais de nom de RÉPERTOIRE — la réutiliser évite une
 * table de plus pour quatre lignes, mais son enum devait s'ouvrir.
 */
return new class extends Migration
{
    private const AVEC = ['feu', 'eau', 'terre', 'air', 'barde', 'druide', 'warlock', 'elfique'];

    private const SANS = ['feu', 'eau', 'terre', 'air'];

    public function up(): void
    {
        $this->enum(self::AVEC);
    }

    public function down(): void
    {
        $this->enum(self::SANS);
    }

    /**
     * @param  list<string>  $valeurs
     */
    private function enum(array $valeurs): void
    {
        Schema::table('sorts', function (Blueprint $t) use ($valeurs) {
            $t->enum('element', $valeurs)->change();
        });
    }
};
