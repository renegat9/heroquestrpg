<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le Bloomrot Scion occupe QUATRE cases sur quatre (René, 2026-10-31 : « Oui allons-y avec le 4x4 »).
 *
 * « Use the grasping vines tile… » n'est pas le sujet : le jeton est la TUILE de la carte de la quête 14 (F9907 PDF 20,
 * « The Bloomrot Scion »), un carré de quatre cases de côté, « the hulking form of the maw » que le texte désigne. L'emprise
 * d'une figure suit son jeton de livret (même règle que `2026_10_29_100000_emprises_figures_selon_jetons`).
 *
 * ⚠ L'emprise se lit dans le CATALOGUE à chaque tour (`Monstre::emprise()`), pas sur l'instance : une figure déjà posée
 * grandirait avec cette migration. Vérifié sur la vraie base le 2026-10-31 : aucune instance de Scion, aucune quête en
 * cours. La salle du boss, qui doit la recevoir, est agrandie par `2026_10_31_150100_salle_du_boss_neuf_par_cinq`.
 */
return new class extends Migration
{
    private const NOM = 'Scion Fleur-Pourrie';

    public function up(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)->update(['grande_taille' => json_encode(['l' => 4, 'h' => 4])]);
    }

    public function down(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)->update(['grande_taille' => null]);
    }
};
