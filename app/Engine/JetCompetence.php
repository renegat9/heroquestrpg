<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\LanceurDes;

/**
 * Jet de compétence Body ou Mind (doc 01 §3, décision P4).
 *
 * Le personnage lance un nombre de dés de combat égal à son attribut
 * (Body ou Mind) ; chaque CRÂNE vaut 1 succès. Le MJ fixe une difficulté
 * = nombre de succès requis (1 facile … 4+ très difficile).
 *
 * Issues (P4 — réussite mixte) :
 * - succès ≥ difficulté                → Réussite.
 * - succès = difficulté − 1 ET ≥ 1     → Réussite mixte (« quasi-échec ») :
 *   le MJ IA arbitre ensuite entre succès à coût et échec sec, mais c'est le
 *   moteur qui détecte l'état.
 * - sinon                              → Échec.
 *
 * Interprétations actées ici :
 * - le quasi-échec exige AU MOINS 1 succès : rater totalement un jet de
 *   difficulté 1 (0 crâne) est un échec sec, pas un « succès à coût » ;
 * - 0 dé lancé (attribut 0) = 0 succès = échec automatique, cohérent avec la
 *   note du doc 09 §2 (« 0 dé = 0 succès »).
 *
 * Couvre aussi les jets de parchemin des non-lanceurs (doc 02 §6, S1) :
 * jet de Mind, difficulté 1 à 3 selon le sort.
 */
final class JetCompetence
{
    public function __construct(private readonly LanceurDes $des)
    {
    }

    public function resoudre(int $nbDes, int $difficulte): ResultatJet
    {
        if ($nbDes < 0) {
            throw new \InvalidArgumentException("Nombre de dés invalide : {$nbDes}.");
        }
        if ($difficulte < 1) {
            throw new \InvalidArgumentException(
                "Difficulté invalide : {$difficulte} (minimum 1 succès requis)."
            );
        }

        $faces = $this->des->desCombat($nbDes);
        $succes = count(array_filter($faces, fn ($face) => $face->estCrane()));

        $issue = match (true) {
            $succes >= $difficulte => IssueJet::Reussite,
            $succes === $difficulte - 1 && $succes >= 1 => IssueJet::ReussiteMixte,
            default => IssueJet::Echec,
        };

        return new ResultatJet($faces, $succes, $difficulte, $issue);
    }
}
