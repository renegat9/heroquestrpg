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
 *     puis une porte est PERCÉE de chaque côté des couloirs, sur chacune de
 *     leurs rangées, à l'état `fermee` (on l'ouvre en jeu — E2) ;
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

    /**
     * Largeur des couloirs en cases (correctifs F) : 2 = deux figurines de front
     * (on ne se bouscule plus en file indienne). Les rangées retenues sont la
     * ligne médiane et celle JUSTE AU-DESSUS (voir rangeesCouloir) : toutes les
     * tuiles seedées ont du sol sur ces deux rangées à l'intérieur, donc chaque
     * porte percée y ouvre bien sur du sol (la rangée sous la médiane, elle, est
     * un mur plein sur la petite tuile 4×4).
     */
    public const LARGEUR_COULOIR = 2;

    public const NB_SALLES_MIN = 2;

    /** Nb max de positions de spawn retournées (héros / monstres). */
    public const MAX_SPAWNS_HEROS = 8;

    /**
     * @return array{
     *   largeur: int, hauteur: int,
     *   cases: list<list<string>>,
     *   salles: list<array{x: int, y: int, largeur: int, hauteur: int, theme: string}>,
     *   portes: list<array{x: int, y: int, etat: string, verrou?: array<string, mixed>, revele?: bool}>,
     *   pieges: list<array{x: int, y: int, piege_id: int|null, etat: string}>,
     *   spawn_heros: list<array{x: int, y: int}>,
     *   spawn_monstres: list<array{x: int, y: int}>
     * }
     */
    public function assembler(GabaritQuete $gabarit, int $graine = 0): array
    {
        $structure = $gabarit->structure ?? [];
        $tuiles = $this->choisirTuiles($structure, $graine);

        // --- Dimensions du canevas -------------------------------------
        $hauteur = max(array_map(fn (Tuile $t) => (int) $t->grille['hauteur'], $tuiles));
        $ligneMediane = intdiv($hauteur, 2);
        $largeur = array_sum(array_map(fn (Tuile $t) => (int) $t->grille['largeur'], $tuiles))
            + self::LONGUEUR_COULOIR * (count($tuiles) - 1);

        $cases = array_fill(0, $hauteur, array_fill(0, $largeur, 'm'));
        $salles = [];
        $portes = [];

        // Portes spéciales (verrouillée / secrète) éventuellement posées par le
        // gabarit (doc 14 §3.1/3.3). Absent → les portes restent simplement
        // `fermee` : ouvrables à la main par un héros adjacent (E2).
        $portesSpec = (array) data_get($structure, 'portes', []);

        // --- Pose des salles en chaîne ----------------------------------
        $x = 0;
        foreach ($tuiles as $i => $tuile) {
            $w = (int) $tuile->grille['largeur'];
            $h = (int) $tuile->grille['hauteur'];
            $y = $ligneMediane - intdiv($h, 2);

            foreach ($tuile->grille['cases'] as $r => $ligne) {
                foreach ($ligne as $c => $case) {
                    // Les portes « possibles » de la tuile sont refermées :
                    // les portes réelles sont percées sur les couloirs.
                    $cases[$y + $r][$x + $c] = $case === 'p' ? 'm' : $case;
                }
            }

            $salles[] = ['x' => $x, 'y' => $y, 'largeur' => $w, 'hauteur' => $h, 'theme' => $tuile->theme];

            // Couloirs à LARGEUR_COULOIR cases de large (F) : chaque rangée du
            // couloir perce sa propre porte de part et d'autre.
            $rangees = $this->rangeesCouloir($ligneMediane);

            if ($i > 0) {
                foreach ($rangees as $ry) {
                    $cases[$ry][$x] = 'p'; // porte ouest
                    $portes[] = ['x' => $x, 'y' => $ry, 'etat' => MoteurPortes::ETAT_FERMEE];
                }
            }

            if ($i < count($tuiles) - 1) {
                // Portes est = entrée du couloir n°$i. Le gabarit peut les
                // verrouiller / les rendre secrètes : la spec s'applique à
                // TOUTES les rangées, sinon le couloir resterait franchissable
                // par la rangée voisine.
                $spec = $this->specPorte($portesSpec, $i);

                foreach ($rangees as $ry) {
                    $porte = ['x' => $x + $w - 1, 'y' => $ry, 'etat' => MoteurPortes::ETAT_FERMEE];

                    if ($spec !== null) {
                        $porte['etat'] = (string) $spec['etat'];
                        if (isset($spec['verrou'])) {
                            $porte['verrou'] = $spec['verrou'];
                        }
                        // Une secrète est invisible : la case redevient un mur
                        // jusqu'à sa découverte (l'overlay Grille la rouvre ensuite).
                        if ($porte['etat'] === 'secrete') {
                            $porte['revele'] = false;
                            $cases[$ry][$x + $w - 1] = 'm';
                        }
                    }

                    if ($porte['etat'] !== 'secrete') {
                        $cases[$ry][$x + $w - 1] = 'p'; // porte est visible
                    }
                    $portes[] = $porte;
                }

                for ($cx = $x + $w; $cx < $x + $w + self::LONGUEUR_COULOIR; $cx++) {
                    foreach ($rangees as $ry) {
                        $cases[$ry][$cx] = 's'; // couloir creusé
                    }
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
            // Leviers d'ouverture (doc 14 §3.3) : éléments {x, y, levier_id} posés
            // au contact desquels l'action « Actionner le levier » ouvre la porte
            // liée (verrou.levier_id). Vide par défaut ; le gabarit/contenu les
            // déclare via structure.leviers (positions explicites).
            'leviers' => $this->placerLeviers($structure),
            'pieges' => $this->placerPieges($structure, $salles, $ligneMediane),
            'spawn_heros' => array_slice($this->interieur($cases, $salles[0]), 0, self::MAX_SPAWNS_HEROS),
            'spawn_monstres' => $this->spawnsMonstres($cases, $salles),
        ];
    }

    /**
     * Rangées (y) occupées par un couloir, de haut en bas : la ligne médiane et
     * les LARGEUR_COULOIR-1 rangées AU-DESSUS. Le choix du « au-dessus » n'est
     * pas arbitraire : toutes les tuiles seedées ont du sol à l'intérieur sur
     * ces rangées, alors que la rangée SOUS la médiane est un mur plein sur la
     * petite salle 4×4 (une porte y ouvrirait sur un mur).
     *
     * @return list<int>
     */
    private function rangeesCouloir(int $ligneMediane): array
    {
        $rangees = [];

        for ($k = self::LARGEUR_COULOIR - 1; $k >= 0; $k--) {
            $rangees[] = $ligneMediane - $k;
        }

        return $rangees;
    }

    /**
     * Spécification de porte spéciale pour le couloir n°$couloir (doc 14 §3.3) :
     * première entrée de structure.portes ciblant ce couloir avec un `etat`.
     *
     * @param  list<array{couloir?: int, etat?: string, verrou?: array<string, mixed>}>  $specs
     * @return array{etat: string, verrou?: array<string, mixed>}|null
     */
    private function specPorte(array $specs, int $couloir): ?array
    {
        foreach ($specs as $spec) {
            if ((int) ($spec['couloir'] ?? -1) === $couloir && isset($spec['etat'])) {
                /** @var array{etat: string, verrou?: array<string, mixed>} $spec */
                return $spec;
            }
        }

        return null;
    }

    /**
     * Leviers déclarés par le gabarit (positions explicites) — doc 14 §3.3.
     *
     * @param  array<string, mixed>  $structure
     * @return list<array{x: int, y: int, levier_id: string}>
     */
    private function placerLeviers(array $structure): array
    {
        $leviers = [];

        foreach ((array) data_get($structure, 'leviers', []) as $levier) {
            if (isset($levier['x'], $levier['y'], $levier['levier_id'])) {
                $leviers[] = [
                    'x' => (int) $levier['x'],
                    'y' => (int) $levier['y'],
                    'levier_id' => (string) $levier['levier_id'],
                ];
            }
        }

        return $leviers;
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return list<Tuile>
     */
    private function choisirTuiles(array $structure, int $graine): array
    {
        // PRNG local DÉTERMINISTE amorcé par la graine (dérivée du groupe + de la
        // position de quête côté DemarreurQuete) : deux campagnes différentes —
        // ou deux quêtes — obtiennent des cartes différentes (fini « toujours la
        // même carte au départ »), tout en restant reproductible pour une même
        // quête et SANS toucher à la file de dés du jeu (map snapshottée). Graine
        // 0 (défaut) = tirage fixe, pour des tests déterministes.
        $etat = $graine & 0x7fffffff;
        $suivant = function () use (&$etat): int {
            $etat = ($etat * 1103515245 + 12345) & 0x7fffffff;

            return $etat;
        };

        // Nombre de salles TIRÉ dans [min, max] du gabarit (au lieu du min figé).
        $min = max(self::NB_SALLES_MIN, (int) data_get($structure, 'salles.min', 3));
        $max = max($min, (int) data_get($structure, 'salles.max', $min));
        $nbSalles = $min + ($max > $min ? $suivant() % ($max - $min + 1) : 0);

        $generiques = Tuile::query()
            ->where('type', 'salle')
            ->where('theme', 'generique')
            ->orderBy('id')
            ->get()
            ->all();

        if ($generiques === []) {
            throw new RuntimeException('Aucune tuile « salle » en base — seeder les tuiles avant d\'assembler une carte.');
        }

        // Mélange déterministe (Fisher-Yates avec le PRNG local) → l'ordre et le
        // choix des salles varient d'une quête à l'autre.
        for ($i = count($generiques) - 1; $i > 0; $i--) {
            $j = $suivant() % ($i + 1);
            [$generiques[$i], $generiques[$j]] = [$generiques[$j], $generiques[$i]];
        }

        $tuiles = [];
        for ($i = 0; $i < $nbSalles; $i++) {
            $tuiles[] = $generiques[$i % count($generiques)];
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
