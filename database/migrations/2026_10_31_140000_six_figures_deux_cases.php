<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Six figures occupent DEUX cases (René, 2026-10-31 : « Continue avec les correctifs »), chacune sur le jeton OVALE de son livret :
 *  - Doralf : l'ovale de l'Ogre Commander, carte de la quête 2 (F9528 PDF 22), « Doralf must be one of the monsters » (PDF 23) ;
 *  - Gruzbella Hammerhand, Déterminée, Imprudente : l'ovale de l'Ogre Champion, carte de la quête 3 (F9528 PDF 24), « do not
 *    remove the miniature » (PDF 25) ;
 *  - Demonspider et Demonape : « place a Blightcrawler / Giant Ape miniature in the nearest open square to her » (F9907 PDF 18).
 *
 * L'emprise d'une figure suit son jeton de livret (même règle que `2026_10_29_100000_emprises_figures_selon_jetons`, le Golem
 * et Magrian). Gretzl reste 1×1 : sa mue en Demonspider (1×2) passe par `MoteurSynchroformes::ancrer()`.
 *
 * ⚠ L'emprise se lit dans le CATALOGUE à chaque tour (`Monstre::emprise()`), pas sur l'instance : une figure déjà posée
 * grandirait avec cette migration. Vérifié sur la vraie base le 2026-10-31 : aucune instance de ces six, aucune quête en cours.
 */
return new class extends Migration
{
    private const NOMS = [
        'Doralf',
        'Gruzbella Hammerhand',
        'Gruzbella Déterminée',
        'Gruzbella Imprudente',
        'Demonspider',
        'Demonape',
    ];

    public function up(): void
    {
        DB::table('monstres')->whereIn('nom_base', self::NOMS)->update(['grande_taille' => json_encode(['l' => 1, 'h' => 2])]);
    }

    public function down(): void
    {
        DB::table('monstres')->whereIn('nom_base', self::NOMS)->update(['grande_taille' => null]);
    }
};
