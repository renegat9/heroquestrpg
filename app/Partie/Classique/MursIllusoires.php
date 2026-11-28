<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Grille;
use Illuminate\Validation\ValidationException;

/**
 * LE MUR ILLUSOIRE — lot E2, *Wizards of Morcar* q. 3 D (G1504 p. 25) : « If a hero touches/strikes the wall […] “When you reach
 * out to the wall, it flutters and dissipates. It is an illusion.” The illusion wall can't be found by searching but can be
 * walked through. »
 *
 * `blocs[].illusoire: true` : un bloc de l'auteur, de la roche à la carte (il bloque la marche et la vue, aucune fouille ne
 * le trouve) jusqu'à ce qu'un héros à son CONTACT le touche — une option gratuite (`toucher_mur_illusoire`, créneau
 * `interaction`) que le résolveur revalide contre `touchables()`. Touché, il se dissipe : la case redevient du sol
 * (`grille.cases`, état durable), et on peut y passer.
 */
final class MursIllusoires
{
    /**
     * Les murs illusoires encore debout que ce héros touche (contact orthogonal, `Grille::sontAdjacentes()`).
     *
     * @return list<array{x: int, y: int}>
     */
    public function touchables(Quete $quete, EtatPersonnageQuete $etat): array
    {
        if (! $quete->estConstruite() || $etat->tombe || $etat->position_x === null) {
            return [];
        }
        $cases = (array) ($quete->carte?->grille['cases'] ?? []);
        $murs = [];
        foreach ((array) ($quete->documentConstruit()['blocs'] ?? []) as $bloc) {
            if (($bloc['illusoire'] ?? false) !== true) {
                continue;
            }
            $x = (int) $bloc['x'];
            $y = (int) $bloc['y'];
            if (($cases[$y][$x] ?? null) === 'm'
                && Grille::sontAdjacentes((int) $etat->position_x, (int) $etat->position_y, $x, $y, carte: $quete->carte)) {
                $murs[] = ['x' => $x, 'y' => $y];
            }
        }

        return $murs;
    }

    /** @return array<string, mixed> le résultat moteur `mur_illusoire` */
    public function toucher(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $x, int $y): array
    {
        if (! in_array(['x' => $x, 'y' => $y], $this->touchables($quete, $etat), true)) {
            throw ValidationException::withMessages(['option_id' => 'Aucun mur à toucher ici.']);
        }
        $carte = $quete->carte;
        $grille = (array) $carte->grille;
        $grille['cases'][$y][$x] = 's';
        $carte->update(['grille' => $grille]);

        return ['type' => 'mur_illusoire', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom, 'x' => $x, 'y' => $y];
    }
}
