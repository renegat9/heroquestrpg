<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emprise des figures alignée sur les jetons des livrets (René, 2026-10-29 : « Oui »).
 *
 * Un livret qui dessine des jetons OVALES les réserve aux figures de 2 cases : sur la carte de l'Ogre Horde
 * (F9528 PDF 36), chaque ovale d'ogre couvre exactement 2 cases et chaque jeton rond une seule.
 *  - Ogre (Mage of the Mirror) et Ogre guerrier (Ogre Horde) : jeton ROND du diamètre d'une case, à côté
 *    d'ovales de 2 cases dans la même légende → 1×1 (René revient sur les 2 cases de l'Ogre du 2026-10-04).
 *  - Rampant putride (Blightcrawler) et Deathspinner (même figurine) : jeton OVALE de la taille de ceux du
 *    Serpent et du Singe géant (F9907 PDF 6) → 1×2.
 * La Horreur des Glaces garde ses 2 cases : son livret n'a aucun ovale et son texte la nomme grand monstre.
 *
 * ⚠ L'emprise se lit dans le CATALOGUE à chaque tour (`Monstre::emprise()`), pas sur l'instance : une figure
 * déjà posée change de taille avec cette migration. Vérifié sur la vraie base le 2026-10-29 : aucune instance
 * de ces quatre monstres. Une figure qui rétrécit libère une case ; une figure qui grandit sur une case prise
 * chevaucherait son voisin — c'est pourquoi la vérification a précédé.
 */
return new class extends Migration
{
    private const UNE_CASE = ['Ogre', 'Ogre guerrier'];

    private const DEUX_CASES = ['Rampant putride', 'Deathspinner la Reine du Fléau'];

    public function up(): void
    {
        DB::table('monstres')->whereIn('nom_base', self::UNE_CASE)->update(['grande_taille' => null]);
        DB::table('monstres')->whereIn('nom_base', self::DEUX_CASES)->update(['grande_taille' => json_encode(['l' => 1, 'h' => 2])]);
    }

    public function down(): void
    {
        DB::table('monstres')->whereIn('nom_base', self::UNE_CASE)->update(['grande_taille' => json_encode(['l' => 1, 'h' => 2])]);
        DB::table('monstres')->whereIn('nom_base', self::DEUX_CASES)->update(['grande_taille' => null]);
    }
};
