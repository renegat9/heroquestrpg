<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quatrième emplacement porté : le CASQUE (décision de René, 2026-08-08).
 *
 * Au plateau, les pièces d'armure se CUMULENT — « [Borin's Armor] may be
 * combined with the helmet and/or shield » (LR p. 7). Chez nous casque, cotte
 * et plates partageaient l'emplacement `armure` : on portait l'un OU l'autre, le
 * plafond de défense tombait à 5 au lieu de 6, et le casque n'était qu'un achat
 * de début de campagne qu'on jetait dès la première vraie armure.
 *
 * Le casque a donc son propre emplacement. Défense maximale : 2 (base) + 1
 * (casque) + 2 (plates) + 1 (bouclier) = **6**, la valeur du plateau.
 *
 * ⚠ Les deux enums doivent bouger ENSEMBLE (`objets.emplacement` dit où la
 * pièce se monte, `inventaire.emplacement` où elle est montée) : n'en migrer
 * qu'un rend le casque inéquipable — l'objet annonce un slot que la ligne
 * d'inventaire ne sait pas écrire. `->change()` est indispensable sur les deux
 * pilotes : MariaDB rejette la valeur hors ENUM, et SQLite (les tests) rend
 * `enum` en `varchar` + contrainte CHECK, qui la rejette tout autant.
 *
 * Les casques DÉJÀ ÉQUIPÉS sont déplacés `armure` → `casque`. Sans ça un héros
 * casqué gardait son casque dans le slot d'armure et ne pouvait plus rien y
 * mettre d'autre, alors même que la règle vient de s'ouvrir.
 */
return new class extends Migration
{
    private const AVEC = ['arme_principale', 'arme_secondaire', 'casque', 'armure', 'sac', 'consommable'];

    private const SANS = ['arme_principale', 'arme_secondaire', 'armure', 'sac', 'consommable'];

    public function up(): void
    {
        $this->enums(self::AVEC);

        DB::table('objets')->where('nom', 'Casque')->update(['emplacement' => 'casque']);

        $casques = DB::table('objets')->where('nom', 'Casque')->pluck('id');

        if ($casques->isNotEmpty()) {
            DB::table('inventaire')
                ->whereIn('objet_id', $casques)
                ->where('emplacement', 'armure')
                ->update(['emplacement' => 'casque']);
        }
    }

    public function down(): void
    {
        $casques = DB::table('objets')->where('nom', 'Casque')->pluck('id');

        if ($casques->isNotEmpty()) {
            DB::table('inventaire')
                ->whereIn('objet_id', $casques)
                ->where('emplacement', 'casque')
                ->update(['emplacement' => 'armure']);
        }

        DB::table('objets')->where('nom', 'Casque')->update(['emplacement' => 'armure']);

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
