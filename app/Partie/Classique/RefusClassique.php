<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Groupe;
use App\Partie\Arene\EtapesCampagne;

/**
 * MODE CLASSIQUE : une erreur NOMMÉE, jamais un passage en silence (`docs/plan-mode-classique.md` §7). Levée quand un
 * groupe classique atteint une étape de campagne qui lui est fermée — un travail d'IA, une montée de niveau, une
 * clôture — ou quand un chemin du mode classique reçoit un groupe qui n'en est pas un.
 */
final class RefusClassique extends \DomainException
{
    public static function etapeFermee(Groupe $groupe, string $etape): self
    {
        $libelle = EtapesCampagne::ETAPES[$etape]['libelle'] ?? $etape;
        $raison = EtapesCampagne::CLASSIQUE[$etape] ?? 'cette étape n\'a pas dit ce qu\'elle fait à un groupe classique';

        return new self("Le groupe « {$groupe->nom} » (#{$groupe->id}) joue en mode classique : l'étape « {$libelle} » lui est fermée ({$raison}).");
    }

    public static function pasUnGroupeClassique(Groupe $groupe): self
    {
        return new self("Le groupe « {$groupe->nom} » (#{$groupe->id}) ne joue pas en mode classique : ce chemin ne démarre que les quêtes d'une campagne construite.");
    }

    public static function sansCampagne(Groupe $groupe): self
    {
        return new self("Le groupe « {$groupe->nom} » (#{$groupe->id}) n'a pas de campagne construite : rien à démarrer.");
    }

    public static function campagneTerminee(Groupe $groupe): self
    {
        return new self("Le groupe « {$groupe->nom} » (#{$groupe->id}) a joué toutes les quêtes de sa campagne.");
    }
}
