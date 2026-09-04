<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un slot `bottes`, pour les deux cartes qui se chaussent.
 *
 * *Rabbit Boots* et *Elven Boots* sont des pièces PORTÉES : elles agissent tant
 * qu'on les a aux pieds, pas depuis le sac. Aucun slot existant ne convenait —
 * `armure` et `casque` les auraient mises en concurrence avec une vraie
 * protection, `talisman` avec les quatre joyaux de classe, et les deux paires se
 * seraient exclues l'une l'autre alors que rien sur leurs cartes ne le dit.
 *
 * ⚠ Même raisonnement, mot pour mot, que l'ajout du slot `talisman` le
 * 2026-08-09 : un emplacement neuf quand la pièce n'entre en concurrence avec
 * rien de ce qui existe.
 */
return new class extends Migration
{
    private const AVEC = ['arme_principale', 'arme_secondaire', 'casque', 'armure', 'talisman', 'bottes', 'sac', 'consommable'];

    private const SANS = ['arme_principale', 'arme_secondaire', 'casque', 'armure', 'talisman', 'sac', 'consommable'];

    public function up(): void
    {
        $this->enums(self::AVEC);
    }

    public function down(): void
    {
        // Les paires déjà chaussées repartent au sac plutôt que d'être perdues :
        // le slot disparaît, l'objet non.
        DB::table('inventaire')->where('emplacement', 'bottes')->update(['emplacement' => 'sac']);
        DB::table('objets')->where('emplacement', 'bottes')->update(['emplacement' => 'talisman']);

        $this->enums(self::SANS);
    }

    /**
     * @param  list<string>  $valeurs
     */
    private function enums(array $valeurs): void
    {
        foreach (['objets', 'inventaire'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($valeurs) {
                $t->enum('emplacement', $valeurs)->change();
            });
        }
    }
};
