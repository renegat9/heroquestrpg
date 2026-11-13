<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\MotsClesTerrain;
use App\Models\Carte;
use App\Models\Terrain;
use RuntimeException;

/**
 * Les règles du TERRAIN qui ne sont ni un coût ni un dégât de franchissement
 * (ceux-là vivent dans `FabriqueGrille`/`Grille` et dans les tronqueurs de
 * `ResolveurTour`) : aujourd'hui, la MARE de *Jungles of Delthrak*.
 *
 * MARE (Pool of Water, livret F9907 p. 4) : « If a hero searches for treasure in
 * an area containing a pool of water, they may choose to restore 1 lost Body
 * Point instead of drawing from the treasure deck. » C'est une règle de la
 * FOUILLE, pas du pas : `terrains.effet.soin_a_la_fouille` (PV rendus) est lu
 * ICI, en un seul point, que le menu (qui OFFRE le choix) et le résolveur (qui
 * rend le point) relisent — « une règle, un point de passage ».
 */
final class MoteurTerrain
{
    /** PV de Body qu'une Mare rend à la fouille (`soin_a_la_fouille`), 0 si le terrain n'en porte pas. */
    public static function soinALaFouille(?array $effet): int
    {
        return max(0, (int) ($effet['soin_a_la_fouille'] ?? 0));
    }

    /**
     * La Mare qui se trouve dans la SALLE d'index `$salle` (« an area
     * containing a pool of water »), ou `null`. Une Mare de couloir n'existe
     * pas (le placement ne pose de terrain qu'en salle) ; si une carte en
     * portait une, elle ne servirait à aucune fouille — une fouille de trésor
     * n'a lieu qu'en salle.
     *
     * @return array{x: int, y: int, nom: string, soin: int}|null
     */
    public function mareDeLaSalle(Carte $carte, int $salle): ?array
    {
        $entrees = (array) ($carte->grille['terrain'] ?? []);

        if ($entrees === []) {
            return null;
        }

        $catalogue = Terrain::query()
            ->whereIn('id', array_values(array_unique(array_column($entrees, 'terrain_id'))))
            ->get(['id', 'nom', 'effet'])
            ->keyBy('id');

        $salles = (array) ($carte->grille['salles'] ?? []);

        foreach ($entrees as $entree) {
            $type = $catalogue->get($entree['terrain_id'] ?? null);
            $soin = $type === null ? 0 : self::soinALaFouille((array) $type->effet);

            if ($soin > 0 && Salles::indexDe($salles, (int) $entree['x'], (int) $entree['y']) === $salle) {
                return ['x' => (int) $entree['x'], 'y' => (int) $entree['y'], 'nom' => (string) $type->nom, 'soin' => $soin];
            }
        }

        return null;
    }

    /**
     * POSER UN PONT DE GLACE en pleine quête (*Ice Bridge*, lot 4 du plan parchemins-glace, 2026-11-13) — LE SEUL point
     * d'écriture de `grille['terrain']` après l'assemblage. Le pont couvre les `$cases` (un élément : une fosse, une
     * fosse longue, une plaque) :
     *
     *  - chaque case reçoit une entrée de terrain `{x, y, terrain_id: <Glace magique>, groupe_id: "pont-N"}`, qui REMPLACE
     *    celle qui s'y trouvait ; l'ancien terrain est gardé en `sous_pont` (jamais relu par les règles) ;
     *  - chaque piège ARMÉ d'une de ces cases passe à `ETAT_SOUS_PONT` (`ETATS_ARMES` : caché, détecté, fosse ouverte,
     *    mare) — il ne se déclenche plus, ne se saute plus, ne se désamorce plus ;
     *  - `N` est unique dans la carte : un pont de plus, un numéro de plus.
     *
     * Permanent : le pont vit dans `cartes.grille`, donc il survit à une reprise. La sauvegarde est faite ici.
     *
     * Le menu et la résolution ont déjà jugé l'élément (`MoteurSorts::elementsPontables()`) : ce point de passage ne
     * revérifie pas, il écrit ce qu'il reçoit — mais il refuse une tuile qui n'est pas un pont.
     *
     * @param  list<array{x: int, y: int}>  $cases
     * @return array{groupe_id: string, cases: list<array{x: int, y: int}>, pieges: int}
     */
    public function poserPont(Carte $carte, array $cases, string $nomTuile): array
    {
        $tuile = Terrain::query()->where('nom', $nomTuile)->first();

        if ($tuile === null || ! MotsClesTerrain::connue('pont') || empty(data_get($tuile->effet, 'pont'))) {
            throw new RuntimeException("Pont : « {$nomTuile} » n'est pas une tuile de pont au catalogue.");
        }

        if ($cases === []) {
            throw new RuntimeException('Un pont couvre au moins une case.');
        }

        $grille = (array) $carte->grille;
        $terrains = (array) ($grille['terrain'] ?? []);

        $numeros = [0];
        foreach ($terrains as $entree) {
            if (preg_match('/^pont-(\d+)$/', (string) ($entree['groupe_id'] ?? ''), $m) === 1) {
                $numeros[] = (int) $m[1];
            }
        }
        $groupe = 'pont-'.(max($numeros) + 1);

        $index = [];
        foreach ($terrains as $i => $entree) {
            $index[((int) $entree['x']).','.((int) $entree['y'])] = $i;
        }

        foreach ($cases as $case) {
            $cle = ((int) $case['x']).','.((int) $case['y']);
            $entree = ['x' => (int) $case['x'], 'y' => (int) $case['y'], 'terrain_id' => (int) $tuile->id, 'groupe_id' => $groupe];

            if (isset($index[$cle])) {
                $ancien = $terrains[$index[$cle]];

                if (isset($ancien['terrain_id'])) {
                    $entree['sous_pont'] = (int) $ancien['terrain_id'];
                }

                $terrains[$index[$cle]] = $entree;
            } else {
                $terrains[] = $entree;
                $index[$cle] = array_key_last($terrains);
            }
        }

        $cleCases = [];
        foreach ($cases as $case) {
            $cleCases[((int) $case['x']).','.((int) $case['y'])] = true;
        }

        $pieges = 0;
        foreach ((array) ($grille['pieges'] ?? []) as $i => $piege) {
            if (isset($cleCases[((int) $piege['x']).','.((int) $piege['y'])])
                && in_array($piege['etat'] ?? null, MoteurPieges::ETATS_ARMES, true)) {
                $grille['pieges'][$i]['etat'] = MoteurPieges::ETAT_SOUS_PONT;
                $pieges++;
            }
        }

        $grille['terrain'] = array_values($terrains);
        $carte->update(['grille' => $grille]);

        return [
            'groupe_id' => $groupe,
            'cases' => array_map(fn (array $c) => ['x' => (int) $c['x'], 'y' => (int) $c['y']], $cases),
            'pieges' => $pieges,
        ];
    }
}
