<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * *Chant runique* (elfe) et *Appel de la forêt* (druide) : le sort qui ABAT un
 * monstre reste disponible (René, 2026-09-25). Ils rendaient un sort épuisé à
 * chaque monstre abattu, par n'importe quel moyen, sur un bouclier noir —
 * `regain_sort` + `sort_non_epuise_sur_bouclier_noir`. Le dé disparaît ; c'est
 * désormais le sort lui-même qui doit tuer (`garde_sort_qui_tue`, lu par
 * `ResolveurTour::preserverSort()`).
 *
 * ⚠ Migration et non re-seed : ce sont les LIGNES EXISTANTES que les héros
 * déjà dotés de ces talents relisent — le lien `personnage_competences`
 * pointe sur la ligne, qui change donc d'effet pour eux aussi, sans toucher à
 * aucun personnage. Ciblée par nom, sans effet si le talent est absent.
 */
return new class extends Migration
{
    private const NOMS = ['Chant runique', 'Appel de la forêt'];

    public function up(): void
    {
        DB::table('competences')->whereIn('nom', self::NOMS)->update([
            'description' => 'Quand un de tes sorts abat un monstre, il reste disponible : tu pourras le relancer.',
            'effet' => json_encode(['mecanique' => 'garde_sort_qui_tue'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function down(): void
    {
        DB::table('competences')->whereIn('nom', self::NOMS)->update([
            'description' => 'Chaque monstre que tu abats te laisse une chance de récupérer un sort épuisé : un bouclier noir sur 1 dé de combat.',
            'effet' => json_encode([
                'mecanique' => 'regain_sort', 'regain' => 'monstre_vaincu',
                'sort_non_epuise_sur_bouclier_noir' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
};
