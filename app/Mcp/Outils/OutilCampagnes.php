<?php

declare(strict_types=1);

namespace App\Mcp\Outils;

use App\Partie\Classique\CampagnesConstruites;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('campagnes')]
#[Title('Campagnes construites')]
#[Description(
    'Liste les campagnes construites enregistrées, en résumés (slug, titre, auteur, mention, nombre de quêtes, empreinte). '
    .'Aucune quête n\'est détaillée : pour le contenu d\'une campagne, appeler campagne_lire.'
)]
#[IsReadOnly]
final class OutilCampagnes extends Tool
{
    public function handle(CampagnesConstruites $campagnes): Response|ResponseFactory
    {
        return Response::structured(['campagnes' => $campagnes->lister()]);
    }
}
