<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Carte;
use App\Models\Terrain;

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
}
