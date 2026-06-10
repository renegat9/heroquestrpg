<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\GabaritQuete;
use App\Models\Piege;
use App\Models\Tuile;
use RuntimeException;

/**
 * Assemblage procédural de la carte d'une quête depuis la bibliothèque de
 * tuiles seedées (doc 06 §3) : salles posées EN CHAÎNE, reliées par des
 * couloirs droits — algorithme volontairement simple et robuste pour le
 * prototype vertical (doc 00 §9).
 *
 * Algorithme :
 *  1. nb de salles = minimum du gabarit (structure.salles.min, plancher 2) ;
 *  2. tuiles « salle » génériques prises en boucle dans l'ordre du catalogue ;
 *     si le gabarit prévoit une rencontre finale, la DERNIÈRE salle utilise
 *     la tuile de thème « boss » ;
 *  3. les salles sont posées de gauche à droite, centrées verticalement sur
 *     une même ligne médiane ; entre deux salles, un couloir droit de
 *     LONGUEUR_COULOIR cases est creusé sur cette ligne ;
 *  4. les cases « p » (porte possible) des tuiles sont refermées en mur,
 *     puis une porte est OUVERTE explicitement de chaque côté des couloirs ;
 *  5. pièges (structure.pieges.min) placés au milieu des couloirs ;
 *  6. spawns : héros dans la première salle, monstres dans les salles
 *     suivantes — la liste des spawns monstres commence par la DERNIÈRE
 *     salle (la rencontre finale y est placée en premier).
 *
 * Codes de case (TuileSeeder) : m = mur, s = sol, p = porte.
 * Toutes les tuiles « salle » seedées ont un intérieur en sol sur leur ligne
 * médiane, ce qui garantit que chaque porte ouvre sur du sol.
 */
final class AssembleurCarte
{
    public const LONGUEUR_COULOIR = 3;

    public const NB_SALLES_MIN = 2;

    /** Nb max de positions de spawn retournées (héros / monstres). */
    public const MAX_SPAWNS_HEROS = 8;

    /**
     * @return array{
     *   largeur: int, hauteur: int,
     *   cases: list<list<string>>,
     *   salles: list<array{x: int, y: int, largeur: int, hauteur: int, theme: string}>,
     *   portes: list<array{x: int, y: int}>,
     *   pieges: list<array{x: int, y: int, piege_id: int|null, etat: string}>,
     *   spawn_heros: list<array{x: int, y: int}>,
     *   spawn_monstres: list<array{x: int, y: int}>
     * }
     */
    public function assembler(GabaritQuete $gabarit): array
    {
        $structure = $gabarit->structure ?? [];
        $tuiles = $this->choisirTuiles($structure);

        // --- Dimensions du canevas -------------------------------------
        $hauteur = max(array_map(fn (Tuile $t) => (int) $t->grille['hauteur'], $tuiles));
        $ligneMediane = intdiv($hauteur, 2);
        $largeur = array_sum(array_map(fn (Tuile $t) => (int) $t->grille['largeur'], $tuiles))
            + self::LONGUEUR_COULOIR * (count($tuiles) - 1);

        $cases = array_fill(0, $hauteur, array_fill(0, $largeur, 'm'));
        $salles = [];
        $portes = [];

        // --- Pose des salles en chaîne ----------------------------------
        $x = 0;
        foreach ($tuiles as $i => $tuile) {
            $w = (int) $tuile->grille['largeur'];
            $h = (int) $tuile->grille['hauteur'];
            $y = $ligneMediane - intdiv($h, 2);

            foreach ($tuile->grille['cases'] as $r => $ligne) {
                foreach ($ligne as $c => $case) {
                    // Les portes « possibles » de la tuile sont refermées :
                    // les portes réelles sont ouvertes sur les couloirs.
                    $cases[$y + $r][$x + $c] = $case === 'p' ? 'm' : $case;
                }
            }

            $salles[] = ['x' => $x, 'y' => $y, 'largeur' => $w, 'hauteur' => $h, 'theme' => $tuile->theme];

            if ($i > 0) {
                $cases[$ligneMediane][$x] = 'p'; // porte ouest
                $portes[] = ['x' => $x, 'y' => $ligneMediane];
            }

            if ($i < count($tuiles) - 1) {
                $cases[$ligneMediane][$x + $w - 1] = 'p'; // porte est
                $portes[] = ['x' => $x + $w - 1, 'y' => $ligneMediane];

                for ($cx = $x + $w; $cx < $x + $w + self::LONGUEUR_COULOIR; $cx++) {
                    $cases[$ligneMediane][$cx] = 's'; // couloir creusé
                }
            }

            $x += $w + self::LONGUEUR_COULOIR;
        }

        return [
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'cases' => $cases,
            'salles' => $salles,
            'portes' => $portes,
            'pieges' => $this->placerPieges($structure, $salles, $ligneMediane),
            'spawn_heros' => array_slice($this->interieur($cases, $salles[0]), 0, self::MAX_SPAWNS_HEROS),
            'spawn_monstres' => $this->spawnsMonstres($cases, $salles),
        ];
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return list<Tuile>
     */
    private function choisirTuiles(array $structure): array
    {
        $nbSalles = max(self::NB_SALLES_MIN, (int) data_get($structure, 'salles.min', 3));

        $generiques = Tuile::query()
            ->where('type', 'salle')
            ->where('theme', 'generique')
            ->orderBy('id')
            ->get()
            ->values();

        if ($generiques->isEmpty()) {
            throw new RuntimeException('Aucune tuile « salle » en base — seeder les tuiles avant d\'assembler une carte.');
        }

        $tuiles = [];
        for ($i = 0; $i < $nbSalles; $i++) {
            $tuiles[] = $generiques[$i % $generiques->count()];
        }

        // Rencontre finale (sous-boss / boss) : la dernière salle est l'antre.
        if (isset($structure['rencontre_finale'])) {
            $boss = Tuile::query()
                ->where('type', 'salle')
                ->where('theme', 'boss')
                ->orderBy('id')
                ->first();

            if ($boss !== null) {
                $tuiles[$nbSalles - 1] = $boss;
            }
        }

        return $tuiles;
    }

    /**
     * Pièges du gabarit (structure.pieges.min), un par couloir, au milieu —
     * le premier piège du catalogue sert de bloc d'effet (l'IA habillera).
     *
     * Cycle de vie (doc 10 §2) : chaque piège démarre `cache`, puis passe à
     * `detecte` (fouille / Œil du mineur), `desarme`, ou `declenche` — l'état
     * vit ici, dans la grille JSON de la carte de la quête (MoteurPieges).
     *
     * @param  array<string, mixed>  $structure
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int, theme: string}>  $salles
     * @return list<array{x: int, y: int, piege_id: int|null, etat: string}>
     */
    private function placerPieges(array $structure, array $salles, int $ligneMediane): array
    {
        $nbCouloirs = count($salles) - 1;
        $nbPieges = min((int) data_get($structure, 'pieges.min', 0), $nbCouloirs);
        $piegeId = Piege::query()->orderBy('id')->value('id');

        $pieges = [];
        for ($i = 0; $i < $nbPieges; $i++) {
            $pieges[] = [
                'x' => $salles[$i]['x'] + $salles[$i]['largeur'] + intdiv(self::LONGUEUR_COULOIR, 2),
                'y' => $ligneMediane,
                'piege_id' => $piegeId,
                'etat' => 'cache',
            ];
        }

        return $pieges;
    }

    /**
     * Cases de sol intérieures d'une salle (ordre ligne par ligne).
     *
     * @param  list<list<string>>  $cases
     * @param  array{x: int, y: int, largeur: int, hauteur: int}  $salle
     * @return list<array{x: int, y: int}>
     */
    private function interieur(array $cases, array $salle): array
    {
        $positions = [];

        for ($r = 0; $r < $salle['hauteur']; $r++) {
            for ($c = 0; $c < $salle['largeur']; $c++) {
                if ($cases[$salle['y'] + $r][$salle['x'] + $c] === 's') {
                    $positions[] = ['x' => $salle['x'] + $c, 'y' => $salle['y'] + $r];
                }
            }
        }

        return $positions;
    }

    /**
     * Spawns de monstres : dernière salle d'abord (rencontre finale), puis
     * en remontant vers l'entrée — jamais dans la salle des héros.
     *
     * @param  list<list<string>>  $cases
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int, theme: string}>  $salles
     * @return list<array{x: int, y: int}>
     */
    private function spawnsMonstres(array $cases, array $salles): array
    {
        $positions = [];

        for ($i = count($salles) - 1; $i >= 1; $i--) {
            foreach ($this->interieur($cases, $salles[$i]) as $position) {
                $positions[] = $position;
            }
        }

        return $positions;
    }
}
