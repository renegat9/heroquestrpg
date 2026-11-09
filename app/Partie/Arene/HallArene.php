<?php

declare(strict_types=1);

namespace App\Partie\Arene;

/**
 * MODE ARÈNE, lot T1 — le HALL du tournoi : une carte FIXE (`docs/plan-tournoi-worlds-end.md` §4, T1).
 *
 * Une seule salle de 8 × 9 cases (René, 2026-11-09), sans porte, sans couloir, sans brouillard (la salle 0 est toujours
 * découverte), et deux TUILES DE DÉPART face à face sur la longueur : le Challenger au sud, le Defender au nord (livret
 * F9528 p. 12 : « each team is placed on its own starting tile »).
 *
 * ⚠ La TAILLE vient de René ; la place et la forme des tuiles de départ, elles, sont NON SOURCÉES (deux rangées pleines
 * contre chaque petit côté) : le plan imprimé du hall (p. 21-25) n'est pas numérisé. Les cases de trophée (lot T4) et
 * les coffres (lot T5) n'y sont pas encore.
 *
 * Même forme que la carte d'`AssembleurCarte::assembler()` — mêmes clés, mêmes couches (vides) — pour que
 * `FabriqueGrille::pour()`, `EtatGroupe` et le résolveur la lisent sans un seul cas particulier.
 */
final class HallArene
{
    /**
     * Salle MUR COMPRIS, comme toute salle de carte : le sol intérieur fait (LARGEUR − 2) × (HAUTEUR − 2), soit
     * **8 × 9 cases** — la salle du hall donnée par René (2026-11-09 : « La carte de l'arène est une salle 8x9 »).
     */
    public const LARGEUR_SALLE = 10;

    public const HAUTEUR_SALLE = 11;

    /** Marge de roche autour de la salle (la grille déborde d'une case de chaque côté). */
    private const MARGE = 1;

    /** Profondeur (en rangées) de chaque tuile de départ, collée à son petit côté de la salle. */
    private const PROFONDEUR_TUILE = 2;

    /**
     * La carte du hall.
     *
     * @return array<string, mixed>
     */
    public function carte(): array
    {
        $largeur = self::LARGEUR_SALLE + 2 * self::MARGE;
        $hauteur = self::HAUTEUR_SALLE + 2 * self::MARGE;
        $x0 = self::MARGE;
        $y0 = self::MARGE;

        $cases = array_fill(0, $hauteur, array_fill(0, $largeur, 'm'));
        foreach ($this->sol() as [$x, $y]) {
            $cases[$y][$x] = 's';
        }

        $salle = [
            'x' => $x0, 'y' => $y0, 'largeur' => self::LARGEUR_SALLE, 'hauteur' => self::HAUTEUR_SALLE,
            'theme' => 'arene',
            'mediane_x' => $x0 + intdiv(self::LARGEUR_SALLE, 2), 'mediane_y' => $y0 + intdiv(self::HAUTEUR_SALLE, 2),
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
            'spawn_heros' => $this->tuileDeDepart(sud: true),
            'spawn_monstres' => $this->tuileDeDepart(sud: false),
            // Les tuiles de départ ne sont pas dupliquées sous une autre clé : `spawn_heros` est celle du Challenger,
            // `spawn_monstres` celle du Defender, et rien d'autre ne les lit.
            'spawn_figures_fixes' => [],
        ];
    }

    /**
     * Cases de la tuile de départ, du FRONT (la rangée qui fait face à l'adversaire) vers l'arrière, et dans chaque
     * rangée du centre vers les bords : les premières figures posées font face à l'adversaire, au milieu.
     *
     * @return list<array{x: int, y: int}>
     */
    public function tuileDeDepart(bool $sud): array
    {
        $premierX = self::MARGE + 1;
        $dernierX = self::MARGE + self::LARGEUR_SALLE - 2;
        $premierY = self::MARGE + 1;
        $dernierY = self::MARGE + self::HAUTEUR_SALLE - 2;

        // Rangées du front vers l'arrière.
        $rangees = [];
        for ($i = 0; $i < self::PROFONDEUR_TUILE; $i++) {
            $rangees[] = $sud
                ? $dernierY - self::PROFONDEUR_TUILE + 1 + $i
                : $premierY + self::PROFONDEUR_TUILE - 1 - $i;
        }

        // Colonnes du centre vers les bords, en alternant (8 colonnes : 4, 5, 3, 6, 2, 7, 1, 8 en relatif).
        $milieu = intdiv($premierX + $dernierX, 2);
        $colonnes = [$milieu];
        for ($d = 1; count($colonnes) < $dernierX - $premierX + 1; $d++) {
            foreach ([$milieu + $d, $milieu - $d] as $x) {
                if ($x >= $premierX && $x <= $dernierX) {
                    $colonnes[] = $x;
                }
            }
        }

        $cases = [];
        foreach ($rangees as $y) {
            foreach ($colonnes as $x) {
                $cases[] = ['x' => $x, 'y' => $y];
            }
        }

        return $cases;
    }

    /** @return list<array{0: int, 1: int}> */
    private function sol(): array
    {
        $cases = [];
        for ($y = self::MARGE + 1; $y <= self::MARGE + self::HAUTEUR_SALLE - 2; $y++) {
            for ($x = self::MARGE + 1; $x <= self::MARGE + self::LARGEUR_SALLE - 2; $x++) {
                $cases[] = [$x, $y];
            }
        }

        return $cases;
    }
}
