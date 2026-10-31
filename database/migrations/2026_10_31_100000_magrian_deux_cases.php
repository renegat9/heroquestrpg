<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Magrian, le Dread Wraith, occupe DEUX cases (René, 2026-10-31 : « Oui 2 cases avec son token »).
 *
 * La planche « Design Your Own Quest Adventures » de Rise of the Dread Moon (F6646 PDF 20) dessine le Dread Wraith en
 * OVALE, à côté des jetons ronds de Sir Ragnar et du Stranger. L'emprise d'une figure suit son jeton de livret (même
 * règle que `2026_10_29_100000_emprises_figures_selon_jetons` et le Golem, `2026_10_29_200000_golem_coeur_de_forge_deux_cases`).
 *
 * ⚠ L'emprise se lit dans le CATALOGUE à chaque tour (`Monstre::emprise()`), pas sur l'instance : une figure déjà posée
 * grandirait avec cette migration. Vérifié sur la vraie base le 2026-10-31 : aucune instance de Magrian.
 */
return new class extends Migration
{
    private const NOM = 'Magrian';

    public function up(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)->update(['grande_taille' => json_encode(['l' => 1, 'h' => 2])]);
    }

    public function down(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)->update(['grande_taille' => null]);
    }
};
