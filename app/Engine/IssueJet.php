<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Issue d'un jet de compétence (doc 01 §3, décision P4).
 *
 * P4 (« réussite mixte ») : un quasi-échec peut donner un « succès à coût »
 * ou un échec sec selon le contexte — l'arbitrage narratif revient au MJ IA,
 * mais c'est le moteur qui DÉTECTE le quasi-échec et l'expose comme
 * ReussiteMixte. L'IA ne décide jamais des chiffres.
 */
enum IssueJet: string
{
    case Reussite = 'reussite';
    case ReussiteMixte = 'reussite_mixte';
    case Echec = 'echec';
}
