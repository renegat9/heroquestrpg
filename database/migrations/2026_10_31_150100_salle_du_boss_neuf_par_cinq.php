<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La salle du boss a NEUF colonnes et CINQ rangées de sol, au lieu de sept et quatre (2026-10-31).
 *
 * La tuile de thème `boss` (la dernière salle de la quête finale, `AssembleurCarte::assembler()`) était 7 × 4 de sol, avec la
 * porte au milieu du mur du haut (« p »). Un Bloomrot Scion 4×4 y tenait sur les quatre rangées : son emprise couvrait donc
 * forcément la case du parvis de la porte, interdit (§2.12 quater). Par construction, aucune place pour lui dans cette salle.
 *
 * Avec cinq rangées, le 4×4 tient sur les rangées 2 à 5, hors du parvis, et la rangée 1 reste libre pour les héros qui
 * entrent. Neuf colonnes laissent place au mobilier de la salle ET à quatre cases libres au contact de la figure
 * (`AssembleurCarte::ancresFiguresFixes()`) : sur 60 graines d'essai, 24 salles du boss lui faisaient place en 7 × 5 (sans
 * les quatre cases au contact) et 37 en 9 × 5 (mesure du 2026-11-05, avant l'exigence des quatre cases).
 * Les quêtes DÉJÀ assemblées gardent leur carte (`cartes.grille`) : seule la prochaine salle du boss change.
 *
 * ⚠ Mise à jour de la LIGNE du catalogue, jamais un `db:seed` (le seeder de tuiles vide et recrée la table) : même
 * forme que `TuileSeeder`, qui reste la source de la même grille.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const ANCIENNE = [
        'mmmmpmmmm',
        'msssssssm',
        'msssssssm',
        'msssssssm',
        'msssssssm',
        'mmmmmmmmm',
    ];

    /** @var list<string> */
    private const NOUVELLE = [
        'mmmmmpmmmmm',
        'msssssssssm',
        'msssssssssm',
        'msssssssssm',
        'msssssssssm',
        'msssssssssm',
        'mmmmmmmmmmm',
    ];

    public function up(): void
    {
        DB::table('tuiles')->where('type', 'salle')->where('theme', 'boss')
            ->update(['grille' => json_encode(self::grille(self::NOUVELLE))]);
    }

    public function down(): void
    {
        DB::table('tuiles')->where('type', 'salle')->where('theme', 'boss')
            ->update(['grille' => json_encode(self::grille(self::ANCIENNE))]);
    }

    /** @param  list<string>  $lignes */
    private static function grille(array $lignes): array
    {
        return [
            'largeur' => strlen($lignes[0]),
            'hauteur' => count($lignes),
            'cases' => array_map(str_split(...), $lignes),
        ];
    }
};
