<?php

declare(strict_types=1);

namespace App\Partie\Arene;

/**
 * MODE ARÈNE, lot T1 — le HALL du tournoi : une carte FIXE, celle du livret (`docs/plan-tournoi-worlds-end.md` §4, T1).
 *
 * SOURCE : le plan « Playing the Tournament Outside of Quests », livret F9528 p. 15, relu sur le rendu de la page
 * (`reference/18_extensions.md` §Mode tournoi). Une salle centrale de **9 colonnes × 8 rangées** — la taille donnée par
 * René le 2026-11-09 —, et de chaque côté une **tuile de départ de 4 × 4** alignée sur les rangées 3 à 6 de la salle :
 * le Challenger à l'ouest, le Defender à l'est. Deux cases de **gravats** (case bloquée) dans la salle.
 *
 * Ce qui en est rendu, et comment :
 * - Une seule `salle` couvrant tout le hall (mur compris) : pas de brouillard (la salle 0 est toujours découverte), et
 *   `Salles::indexDe()` répond partout la même chose. Le sol dessine la croix ; tout le reste est de la roche.
 * - Les gravats sont de la roche (`m`) : infranchissables, comme une case bloquée.
 * - ⚠ **Écart nommé — la double porte.** Au plateau, une tuile de départ ne touche la salle que par une double porte
 *   (rangées 4 et 5), le reste de la jonction est un mur TRACÉ SUR L'ARÊTE. Notre grille n'a de mur qu'en CASES : sans
 *   ajouter une colonne de roche (qui fausserait toutes les distances du livret), la jonction est ouverte sur les
 *   quatre rangées de la tuile. Les portes, elles, ne manquent pas : « at the start of a battle, the doors to the
 *   tournament hall raise » (p. 12) — elles sont levées pendant tout le combat.
 * - Les cases de trophée et les coffres du plan entrent avec leurs lecteurs (lots T4 et T5), jamais avant.
 *
 * Même forme que la carte d'`AssembleurCarte::assembler()` — mêmes clés, mêmes couches (vides) — pour que
 * `FabriqueGrille::pour()`, `EtatGroupe` et le résolveur la lisent sans un seul cas particulier.
 */
final class HallArene
{
    /** La salle centrale du plan p. 15 : 9 colonnes × 8 rangées (et René : « 8 de haut par 9 de large »). */
    public const LARGEUR_SALLE = 9;

    public const HAUTEUR_SALLE = 8;

    /** Une tuile de départ : 4 × 4, alignée sur les rangées 3 à 6 de la salle (index 2 à 5). */
    public const COTE_TUILE = 4;

    private const PREMIERE_RANGEE_TUILE = 2;

    /**
     * Les gravats du plan, en (colonne, rangée) de la salle centrale, depuis 0 : (3,5) et (7,4) dans la numérotation
     * 1-9 / 1-8 de `reference/18_extensions.md`.
     */
    public const GRAVATS = [[2, 4], [6, 3]];

    /** Marge de roche autour du hall. */
    private const MARGE = 1;

    /**
     * La carte du hall.
     *
     * @return array<string, mixed>
     */
    public function carte(): array
    {
        $largeur = 2 * self::MARGE + 2 * self::COTE_TUILE + self::LARGEUR_SALLE;
        $hauteur = 2 * self::MARGE + self::HAUTEUR_SALLE;

        $cases = array_fill(0, $hauteur, array_fill(0, $largeur, 'm'));
        foreach ($this->sol() as [$x, $y]) {
            $cases[$y][$x] = 's';
        }
        foreach (self::GRAVATS as [$c, $r]) {
            [$x, $y] = $this->caseDeLaSalle($c, $r);
            $cases[$y][$x] = 'm';
        }

        $salle = [
            'x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur,
            'theme' => 'arene',
            'mediane_x' => intdiv($largeur, 2), 'mediane_y' => intdiv($hauteur, 2),
        ];

        return [
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'cases' => $cases,
            'salles' => [$salle],
            'portes' => [],
            'aretes' => [],
            'passage_secret' => false,
            'leviers' => [],
            'pieges' => [],
            'mobilier' => [],
            'epreuves' => [],
            'terrain' => [],
            'dangers' => [],
            // Pas d'escalier : on ne quitte pas le hall, on y gagne ou on y tombe. `null` est le cas déjà prévu par
            // tous les lecteurs (`Carte::casesEscalier()`, `MenuMoteur`).
            'escalier' => null,
            // `spawn_heros` EST la tuile du Challenger, `spawn_monstres` celle du Defender : aucune autre clé ne les
            // duplique, et rien d'autre ne les lit.
            'spawn_heros' => $this->tuileDeDepart(ouest: true),
            'spawn_monstres' => $this->tuileDeDepart(ouest: false),
            'spawn_figures_fixes' => [],
        ];
    }

    /**
     * Les 16 cases d'une tuile de départ, de la colonne qui touche la salle (le FRONT) vers l'arrière, et dans chaque
     * colonne des rangées de la porte (au milieu) vers les bords : les premières figures posées font face à la porte.
     *
     * @return list<array{x: int, y: int}>
     */
    public function tuileDeDepart(bool $ouest): array
    {
        $premierX = $ouest ? self::MARGE : self::MARGE + self::COTE_TUILE + self::LARGEUR_SALLE;
        $colonnes = range($premierX, $premierX + self::COTE_TUILE - 1);
        if ($ouest) {
            $colonnes = array_reverse($colonnes); // le front est la colonne de droite
        }

        $premierY = self::MARGE + self::PREMIERE_RANGEE_TUILE;
        $milieu = $premierY + intdiv(self::COTE_TUILE - 1, 2);
        $rangees = [$milieu];
        for ($d = 1; count($rangees) < self::COTE_TUILE; $d++) {
            foreach ([$milieu + $d, $milieu - $d] as $y) {
                if ($y >= $premierY && $y < $premierY + self::COTE_TUILE) {
                    $rangees[] = $y;
                }
            }
        }

        $cases = [];
        foreach ($colonnes as $x) {
            foreach ($rangees as $y) {
                $cases[] = ['x' => $x, 'y' => $y];
            }
        }

        return $cases;
    }

    /**
     * La case de la grille qui correspond à (colonne, rangée) de la salle centrale, depuis 0.
     *
     * @return array{0: int, 1: int}
     */
    public function caseDeLaSalle(int $colonne, int $rangee): array
    {
        return [self::MARGE + self::COTE_TUILE + $colonne, self::MARGE + $rangee];
    }

    /** @return list<array{0: int, 1: int}> */
    private function sol(): array
    {
        $cases = [];

        for ($r = 0; $r < self::HAUTEUR_SALLE; $r++) {
            for ($c = 0; $c < self::LARGEUR_SALLE; $c++) {
                $cases[] = $this->caseDeLaSalle($c, $r);
            }
        }

        foreach ([true, false] as $ouest) {
            foreach ($this->tuileDeDepart($ouest) as $case) {
                $cases[] = [$case['x'], $case['y']];
            }
        }

        return $cases;
    }
}
