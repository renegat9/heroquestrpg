<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\RareteButin;
use App\Models\Objet;
use Illuminate\Database\Eloquent\Builder;

/**
 * LE TIRAGE D'UNE PIÈCE DE BUTIN, en deux temps — UN seul point de passage.
 *
 * D'abord la RARETÉ, pondérée par le niveau moyen du groupe (`RareteButin::tirer()`,
 * la courbe), puis la PIÈCE, uniformément dans cette rareté. Décidé pour le mobilier
 * le 2026-08-17 ; étendu à l'épreuve « Grimoire à demi calciné » le 2026-10-16, qui
 * tirait jusque-là uniformément dans toute la catégorie : un groupe de niveau 1
 * tombait sur un sort de difficulté 3 (rare) bien plus souvent que la courbe ne le
 * permet.
 *
 * Lu par `MoteurMobilier::carteDepuisEntree()` (tables de fouille) et par
 * `ResolveurTour::epreuveParchemin()` (épreuve). Une seconde boucle ici serait une
 * seconde courbe, qui divergerait au prochain rééquilibrage.
 *
 * Le VIVIER reste à l'appelant : c'est lui qui sait quelles catégories sont
 * admissibles, quelles maîtrises sont présentes et que `unique` est exclu. Ce fichier
 * ne lit que la courbe — ce qu'on tire et ce qu'on a le droit de tirer sont deux règles.
 */
final class TirageButin
{
    /**
     * @param  Builder<Objet>  $vivier  les pièces admissibles ici (catégorie, maîtrises, sans `unique`)
     */
    public static function objetSelonRarete(Builder $vivier, int $niveauMoyen): ?Objet
    {
        // Les raretés PRÉSENTES dans le vivier seulement : un râtelier sans arme rare
        // ne doit pas rendre « rien » une fois sur trois (`RareteButin::tirer()`).
        $disponibles = (clone $vivier)->distinct()->pluck('rarete')
            ->map(fn ($r) => (string) $r)->all();

        $rarete = RareteButin::tirer($disponibles, $niveauMoyen);

        return $rarete === null
            ? null
            : (clone $vivier)->where('rarete', $rarete)->inRandomOrder()->first();
    }
}
