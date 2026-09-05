<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;

/**
 * Quelles positions de l'arc portent un OBJECTIF MAJEUR — le troisième
 * déclencheur de montée de niveau (doc 01 §5, porté le 2026-09-04 sur
 * l'arbitrage de René).
 *
 * Le document en déclarait trois depuis toujours : « chaque sous-boss vaincu,
 * le boss final, **certains objectifs de quête majeurs marqués par le
 * gabarit** ». `MonteeNiveau::JALONS` n'en lisait que deux. Le troisième
 * n'existait que sur le papier, et la cadence réelle tombait très en dessous
 * des « ~5 à 8 niveaux par campagne » que la même section vise :
 *
 *   très courte (1 quête)   0 sous-boss + boss  →  1 niveau
 *   courte      (3-5)       1 + boss            →  2 niveaux
 *   normale     (7-10)      2 + boss            →  3 niveaux
 *   longue      (12-15)     3 + boss            →  4 niveaux
 *   très longue (17-20)     4 + boss            →  5 niveaux
 *
 * Aucune longueur n'atteignait la fourchette, alors que la grille de talents
 * est dimensionnée dessus : « neuf cases pour quatre à sept points » (doc 01
 * §6). À deux points, on n'atteint pas même le rang 3 d'une seule colonne —
 * le choix qui devait faire mal ne se posait pas.
 *
 * ⚠ LA MARQUE ET LA CADENCE SONT DEUX MOITIÉS DISTINCTES, et il faut les
 * deux. Le **gabarit** dit quels objectifs sont majeurs (`structure
 * .objectif_majeur`) — c'est le « marqués par le gabarit » du document. L'ARC
 * dit lesquelles de ces quêtes comptent : sans cette seconde moitié, une
 * campagne très longue offrirait un niveau à chacune de ses quinze quêtes
 * ordinaires, soit **vingt niveaux**, très au-delà de la fourchette que le
 * même paragraphe fixe. Le document le demande d'ailleurs mot pour mot : « la
 * cadence exacte se cale sur l'arc selon la longueur ».
 *
 * ⚠ C'est un PLACEMENT, pas un tirage : le calcul ne dépend que du plan de
 * campagne et du nombre de quêtes, tous deux figés à la création. Recommencer
 * une quête ou recharger un instantané ne déplace donc jamais un objectif
 * majeur — la distinction que le projet fait déjà entre `salle_artefact` et le
 * deck de fouille.
 */
final class CadenceNiveaux
{
    /**
     * Haut de la fourchette visée par la doc 01 §5 (« ~5 à 8 niveaux par
     * campagne »). On plafonne au HAUT plutôt qu'au bas : les positions
     * majeures ne sont distribuées que sur les quêtes ordinaires, et une
     * campagne courte n'en a pas assez pour atteindre le plafond de toute
     * façon — c'est la campagne LONGUE que ce nombre borne.
     */
    public const NIVEAUX_VISES = 8;

    /*
     * ⚠ CE QUE LA CADENCE PROMET, exactement — et ce qu'elle ne promet pas.
     *
     * Dès qu'un arc a la place (≥ 5 quêtes), le total tombe dans la fourchette
     * de la doc : 5 quêtes → 5 niveaux, 20 quêtes → 8. En deçà, la fourchette
     * n'est pas atteignable — une campagne de trois quêtes ne peut pas rendre
     * cinq niveaux — et **chaque quête monte alors d'un niveau**, ce qui est le
     * maximum qu'on puisse donner sans en offrir deux pour une même quête.
     * C'est énoncé plutôt que maquillé : une « très courte » est une démo d'une
     * seule quête, elle vaut un niveau.
     */

    /**
     * Les positions d'arc de ce groupe qui portent un objectif majeur.
     *
     * @return list<int>
     */
    public function positionsMajeures(Groupe $groupe): array
    {
        $total = max(0, (int) $groupe->nb_quetes_total);
        $jalons = $this->positionsJalons($groupe, $total);

        $candidates = [];

        for ($position = 1; $position <= $total; $position++) {
            if (! in_array($position, $jalons, true)) {
                $candidates[] = $position;
            }
        }

        $budget = max(0, self::NIVEAUX_VISES - count($jalons));
        $nombre = count($candidates);

        if ($budget === 0 || $nombre === 0) {
            return [];
        }

        if ($budget >= $nombre) {
            return $candidates;
        }

        // Répartition RÉGULIÈRE sur les quêtes ordinaires, centrée sur chaque
        // tranche : les regrouper en début d'arc donnerait une campagne qui
        // progresse vite puis plus du tout.
        $choisies = [];

        for ($i = 0; $i < $budget; $i++) {
            $index = (int) floor(($i + 0.5) * $nombre / $budget);
            $choisies[min($index, $nombre - 1)] = true;
        }

        return array_values(array_map(
            fn (int $index) => $candidates[$index],
            array_keys($choisies),
        ));
    }

    /** Cette position porte-t-elle un objectif majeur ? */
    public function estMajeure(Groupe $groupe, int $position): bool
    {
        return in_array($position, $this->positionsMajeures($groupe), true);
    }

    /**
     * Positions déjà occupées par un jalon de `MonteeNiveau` — elles montent
     * déjà d'un niveau, et une quête ne doit jamais en donner deux.
     *
     * ⚠ Même lecture que `DemarreurQuete::typeJalon()`, y compris son repli :
     * sans plan de campagne, seule la dernière quête est un jalon. Les deux
     * doivent répondre pareil, sinon une position compterait comme jalon ici
     * et comme quête ordinaire là.
     *
     * @return list<int>
     */
    private function positionsJalons(Groupe $groupe, int $total): array
    {
        $positions = [];

        foreach ((array) data_get($groupe->plan_campagne, 'jalons', []) as $jalon) {
            if (in_array($jalon['type'] ?? null, MonteeNiveau::JALONS, true)) {
                $position = (int) ($jalon['position'] ?? 0);

                if ($position >= 1 && $position <= $total) {
                    $positions[] = $position;
                }
            }
        }

        if ($positions === [] && $total >= 1) {
            $positions[] = $total;
        }

        return array_values(array_unique($positions));
    }
}
