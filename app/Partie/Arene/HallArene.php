<?php

declare(strict_types=1);

namespace App\Partie\Arene;

/**
 * MODE ARÈNE, lot T1 — le HALL du tournoi : une carte FIXE (`docs/plan-tournoi-worlds-end.md` §4, T1).
 *
 * Une seule salle, sans porte, sans couloir, sans brouillard (la salle 0 est toujours découverte), et deux TUILES DE
 * DÉPART face à face : le Challenger à l'ouest, le Defender à l'est (livret F9528 p. 12 : « each team is placed on its
 * own starting tile »).
 *
 * ⚠ GÉOMÉTRIE NON SOURCÉE, dessinée par nous. Le plan imprimé du hall (quêtes 1 à 3, p. 21-25) n'est pas numérisé : ni
 * ses dimensions, ni la place des tuiles de départ, ni celle des cases de trophée et des coffres ne sont dans
 * `reference/18_extensions.md`. Ce hall est donc une proposition à jouer, comme toutes nos cartes générées. Les cases
 * de trophée (lot T4) et les coffres (lot T5) n'y sont pas encore.
 *
 * Même forme que la carte d'`AssembleurCarte::assembler()` — mêmes clés, mêmes couches (vides) — pour que
 * `FabriqueGrille::pour()`, `EtatGroupe` et le résolveur la lisent sans un seul cas particulier.
 */
final class HallArene
{
    /** Salle MUR COMPRIS, comme toute salle de carte : le sol intérieur fait (LARGEUR − 2) × (HAUTEUR − 2). */
    public const LARGEUR_SALLE = 20;

    public const HAUTEUR_SALLE = 14;

    /** Marge de roche autour de la salle (la grille déborde d'une case de chaque côté). */
    private const MARGE = 1;

    /** Colonnes (relatives au bord intérieur) des deux tuiles de départ, et leur profondeur. */
    private const RECUL_TUILE = 1;

    private const PROFONDEUR_TUILE = 3;

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
            'spawn_heros' => $this->tuileDeDepart(ouest: true),
            'spawn_monstres' => $this->tuileDeDepart(ouest: false),
            // Les tuiles de départ ne sont pas dupliquées sous une autre clé : `spawn_heros` est celle du Challenger,
            // `spawn_monstres` celle du Defender, et rien d'autre ne les lit.
            'spawn_figures_fixes' => [],
        ];
    }

    /**
     * Cases de la tuile de départ, de la ligne médiane vers les bords, puis du front vers l'arrière : les premières
     * figures posées font face à l'adversaire, au centre.
     *
     * @return list<array{x: int, y: int}>
     */
    public function tuileDeDepart(bool $ouest): array
    {
        $interieurX = self::MARGE + 1;
        $dernierX = self::MARGE + self::LARGEUR_SALLE - 2;
        $interieurY = self::MARGE + 1;
        $dernierY = self::MARGE + self::HAUTEUR_SALLE - 2;
        $milieuY = intdiv($interieurY + $dernierY, 2);

        // Colonnes du front (côté adversaire) vers l'arrière.
        $colonnes = [];
        for ($i = 0; $i < self::PROFONDEUR_TUILE; $i++) {
            $colonnes[] = $ouest
                ? $interieurX + self::RECUL_TUILE + self::PROFONDEUR_TUILE - 1 - $i
                : $dernierX - self::RECUL_TUILE - self::PROFONDEUR_TUILE + 1 + $i;
        }

        // Lignes de la médiane vers les bords, en alternant : 0, +1, −1, +2, −2…
        $lignes = [$milieuY];
        for ($d = 1; count($lignes) < $dernierY - $interieurY + 1; $d++) {
            foreach ([$milieuY + $d, $milieuY - $d] as $y) {
                if ($y >= $interieurY && $y <= $dernierY) {
                    $lignes[] = $y;
                }
            }
        }

        // Tuile de 6 lignes autour de la médiane : 3 × 6 = 18 cases, assez pour 4 héros et leurs alliés, ou une
        // équipe de monstres.
        $lignes = array_slice($lignes, 0, 6);

        $cases = [];
        foreach ($colonnes as $x) {
            foreach ($lignes as $y) {
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
