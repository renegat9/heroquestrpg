<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Issue d'un jet de compétence (doc 01 §3, décision P4).
 *
 * P4 (« réussite mixte ») : le moteur DÉTECTE le quasi-échec et l'expose
 * comme ReussiteMixte. ⚠ Tranché le 2026-10-19 (René) : un quasi-échec est un
 * ÉCHEC, comme au plateau — aucun « succès à coût ». L'issue ne sert qu'à le
 * NOMMER (fil, narration « raté de peu ») ; `ResultatJet::estReussi()` est
 * faux pour elle et tout résolveur la traite en échec.
 */
enum IssueJet: string
{
    case Reussite = 'reussite';
    case ReussiteMixte = 'reussite_mixte';
    case Echec = 'echec';
}
