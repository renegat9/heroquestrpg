<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ARC ELFIQUE DE VINDICATION — arbitrage de René (2026-09-16) : « Inflige
 * automatiquement 3 de dommages par flèche sauf si un bouclier noir est tiré sur
 * un dé. 4 flèches, après l'arc est détruit. Elfe seulement. »
 *
 * Remplace la mort instantanée de la carte (`tue_sauf_bouclier_noir`) par
 * `degats_sauf_bouclier_noir: 3`, et retire `des_attaque` : l'arc n'attaque plus
 * que par ses flèches, et il se brise à la dernière (`MoteurCharges`) au lieu de
 * redevenir une arme ordinaire.
 *
 * ⚠ Une migration et pas seulement le seeder : c'est la ligne EXISTANTE du
 * catalogue qui change, et c'est elle que lit la vraie partie. Ciblée par le
 * nom, sans effet si l'arc n'est pas au catalogue. Aucune ligne d'inventaire
 * n'est touchée — les flèches restantes d'un exemplaire (`inventaire.charges`)
 * gardent leur sens.
 */
return new class extends Migration
{
    private const NOM = 'Arc elfique de Vindication';

    public function up(): void
    {
        $this->ecrire([
            'portee' => 'distance', 'inutilisable_adjacent' => true,
            'deux_mains' => true, 'degats_sauf_bouclier_noir' => 3, 'charges' => 4,
        ]);
    }

    public function down(): void
    {
        $this->ecrire([
            'des_attaque' => 2, 'portee' => 'distance', 'inutilisable_adjacent' => true,
            'deux_mains' => true, 'tue_sauf_bouclier_noir' => true, 'charges' => 4,
        ]);
    }

    /** @param  array<string, mixed>  $effet */
    private function ecrire(array $effet): void
    {
        DB::table('objets')->where('nom', self::NOM)->update([
            'effet' => json_encode($effet, JSON_UNESCAPED_UNICODE),
        ]);
    }
};
