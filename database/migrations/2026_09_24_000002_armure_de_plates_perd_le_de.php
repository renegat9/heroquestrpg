<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ARMURE DE PLATES — arbitrage de René (2026-09-24), sur la carte OFFICIELLE
 * 2021 : *Plate Mail*, « +2 dés de défense, mais 1 seul dé rouge de mouvement ».
 *
 * `malus_deplacement: 2` (« a 2 square movement penalty ») venait de la
 * conversion FAN Sjeng (`reference/16_armurerie.md` §2.2, « historique »),
 * jamais de la carte officielle, dont le §2.1bis dit qu'elle PRIME sur Sjeng.
 * Au plateau un héros lance DEUX dés de mouvement et la Plate Mail lui en
 * retire UN ; chez nous (base de classe + UN SEUL d6, écart assumé du
 * projet), retirer un dé retire LE SEUL dé — d'où `deplacement_sans_d6: true`
 * (booléen) plutôt qu'un chiffre retranché du total.
 *
 * ⚠ Une migration et pas seulement le seeder : c'est la ligne EXISTANTE du
 * catalogue qui change, et c'est elle que lit la vraie partie — elle n'est
 * PAS jouée sur la base du conteneur ici (René la joue lui-même, après
 * sauvegarde). Ciblée par le nom, sans effet si la pièce n'est pas au
 * catalogue. Aucune ligne d'inventaire n'est touchée : un exemplaire déjà en
 * jeu relit `objets.effet` à chaque calcul de déplacement, il n'a rien à lui
 * de propre à migrer.
 */
return new class extends Migration
{
    private const NOM = 'Armure de plates';

    public function up(): void
    {
        $this->ecrire(['des_defense' => 2, 'deplacement_sans_d6' => true]);
    }

    public function down(): void
    {
        $this->ecrire(['des_defense' => 2, 'malus_deplacement' => 2]);
    }

    /** @param  array<string, mixed>  $effet */
    private function ecrire(array $effet): void
    {
        DB::table('objets')->where('nom', self::NOM)->update([
            'effet' => json_encode($effet, JSON_UNESCAPED_UNICODE),
        ]);
    }
};
