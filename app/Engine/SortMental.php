<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\LanceurDes;

/**
 * Résolution d'un sort mental (sommeil, peur, contrôle…) — décision S2.
 *
 * Effet BINAIRE : la cible tente un jet de Mind pour résister ; échec = elle
 * subit l'effet. Pas de dégâts de PV de Mind au MVP. Pas de réussite mixte
 * ici (P4 concerne les jets de compétence arbitrés par le MJ IA, pas la
 * résistance binaire).
 *
 * Le jet de résistance lance autant de dés de combat que le Mind de la cible
 * (attribut Mind pour un héros, PV de Mind pour un monstre — doc 09 §2) ;
 * chaque crâne = 1 succès.
 *
 * Règle clé Mind 0 (doc 09 §2, morts-vivants) : une cible à Mind 0 est
 * IMMUNISÉE aux sorts mentaux — sans cette règle, « 0 dé = 0 succès » en
 * ferait à tort la cible la plus facile à contrôler. Aucun dé n'est lancé.
 *
 * Interprétation actée ici : les docs ne chiffrent pas la difficulté du jet
 * de résistance ; on retient 1 succès par défaut, paramétrable par sort.
 *
 * S2 s'applique dans les deux sens : sorts des héros (Sommeil, Tempête) sur
 * les monstres, et sorts de Dread sur les héros (le Mind du héros sert de
 * défense, doc 09 §4).
 */
final class SortMental
{
    public function __construct(private readonly LanceurDes $des)
    {
    }

    /**
     * @param int $mindCible  dés de résistance de la cible (attribut Mind héros / PV Mind monstre)
     * @param int $difficulte succès requis pour résister (défaut : 1)
     */
    public function resoudre(int $mindCible, int $difficulte = 1): ResultatSortMental
    {
        if ($mindCible < 0) {
            throw new \InvalidArgumentException("Mind de la cible invalide : {$mindCible}.");
        }
        if ($difficulte < 1) {
            throw new \InvalidArgumentException(
                "Difficulté invalide : {$difficulte} (minimum 1 succès requis)."
            );
        }

        if ($mindCible === 0) {
            return new ResultatSortMental(
                issue: IssueSortMental::Immunise,
                faces: [],
                succes: 0,
                difficulte: $difficulte,
            );
        }

        $faces = $this->des->desCombat($mindCible);
        $succes = count(array_filter($faces, fn ($face) => $face->estCrane()));

        return new ResultatSortMental(
            issue: $succes >= $difficulte ? IssueSortMental::Resiste : IssueSortMental::SubitEffet,
            faces: $faces,
            succes: $succes,
            difficulte: $difficulte,
        );
    }
}
