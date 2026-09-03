<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les CARTES OFFICIELLES d'artefact (© 2021-2023 Hasbro, photos de René,
 * 2026-09-03) remplacent le paquet fan `sjeng-artefacts.pdf`. Cinq de nos
 * artefacts n'ont AUCUNE carte : ils sortent du catalogue.
 *
 * ⚠ `ObjetSeeder` écrit en `updateOrCreate` et ne purge JAMAIS — retirer les
 * lignes du seeder ne suffit donc pas, elles survivraient en base et
 * continueraient d'être tirées par les coffres. Même précaution que la
 * migration qui a supprimé *Maîtrise lourde* et *Poigne de forgeron* le
 * 2026-08-22.
 *
 * ⚠ Les lignes d'INVENTAIRE partent avec : un artefact retiré du catalogue mais
 * laissé dans un sac deviendrait une ligne orpheline — ni équipable, ni
 * revendable, ni affichable, et sans la moindre erreur pour le dire.
 *
 * ⚠ La RÈGLE du Sceptre de Mémoire n'est pas perdue avec l'objet : elle est
 * reversée sur les talents `regain_sort` (arbitrage de René), où le bouclier
 * noir bride un regain qui se déclenchait jusqu'ici à chaque monstre abattu.
 */
return new class extends Migration
{
    /** Aucune carte officielle ne les couvre (voir `config/cartes.php`). */
    private const HORS_SOURCE = [
        'Capuche du Magister',
        'Runes naines',
        'Sceptre de Mémoire',
        'Baguette de Galimatias',
        'Parchemin de Sorts',
    ];

    public function up(): void
    {
        $ids = DB::table('objets')->whereIn('nom', self::HORS_SOURCE)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('inventaire')->whereIn('objet_id', $ids)->delete();
        DB::table('objets')->whereIn('id', $ids)->delete();
    }

    /**
     * Irréversible, et c'est assumé : re-semer des artefacts que plus aucune
     * carte ne source reviendrait à réintroduire l'invention qu'on retire.
     */
    public function down(): void {}
};
